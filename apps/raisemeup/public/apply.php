<?php
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/UserRepository.php';
require_once __DIR__ . '/../src/FamilyAccountRepository.php';
require_once __DIR__ . '/../src/LineLoginClient.php';
require_once __DIR__ . '/../src/PlanRepository.php';
require_once __DIR__ . '/../src/SubscriptionRepository.php';
require_once __DIR__ . '/../src/StripeClient.php';
require_once __DIR__ . '/../src/Layout.php';
require_once __DIR__ . '/../src/ApplyDoneView.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');
session_start();

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
$planRepo = new PlanRepository($pdo);

if (empty($_SESSION['apply_csrf_token'])) {
    $_SESSION['apply_csrf_token'] = bin2hex(random_bytes(32));
}

// --- 申込完了画面(POST→Stripe Checkout→リダイレクト後のGET) ---
// LINEログインでのステップ連携(①利用者→②ご家族)を挟んで何度も再表示されるため、
// フォーム再送信防止用のcsrf_tokenとは違いここではセッションから消さずに残す
if (isset($_GET['done']) && !empty($_SESSION['apply_result'])) {
    renderDone((int) $_SESSION['apply_result']['user_id'], (int) $_SESSION['apply_result']['family_id'], $pdo, false, (string) ($_GET['line_error'] ?? ''));
    exit;
}

// Stripe Checkoutを利用者が途中でキャンセルした場合。トライアル自体は既に作成済みなので、
// 支払い未登録である旨だけ伝えて通常のトライアル案内を表示する(サービス利用は妨げない)
if (isset($_GET['cancelled']) && !empty($_SESSION['apply_result'])) {
    renderDone((int) $_SESSION['apply_result']['user_id'], (int) $_SESSION['apply_result']['family_id'], $pdo, true, (string) ($_GET['line_error'] ?? ''));
    exit;
}

$errors = [];
$formValues = [
    'family_last_name' => '', 'family_first_name' => '', 'family_email' => '', 'family_phone' => '',
    'user_last_name' => '', 'user_first_name' => '', 'user_phone' => '', 'user_zip' => '', 'user_address' => '',
    'user_birthdate' => '', 'user_gender' => '', 'relation' => '', 'plan_id' => '', 'companion_gender' => 'random',
];

// トップページの料金プランカードから「このプランで申し込む」で来た場合、該当プランを選択済みにしておく
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['plan_id'])) {
    $formValues['plan_id'] = trim((string) $_GET['plan_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ハニーポット: 人間には見えない欄が埋まっていればボット判定し、DB操作はせず成功したふりをして終了する
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        header('Location: /apply/');
        exit;
    }

    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['apply_csrf_token'], $token)) {
        $errors[] = 'フォームの有効期限が切れました。お手数ですがもう一度入力してください。';
    }

    foreach (array_keys($formValues) as $key) {
        $formValues[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    // 生年月日は年・月・日の3つのプルダウンで入力させ(高齢のご家族が申込む場合、日付型の
    // カレンダーUIだと何十年も遡るのに不便なため)、ここでY-m-d形式に組み立てる
    $birthYear = trim((string) ($_POST['user_birthdate_year'] ?? ''));
    $birthMonth = trim((string) ($_POST['user_birthdate_month'] ?? ''));
    $birthDay = trim((string) ($_POST['user_birthdate_day'] ?? ''));
    if ($birthYear !== '' && $birthMonth !== '' && $birthDay !== '') {
        $formValues['user_birthdate'] = sprintf('%04d-%02d-%02d', (int) $birthYear, (int) $birthMonth, (int) $birthDay);
    } elseif ($birthYear !== '' || $birthMonth !== '' || $birthDay !== '') {
        $formValues['user_birthdate'] = '';
        $errors[] = '生年月日は年・月・日をすべて選択してください。';
    } else {
        $formValues['user_birthdate'] = '';
    }

    $activePlans = $planRepo->getActivePlans();
    // coming_soonなプランは表示はするが、申込みでは選択できないようにする(サービス開始前のため)
    $selectablePlans = array_filter($activePlans, fn($p) => !$p['coming_soon']);
    $activePlanIds = array_map(fn($p) => (string) $p['id'], $selectablePlans);

    if ($formValues['family_last_name'] === '' || $formValues['family_first_name'] === '') {
        $errors[] = 'お申込者(ご家族)様のお名前(姓・名)を入力してください。';
    }
    // ご利用者様のお名前は任意(マイページから後で登録することもできる)だが、
    // 姓・名どちらかだけ入力されている中途半端な状態は防ぐ
    if (($formValues['user_last_name'] !== '') !== ($formValues['user_first_name'] !== '')) {
        $errors[] = 'ご利用者様のお名前は姓・名の両方を入力してください。';
    }
    $duplicateFamily = null;
    $duplicateCanLogin = false;
    if ($formValues['family_email'] !== '' && !filter_var($formValues['family_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'メールアドレスの形式が正しくありません。';
    } elseif ($formValues['family_email'] !== '') {
        // family_accounts.emailはUNIQUEなので、二重送信(戻る操作での再送信等)や同一メールでの再申込みを
        // ここで検知し、DB例外による汎用エラーではなく専用の案内を出す
        $duplicateFamily = (new FamilyAccountRepository($pdo))->findByEmail($formValues['family_email']);

        // 本人・ご家族どちらのLINE連携も必須のため、どちらか一方でも未連携かつ支払いも一切発生して
        // いない申込みは、実質何も使われていないので check_subscriptions.php の ABANDON_TIMEOUT_DAYS
        // (3日)を待たず、再申込みの時点で即座に放置扱いにしてemailを解放する。既に支払い情報が
        // 紐づいている場合は誤って解約してしまうと危険なので対象外とし、
        // 「ログイン案内」または「心当たりがなければsupport@へ」の案内に振り分ける
        if ($duplicateFamily !== null) {
            $linkedUserStmt = $pdo->prepare(
                'SELECT u.id, u.status FROM user_family_links l
                 JOIN users u ON u.id = l.user_id
                 WHERE l.family_account_id = ? ORDER BY l.id DESC LIMIT 1'
            );
            $linkedUserStmt->execute([$duplicateFamily['id']]);
            $linkedUser = $linkedUserStmt->fetch(PDO::FETCH_ASSOC);

            $latestSubStmt = $pdo->prepare(
                'SELECT id, payment_customer_ref FROM subscriptions WHERE family_account_id = ? ORDER BY id DESC LIMIT 1'
            );
            $latestSubStmt->execute([$duplicateFamily['id']]);
            $latestSub = $latestSubStmt->fetch(PDO::FETCH_ASSOC);

            // ご家族側は「LINEログイン+友だち追加」までが必須のため、line_user_idの有無だけでなく
            // friend_confirmed_atも見て判定する(check_subscriptions.phpの放置判定と同じ基準)
            $familyIncomplete = $duplicateFamily['line_user_id'] === null || $duplicateFamily['friend_confirmed_at'] === null;
            $isReclaimable = $linkedUser !== false
                && ($linkedUser['status'] === 'pending' || $familyIncomplete)
                && (!$latestSub || empty($latestSub['payment_customer_ref']));

            if ($isReclaimable) {
                if ($latestSub) {
                    (new SubscriptionRepository($pdo))->markAbandoned((int) $latestSub['id']);
                }
                (new FamilyAccountRepository($pdo))->clearEmail((int) $duplicateFamily['id']);
                $duplicateFamily = null;
            } else {
                // マイページログインは家族(お申込者)自身のline_user_idで本人確認するため、
                // それが設定済み(=ログイン可能)かつ支払いも完了しているなら、迷わせず
                // 「申込み」ではなく「ログイン」に誘導する
                $duplicateCanLogin = $duplicateFamily['line_user_id'] !== null
                    && !empty($latestSub['payment_customer_ref'] ?? null);
            }
        }
    }
    // ハイフンありなし両方許容し、DBには数字7桁のみを保存する
    $formValues['user_zip'] = preg_replace('/[^0-9]/', '', $formValues['user_zip']);
    if ($formValues['user_zip'] !== '' && strlen($formValues['user_zip']) !== 7) {
        $errors[] = '郵便番号は7桁の数字で入力してください。';
    }
    if ($formValues['user_birthdate'] !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $formValues['user_birthdate']);
        if (!$d || $d->format('Y-m-d') !== $formValues['user_birthdate'] || $d > new DateTime('now', new DateTimeZone('Asia/Tokyo'))) {
            $errors[] = '生年月日の形式が正しくありません。';
        }
    }
    if (!in_array($formValues['plan_id'], $activePlanIds, true)) {
        $errors[] = 'プランを選択してください。';
    }
    if (!in_array($formValues['companion_gender'], ['male', 'female', 'random'], true)) {
        $errors[] = '話し相手の性別を選択してください。';
    }
    if ($formValues['user_gender'] !== '' && !in_array($formValues['user_gender'], ['male', 'female'], true)) {
        $errors[] = 'ご利用者様の性別の指定が正しくありません。';
    }

    if (empty($errors) && $duplicateFamily === null) {
        $selectedPlan = $planRepo->find((int) $formValues['plan_id']);

        try {
            $pdo->beginTransaction();

            $familyRepo = new FamilyAccountRepository($pdo);
            $userRepo = new UserRepository($pdo);
            $subscriptionRepo = new SubscriptionRepository($pdo);

            $familyFullName = trim($formValues['family_last_name'] . ' ' . $formValues['family_first_name']);
            $userFullName = trim($formValues['user_last_name'] . ' ' . $formValues['user_first_name']);

            $family = $familyRepo->create([
                'name' => $familyFullName,
                'email' => $formValues['family_email'],
                'phone' => $formValues['family_phone'],
            ]);

            // 呼び名は申込み時には聞かず、TAYORIとの会話の中で自然に確認して
            // ConversationHandlerが後から設定する(companion_nameと同じ方針)。
            // 氏名(full_name)はご家族が代理入力する管理用の名前で、呼び名とは別物
            $user = $userRepo->createPending([
                'full_name' => $userFullName,
                'display_name' => null,
                'phone' => $formValues['user_phone'],
                'postal_code' => $formValues['user_zip'],
                'address' => $formValues['user_address'],
                'birthdate' => $formValues['user_birthdate'],
                'gender' => $formValues['user_gender'],
                'companion_gender' => $formValues['companion_gender'],
            ]);

            $pdo->prepare(
                "INSERT INTO user_family_links (user_id, family_account_id, relation, role, notify_priority, is_active)
                 VALUES (?, ?, ?, 'payer', 1, 1)"
            )->execute([$user['id'], $family['id'], $formValues['relation'] ?: null]);

            $subscriptionId = $subscriptionRepo->createTrial($user['id'], $family['id'], (int) $selectedPlan['id'], TRIAL_DAYS);

            $pdo->commit();

            // 呼び名は自動生成しない。デフォルトは「たより」のままにしておき、会話の中で本人から
            // 「〇〇って呼びたい」等の希望があった場合だけConversationHandlerがcompanion_nameを更新する

            // 表示に必要な情報(プラン名・トライアル終了日等)はrenderDone側で毎回DBから取得するので、
            // ここでは「このブラウザがどの申込みを行ったか」を示すIDだけを持たせる
            $_SESSION['apply_result'] = [
                'user_id' => (int) $user['id'],
                'family_id' => (int) $family['id'],
            ];
            unset($_SESSION['apply_csrf_token']);

            // トライアル本体はDBに確定済みなので、ここから先(Stripe連携)が失敗してもロールバックはしない。
            // カード登録なしのトライアルとしてそのままサービスを継続させる(方針はcheck_subscriptions.phpと同じ)。
            $baseUrl = rtrim(Config::get('APP_BASE_URL', ''), '/');
            if ($baseUrl !== '' && !empty($selectedPlan['stripe_price_id'])) {
                try {
                    $stripe = new StripeClient(Config::get('STRIPE_SECRET_KEY', ''));
                    $customer = $stripe->createCustomer(
                        $formValues['family_email'],
                        $familyFullName,
                        ['family_account_id' => (string) $family['id']]
                    );
                    $familyRepo->setStripeCustomerId((int) $family['id'], $customer['id']);

                    $session = $stripe->createCheckoutSession([
                        'customer' => $customer['id'],
                        'line_items' => [['price' => $selectedPlan['stripe_price_id'], 'quantity' => 1]],
                        'subscription_data' => ['trial_period_days' => TRIAL_DAYS],
                        'client_reference_id' => (string) $subscriptionId,
                        'success_url' => $baseUrl . '/apply/?done=1',
                        'cancel_url' => $baseUrl . '/apply/?cancelled=1',
                    ]);

                    header('Location: ' . $session['url']);
                    exit;
                } catch (Throwable $e) {
                    error_log('Stripe checkout session creation failed: ' . $e->getMessage());
                }
            }

            header('Location: /apply/?done=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('apply.php submission failed: ' . $e->getMessage());
            $errors[] = '申込処理中にエラーが発生しました。お手数ですが時間をおいて再度お試しください。';
        }
    }
}

renderForm($planRepo->getActivePlans(), $errors, $formValues, $_SESSION['apply_csrf_token'], $duplicateFamily ?? null, $duplicateCanLogin ?? false);

function renderForm(array $plans, array $errors, array $v, string $csrfToken, ?array $duplicateFamily = null, bool $duplicateCanLogin = false): void
{
    [$bdYear, $bdMonth, $bdDay] = $v['user_birthdate'] !== '' ? explode('-', $v['user_birthdate']) : ['', '', ''];
    $bdMonth = $bdMonth !== '' ? (string) (int) $bdMonth : '';
    $bdDay = $bdDay !== '' ? (string) (int) $bdDay : '';
    Layout::renderHeader('apply', 'ご利用申込');
    ?>
<style>
  .card { max-width: 680px; margin: 0 auto; background:#fff; border-radius:12px; padding:28px 24px; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
  .card h1 { font-size:1.4rem; margin-top:0; }
  .card h2 { font-size:1.05rem; margin:28px 0 12px; border-left:4px solid #4B8B5A; padding-left:8px; }
  .card label { display:block; font-weight:bold; margin:14px 0 4px; font-size:0.95rem; }
  .card input[type=text], .card input[type=email], .card input[type=tel], .card input[type=date] {
    width:100%; box-sizing:border-box; padding:10px; font-size:1rem; border:1px solid #ccc; border-radius:6px;
  }
  .birthdate-row { display:flex; gap:8px; }
  .birthdate-row select { flex:1; min-width:0; box-sizing:border-box; padding:10px; font-size:1rem; border:1px solid #ccc; border-radius:6px; background:#fff; }
  .name-row { display:flex; gap:8px; }
  .name-row input[type=text] { flex:1; min-width:0; }
  .plan { border:1px solid #ddd; border-radius:8px; padding:12px; margin-bottom:8px; }
  .plan label { display:flex; align-items:baseline; gap:8px; font-weight:normal; margin:0; }
  .plan .price { color:#4B8B5A; font-weight:bold; }
  .plan-soon { border:1px dashed #cfc9bd; background:#faf9f6; color:#888; }
  .plan-soon .name-row { display:flex; align-items:baseline; gap:8px; }
  .plan-soon .badge-soon { display:inline-block; font-size:0.75rem; font-weight:bold; color:#fff; background:#a9915a; border-radius:10px; padding:2px 8px; }
  .hint { font-size:0.85rem; color:#777; margin-top:2px; }
  .errors { background:#fdecea; border:1px solid #f5b0a8; color:#a12a1f; padding:12px 16px; border-radius:8px; margin-bottom:16px; }
  .notice { background:#eef2ea; border:1px solid #cfdbc4; color:#333; padding:12px 16px; border-radius:8px; margin-bottom:16px; line-height:1.6; }
  .notice a { color:#4B8B5A; font-weight:bold; }
  .card button { display:inline-flex; align-items:center; justify-content:center; gap:10px; margin-top:24px; width:100%; padding:14px; font-size:1.05rem; background:#4B8B5A; color:#fff; border:none; border-radius:8px; cursor:pointer; }
  .card button .icon { width:1.35em; height:1.35em; }
  .card button:hover { background:#1E4729; }
  .honeypot { position:absolute; left:-9999px; }
  .apply-steps { display:flex; list-style:none; margin:0 0 24px; padding:0; }
  .apply-step { flex:1; position:relative; display:flex; flex-direction:column; align-items:center; text-align:center; font-size:0.72rem; color:#aaa; }
  .apply-step:not(:last-child)::after { content:''; position:absolute; top:14px; left:calc(50% + 20px); right:calc(-50% + 20px); height:2px; background:#e6e2d8; }
  .apply-step.is-done:not(:last-child)::after { background:#4B8B5A; }
  .apply-step-circle { display:flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:50%; background:#e6e2d8; color:#8a8578; font-weight:bold; font-size:0.85rem; margin-bottom:6px; }
  .apply-step.is-done .apply-step-circle { background:#4B8B5A; color:#fff; }
  .apply-step.is-current .apply-step-circle { background:#fff; color:#4B8B5A; border:2px solid #4B8B5A; }
  .apply-step.is-attention .apply-step-circle { background:#e8a33d; color:#fff; }
  .apply-step.is-done .apply-step-label, .apply-step.is-current .apply-step-label { color:#3D3A35; font-weight:bold; }
  .notice-important { background:#fff; border:2px solid #4B8B5A; border-radius:12px; padding:18px 20px; margin-bottom:16px; }
  .notice-important .notice-title { display:flex; align-items:center; gap:8px; font-weight:bold; color:#1E4729; font-size:1.02rem; margin-bottom:10px; }
  .notice-important .notice-title .icon { width:1.3em; height:1.3em; }
  .notice-important p { margin:0 0 14px; line-height:1.7; }
  .notice-important .highlight { background:#FBE2C4; color:#1E4729; font-weight:bold; padding:1px 5px; border-radius:4px; }
  .notice-steps { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:10px; }
  .notice-steps li { display:flex; align-items:flex-start; gap:10px; font-size:0.92rem; line-height:1.6; }
  .notice-steps .num { flex-shrink:0; display:flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; background:#4B8B5A; color:#fff; font-size:0.78rem; font-weight:bold; margin-top:1px; }
</style>
<div class="card">
  <?php renderStepIndicator([1 => 'current']); ?>
  <h1>TAYORI ご利用申込</h1>
  <p>ご家族様が代わってお申込みください。お申込み後、<?= TRIAL_DAYS ?>日間無料でお試しいただけます。</p>
  <div class="notice-important">
    <div class="notice-title"><?= Layout::icon('shield') ?> ご注意ください</div>
    <p>お申込みは、<span class="highlight">ご家族様ご自身のスマートフォンまたはパソコン</span>で行ってください。</p>
    <ol class="notice-steps">
      <li><span class="num">1</span><span>このページで<strong>ご家族様</strong>がお申込み情報を入力・送信します</span></li>
      <li><span class="num">2</span><span>お申込み後、<strong>ご家族様ご自身の端末</strong>でLINE連携を行います</span></li>
      <li><span class="num">3</span><span>続けて、<strong>ご利用者様(ご本人)</strong>へ連携用のURLをお送りいただきます</span></li>
    </ol>
  </div>

  <?php if ($duplicateFamily !== null): ?>
    <?php $resumable = (int) ($_SESSION['apply_result']['family_id'] ?? 0) === (int) $duplicateFamily['id']; ?>
    <div class="notice">
      <?php if ($resumable): ?>
        このメールアドレスでは、完了していないお申込みがあります。<a href="/apply/?done=1">続きはこちらから</a>お進みください。
      <?php elseif ($duplicateCanLogin): ?>
        このメールアドレスは既にご登録済みです。お手数ですが<a href="/mypage_login_start.php">マイページにログイン</a>してご確認ください。
      <?php else: ?>
        このメールアドレスでは既にお申込みをいただいています。心当たりがない場合や、続きのお手続きについては<a href="mailto:support@tayori-net.jp">support@tayori-net.jp</a>までご連絡ください。
      <?php endif; ?>
    </div>
  <?php elseif (!empty($errors)): ?>
    <div class="errors">
      <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" action="/apply/">
    <input type="text" name="website" class="honeypot" tabindex="-1" autocomplete="off" value="">
    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

    <h2>お申込者様(ご家族)について</h2>
    <label for="family_last_name">お名前</label>
    <div class="name-row">
      <input type="text" id="family_last_name" name="family_last_name" value="<?= h($v['family_last_name']) ?>" placeholder="姓" required>
      <input type="text" id="family_first_name" name="family_first_name" value="<?= h($v['family_first_name']) ?>" placeholder="名" required>
    </div>

    <label for="family_email">メールアドレス</label>
    <input type="email" id="family_email" name="family_email" value="<?= h($v['family_email']) ?>">

    <label for="family_phone">電話番号</label>
    <input type="tel" id="family_phone" name="family_phone" value="<?= h($v['family_phone']) ?>">

    <h2>ご利用者様(ご本人)について</h2>
    <label for="user_last_name">お名前</label>
    <div class="name-row">
      <input type="text" id="user_last_name" name="user_last_name" value="<?= h($v['user_last_name']) ?>" placeholder="姓">
      <input type="text" id="user_first_name" name="user_first_name" value="<?= h($v['user_first_name']) ?>" placeholder="名">
    </div>
    <div class="hint">任意です。ご登録いただくと、マイページやご家族向けの通知にこのお名前を表示します(あとからマイページで登録・変更することもできます)</div>

    <label for="relation">続柄</label>
    <input type="text" id="relation" name="relation" value="<?= h($v['relation']) ?>" placeholder="例: 息子、娘、ケアマネージャー">

    <label for="user_phone">電話番号</label>
    <input type="tel" id="user_phone" name="user_phone" value="<?= h($v['user_phone']) ?>">

    <label for="user_zip">郵便番号</label>
    <input type="text" id="user_zip" name="user_zip" value="<?= h($v['user_zip']) ?>" inputmode="numeric" placeholder="1234567" maxlength="8">
    <div class="hint">ハイフンなしで入力すると、住所が自動で入力されます</div>

    <label for="user_address">ご住所</label>
    <input type="text" id="user_address" name="user_address" value="<?= h($v['user_address']) ?>">

    <label for="user_birthdate_year">生年月日</label>
    <div class="birthdate-row">
      <select id="user_birthdate_year" name="user_birthdate_year">
        <option value="">年</option>
        <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 110; $y--): ?>
          <option value="<?= $y ?>" <?= $bdYear === (string) $y ? 'selected' : '' ?>><?= $y ?>年</option>
        <?php endfor; ?>
      </select>
      <select id="user_birthdate_month" name="user_birthdate_month">
        <option value="">月</option>
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= $bdMonth === (string) $m ? 'selected' : '' ?>><?= $m ?>月</option>
        <?php endfor; ?>
      </select>
      <select id="user_birthdate_day" name="user_birthdate_day">
        <option value="">日</option>
        <?php for ($d = 1; $d <= 31; $d++): ?>
          <option value="<?= $d ?>" <?= $bdDay === (string) $d ? 'selected' : '' ?>><?= $d ?>日</option>
        <?php endfor; ?>
      </select>
    </div>

    <label>ご本人の性別</label>
    <div class="hint">任意です。会話の話し方・言葉選びを合わせる参考にします</div>
    <div class="plan">
      <label><input type="radio" name="user_gender" value="male" <?= $v['user_gender'] === 'male' ? 'checked' : '' ?>> 男性</label>
    </div>
    <div class="plan">
      <label><input type="radio" name="user_gender" value="female" <?= $v['user_gender'] === 'female' ? 'checked' : '' ?>> 女性</label>
    </div>
    <div class="plan">
      <label><input type="radio" name="user_gender" value="" <?= $v['user_gender'] === '' ? 'checked' : '' ?>> 回答しない</label>
    </div>

    <label>話し相手の性別</label>
    <div class="plan">
      <label><input type="radio" name="companion_gender" value="male" <?= $v['companion_gender'] === 'male' ? 'checked' : '' ?>> 男性</label>
    </div>
    <div class="plan">
      <label><input type="radio" name="companion_gender" value="female" <?= $v['companion_gender'] === 'female' ? 'checked' : '' ?>> 女性</label>
    </div>
    <div class="plan">
      <label><input type="radio" name="companion_gender" value="random" <?= $v['companion_gender'] === 'random' ? 'checked' : '' ?>> おまかせ</label>
    </div>

    <h2>プランを選択</h2>
    <?php foreach ($plans as $plan): ?>
      <?php if (!empty($plan['coming_soon'])): ?>
        <div class="plan plan-soon">
          <div class="name-row"><span><?= h($plan['name']) ?></span><span class="badge-soon">近日公開</span></div>
          <?php if (!empty($plan['description'])): ?><div class="hint"><?= h($plan['description']) ?></div><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="plan">
          <label>
            <input type="radio" name="plan_id" value="<?= (int) $plan['id'] ?>" <?= $v['plan_id'] === (string) $plan['id'] ? 'checked' : '' ?> required>
            <span><?= h($plan['name']) ?> <span class="price">月額<?= number_format((int) $plan['price_yen']) ?>円</span><?php if (!empty($plan['description'])): ?><br><span class="hint"><?= h($plan['description']) ?></span><?php endif; ?></span>
          </label>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>

    <button type="submit">この内容で申し込む<?= Layout::icon('play') ?></button>
  </form>
</div>
<script>
(function () {
  var zipInput = document.getElementById('user_zip');
  var addressInput = document.getElementById('user_address');
  zipInput.addEventListener('input', function () {
    var digits = zipInput.value.replace(/[^0-9]/g, '');
    if (digits.length !== 7) {
      return;
    }
    fetch('https://zipcloud.ibsnet.co.jp/api/search?zipcode=' + digits)
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.status !== 200 || !data.results || !data.results.length) {
          return;
        }
        var r = data.results[0];
        addressInput.value = r.address1 + r.address2 + r.address3;
      })
      .catch(function () {});
  });
})();
</script>
    <?php
    Layout::renderFooter();
}

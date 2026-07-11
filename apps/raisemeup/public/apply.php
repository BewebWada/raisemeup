<?php
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/UserRepository.php';
require_once __DIR__ . '/../src/FamilyAccountRepository.php';
require_once __DIR__ . '/../src/PlanRepository.php';
require_once __DIR__ . '/../src/SubscriptionRepository.php';
require_once __DIR__ . '/../src/StripeClient.php';
require_once __DIR__ . '/../src/ClaudeClient.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

const TRIAL_DAYS = 10;

Env::load(__DIR__ . '/../../../.env');
session_start();

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
$planRepo = new PlanRepository($pdo);

if (empty($_SESSION['apply_csrf_token'])) {
    $_SESSION['apply_csrf_token'] = bin2hex(random_bytes(32));
}

// --- 申込完了画面(POST→Stripe Checkout→リダイレクト後のGET、結果はセッションに一時保存したものを1回だけ表示) ---
if (isset($_GET['done']) && !empty($_SESSION['apply_result'])) {
    $result = $_SESSION['apply_result'];
    unset($_SESSION['apply_result']);
    renderDone($result, false);
    exit;
}

// Stripe Checkoutを利用者が途中でキャンセルした場合。トライアル自体は既に作成済みなので、
// 支払い未登録である旨だけ伝えて通常のトライアル案内を表示する(サービス利用は妨げない)
if (isset($_GET['cancelled']) && !empty($_SESSION['apply_result'])) {
    $result = $_SESSION['apply_result'];
    unset($_SESSION['apply_result']);
    renderDone($result, true);
    exit;
}

$errors = [];
$formValues = [
    'family_name' => '', 'family_email' => '', 'family_phone' => '',
    'user_display_name' => '', 'user_phone' => '', 'user_address' => '',
    'user_birthdate' => '', 'relation' => '', 'plan_id' => '', 'companion_gender' => 'random',
];

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

    $activePlans = $planRepo->getActivePlans();
    $activePlanIds = array_map(fn($p) => (string) $p['id'], $activePlans);

    if ($formValues['family_name'] === '') {
        $errors[] = 'お申込者(ご家族)様のお名前を入力してください。';
    }
    if ($formValues['family_email'] !== '' && !filter_var($formValues['family_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'メールアドレスの形式が正しくありません。';
    }
    if ($formValues['user_display_name'] === '') {
        $errors[] = 'ご利用者様(ご本人)のお名前・呼び名を入力してください。';
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

    if (empty($errors)) {
        $selectedPlan = $planRepo->find((int) $formValues['plan_id']);

        try {
            $pdo->beginTransaction();

            $familyRepo = new FamilyAccountRepository($pdo);
            $userRepo = new UserRepository($pdo);
            $subscriptionRepo = new SubscriptionRepository($pdo);

            $family = $familyRepo->create([
                'name' => $formValues['family_name'],
                'email' => $formValues['family_email'],
                'phone' => $formValues['family_phone'],
            ]);

            $user = $userRepo->createPending([
                'display_name' => $formValues['user_display_name'],
                'phone' => $formValues['user_phone'],
                'address' => $formValues['user_address'],
                'birthdate' => $formValues['user_birthdate'],
                'companion_gender' => $formValues['companion_gender'],
            ]);

            $pdo->prepare(
                "INSERT INTO user_family_links (user_id, family_account_id, relation, role, notify_priority, is_active)
                 VALUES (?, ?, ?, 'payer', 1, 1)"
            )->execute([$user['id'], $family['id'], $formValues['relation'] ?: null]);

            $subscriptionId = $subscriptionRepo->createTrial($user['id'], $family['id'], (int) $selectedPlan['id'], TRIAL_DAYS);

            $pdo->commit();

            // 名前決定はトライアル契約の確定とは独立した処理なので、失敗してもここまでのDB確定はロールバックしない
            // (generateCompanionName自体がAPI失敗時に固定名へフォールバックするため、実質必ず何かの名前が付くが、
            // 保存(DB書き込み)自体が失敗する可能性もあるので、ここも独立したtry/catchで囲み、既に確定した
            // トライアルを「エラー」として家族に見せてしまわないようにする)
            $companionName = 'Raise Me Up';
            try {
                $claudeClient = new ClaudeClient(Config::get('ANTHROPIC_API_KEY'), Config::get('CLAUDE_MODEL'));
                $companionName = $claudeClient->generateCompanionName($formValues['companion_gender']);
                $userRepo->setCompanionName((int) $user['id'], $companionName);
            } catch (Throwable $e) {
                error_log('Companion name generation/save failed: ' . $e->getMessage());
            }

            $trialEndsAt = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->modify('+' . TRIAL_DAYS . ' days');

            $_SESSION['apply_result'] = [
                'user_display_name' => $formValues['user_display_name'],
                'companion_name' => $companionName,
                'user_invite_code' => $user['invite_code'],
                'family_invite_code' => $family['invite_code'],
                'plan_name' => $selectedPlan['name'],
                'plan_price_yen' => $selectedPlan['price_yen'],
                'trial_ends_at' => $trialEndsAt->format('Y年n月j日'),
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
                        $formValues['family_name'],
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

renderForm($planRepo->getActivePlans(), $errors, $formValues, $_SESSION['apply_csrf_token']);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function renderForm(array $plans, array $errors, array $v, string $csrfToken): void
{
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ご利用申込 | Raise Me Up</title>
<style>
  body { font-family: -apple-system, "Hiragino Sans", "Yu Gothic", sans-serif; background:#f7f6f3; color:#333; margin:0; padding:24px 16px 60px; }
  .card { max-width: 560px; margin: 0 auto; background:#fff; border-radius:12px; padding:28px 24px; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
  h1 { font-size:1.4rem; margin-top:0; }
  h2 { font-size:1.05rem; margin:28px 0 12px; border-left:4px solid #d98a3d; padding-left:8px; }
  label { display:block; font-weight:bold; margin:14px 0 4px; font-size:0.95rem; }
  input[type=text], input[type=email], input[type=tel], input[type=date] {
    width:100%; box-sizing:border-box; padding:10px; font-size:1rem; border:1px solid #ccc; border-radius:6px;
  }
  .plan { border:1px solid #ddd; border-radius:8px; padding:12px; margin-bottom:8px; }
  .plan label { display:flex; align-items:baseline; gap:8px; font-weight:normal; margin:0; }
  .plan .price { color:#b05a1e; font-weight:bold; }
  .hint { font-size:0.85rem; color:#777; margin-top:2px; }
  .errors { background:#fdecea; border:1px solid #f5b0a8; color:#a12a1f; padding:12px 16px; border-radius:8px; margin-bottom:16px; }
  button { margin-top:24px; width:100%; padding:14px; font-size:1.05rem; background:#d98a3d; color:#fff; border:none; border-radius:8px; cursor:pointer; }
  button:hover { background:#c07a30; }
  .honeypot { position:absolute; left:-9999px; }
</style>
</head>
<body>
<div class="card">
  <h1>Raise Me Up ご利用申込</h1>
  <p>ご家族が代わってお申込みください。お申込み後、<?= TRIAL_DAYS ?>日間無料でお試しいただけます。</p>

  <?php if (!empty($errors)): ?>
    <div class="errors">
      <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" action="/apply/">
    <input type="text" name="website" class="honeypot" tabindex="-1" autocomplete="off" value="">
    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

    <h2>お申込者様(ご家族)について</h2>
    <label for="family_name">お名前</label>
    <input type="text" id="family_name" name="family_name" value="<?= h($v['family_name']) ?>" required>

    <label for="family_email">メールアドレス</label>
    <input type="email" id="family_email" name="family_email" value="<?= h($v['family_email']) ?>">

    <label for="family_phone">電話番号</label>
    <input type="tel" id="family_phone" name="family_phone" value="<?= h($v['family_phone']) ?>">

    <h2>ご利用者様(ご本人)について</h2>
    <label for="user_display_name">お名前・呼び名</label>
    <input type="text" id="user_display_name" name="user_display_name" value="<?= h($v['user_display_name']) ?>" required>
    <div class="hint">会話の中で「〇〇さん」とお呼びする際に使います</div>

    <label for="relation">続柄</label>
    <input type="text" id="relation" name="relation" value="<?= h($v['relation']) ?>" placeholder="例: 息子、娘、ケアマネージャー">

    <label for="user_phone">電話番号</label>
    <input type="tel" id="user_phone" name="user_phone" value="<?= h($v['user_phone']) ?>">

    <label for="user_address">ご住所</label>
    <input type="text" id="user_address" name="user_address" value="<?= h($v['user_address']) ?>">

    <label for="user_birthdate">生年月日</label>
    <input type="date" id="user_birthdate" name="user_birthdate" value="<?= h($v['user_birthdate']) ?>">

    <label>話し相手の性別</label>
    <div class="hint">AIの話し相手の名前を決める際に使用します</div>
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
      <div class="plan">
        <label>
          <input type="radio" name="plan_id" value="<?= (int) $plan['id'] ?>" <?= $v['plan_id'] === (string) $plan['id'] ? 'checked' : '' ?> required>
          <span><?= h($plan['name']) ?> <span class="price">月額<?= number_format((int) $plan['price_yen']) ?>円</span><?php if (!empty($plan['description'])): ?><br><span class="hint"><?= h($plan['description']) ?></span><?php endif; ?></span>
        </label>
      </div>
    <?php endforeach; ?>

    <button type="submit">この内容で申し込む</button>
  </form>
</div>
</body>
</html>
    <?php
}

function renderDone(array $r, bool $paymentPending): void
{
    $addFriendUrl = Config::get('LINE_ADD_FRIEND_URL', '');
    $familyAddFriendUrl = Config::get('LINE_FAMILY_ADD_FRIEND_URL', '');
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>お申込みありがとうございます | Raise Me Up</title>
<style>
  body { font-family: -apple-system, "Hiragino Sans", "Yu Gothic", sans-serif; background:#f7f6f3; color:#333; margin:0; padding:24px 16px 60px; }
  .card { max-width: 560px; margin: 0 auto; background:#fff; border-radius:12px; padding:28px 24px; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
  h1 { font-size:1.3rem; }
  .code-box { background:#fff8ef; border:1px solid #eecb98; border-radius:8px; padding:16px; margin:16px 0; text-align:center; }
  .code { font-size:1.8rem; font-weight:bold; letter-spacing:0.15em; color:#b05a1e; }
  .steps { padding-left:1.2em; }
  .steps li { margin-bottom:10px; }
  a.button { display:block; text-align:center; margin-top:12px; padding:12px; background:#06c755; color:#fff; text-decoration:none; border-radius:8px; font-weight:bold; }
  .qr-box { text-align:center; margin-top:12px; }
  .qr-box img { width:160px; height:160px; border:1px solid #eee; border-radius:8px; padding:8px; background:#fff; }
  .optional { margin-top:32px; padding-top:16px; border-top:1px solid #eee; }
</style>
</head>
<body>
<div class="card">
  <h1>お申込みありがとうございます</h1>
  <p><?= h($r['plan_name']) ?>(月額<?= number_format((int) $r['plan_price_yen']) ?>円)を<?= TRIAL_DAYS ?>日間無料でお試しいただけます(<?= h($r['trial_ends_at']) ?>まで)。</p>
  <?php if ($paymentPending): ?>
    <p style="color:#a12a1f;">お支払い情報の登録が完了していません。無料期間中はそのままご利用いただけますが、期間終了までにお支払い情報のご登録が必要です。折り返しご案内いたします。</p>
  <?php endif; ?>

  <p>話し相手の名前は<strong><?= h($r['companion_name']) ?></strong>に決まりました。</p>

  <p><strong><?= h($r['user_display_name']) ?></strong>様ご本人のスマートフォンで、以下の手順をお願いします。</p>
  <ol class="steps">
    <li>LINEで「Raise Me Up」公式アカウントを友だち追加する</li>
    <?php if ($addFriendUrl !== ''): ?>
      <li><a class="button" href="<?= h($addFriendUrl) ?>">友だち追加はこちら</a></li>
      <li>
        <div class="qr-box">
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=<?= urlencode($addFriendUrl) ?>" alt="友だち追加QRコード" width="160" height="160">
        </div>
      </li>
    <?php endif; ?>
    <li>最初のメッセージとして、下記の連携コードをそのまま送信する</li>
  </ol>
  <div class="code-box">
    <div class="code"><?= h($r['user_invite_code']) ?></div>
  </div>

  <div class="optional">
    <p>ご家族様ご自身のLINEでも、無料期間終了のお知らせなどを受け取りたい場合は、ご家族向けの公式アカウント「RaiseMeUpサポート」を友だち追加のうえ、以下のコードを送ってください(任意)。</p>
    <?php if ($familyAddFriendUrl !== ''): ?>
      <a class="button" href="<?= h($familyAddFriendUrl) ?>">RaiseMeUpサポートを友だち追加</a>
      <div class="qr-box">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=<?= urlencode($familyAddFriendUrl) ?>" alt="RaiseMeUpサポート友だち追加QRコード" width="160" height="160">
      </div>
    <?php endif; ?>
    <div class="code-box">
      <div class="code"><?= h($r['family_invite_code']) ?></div>
    </div>
  </div>
</div>
</body>
</html>
    <?php
}

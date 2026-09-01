<?php
// マイページから、既存の家族アカウントに2人目以降の利用者を追加するためのフォーム。
// 家族(お申込者)は既にLINEログイン済みという前提のため、apply.phpと違って
// お申込者情報や家族向けLINE連携ステップは扱わず、利用者本人の情報とプラン選択のみを受け付ける。
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/UserRepository.php';
require_once __DIR__ . '/../src/FamilyAccountRepository.php';
require_once __DIR__ . '/../src/PlanRepository.php';
require_once __DIR__ . '/../src/SubscriptionRepository.php';
require_once __DIR__ . '/../src/StripeClient.php';
require_once __DIR__ . '/../src/ApplyDoneView.php';
require_once __DIR__ . '/../src/MailClient.php';
require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');
Session::start();

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);

$familyId = $_SESSION['mypage_family_id'] ?? null;
if ($familyId === null) {
    header('Location: /mypage/');
    exit;
}

$familyRepo = new FamilyAccountRepository($pdo);
$family = $familyRepo->find((int) $familyId);
if ($family === null) {
    header('Location: /mypage/');
    exit;
}

if (empty($_SESSION['mypage_csrf_token'])) {
    $_SESSION['mypage_csrf_token'] = bin2hex(random_bytes(32));
}

$planRepo = new PlanRepository($pdo);
$errors = [];
$formValues = [
    'relation' => '', 'user_phone' => '', 'user_zip' => '', 'user_address' => '',
    'user_birthdate' => '', 'user_gender' => '', 'plan_id' => '', 'companion_gender' => 'random',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['mypage_csrf_token'], $token)) {
        $errors[] = 'フォームの有効期限が切れました。お手数ですがもう一度お試しください。';
    }

    foreach (array_keys($formValues) as $key) {
        $formValues[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    $activePlans = $planRepo->getActivePlans();
    // coming_soonなプランは表示はするが、申込みでは選択できないようにする(apply.phpと同じ方針)
    $selectablePlans = array_filter($activePlans, fn($p) => !$p['coming_soon']);
    $activePlanIds = array_map(fn($p) => (string) $p['id'], $selectablePlans);

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

    if (empty($errors)) {
        $selectedPlan = $planRepo->find((int) $formValues['plan_id']);

        try {
            $pdo->beginTransaction();

            $userRepo = new UserRepository($pdo);
            $subscriptionRepo = new SubscriptionRepository($pdo);

            // 呼び名は申込み時には聞かず、TAYORIとの会話の中で自然に確認して
            // ConversationHandlerが後から設定する(apply.phpと同じ方針)
            $user = $userRepo->createPending([
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

            $subscriptionId = $subscriptionRepo->createTrial($user['id'], (int) $family['id'], (int) $selectedPlan['id'], TRIAL_DAYS);

            $pdo->commit();

            // 申込者(ご家族)への追加確認メール。送信失敗はログに残すのみで、追加自体は既に確定済みなので
            // 処理は止めない
            if (!empty($family['email'])) {
                try {
                    $mailClient = MailClient::fromConfig();
                    if ($mailClient !== null) {
                        $trialEndsAt = date('Y-m-d', strtotime('+' . TRIAL_DAYS . ' days'));
                        $mailClient->send(
                            (string) $family['email'],
                            '【TAYORI】利用者を追加しました',
                            (string) ($family['name'] ?? '') . "様\n\n"
                            . "TAYORIのご利用者様を追加しました。以下の内容で受け付けました。\n\n"
                            . "プラン: {$selectedPlan['name']}(月額" . number_format((int) $selectedPlan['price_yen']) . "円)\n"
                            . "無料期間: 本日から{$trialEndsAt}まで\n\n"
                            . "引き続き、追加されたご利用者様のLINE連携をお願いいたします。\n\n"
                            . "ご不明な点はsupport@tayori-net.jpまでご連絡ください。"
                        );
                    }
                } catch (Throwable $e) {
                    error_log('mypage_add_user.php confirmation email failed: ' . $e->getMessage());
                }
            }

            // 家族は既に支払い情報登録済み(stripe_customer_id設定済み)の場合のみ、Checkoutを経由せず
            // 直接Subscriptionを作成する(カード再入力なし)。未登録の場合はローカルのtrialのみ作成し、
            // apply.php同様ここでも失敗を許容してサービス利用は妨げない
            if (!empty($family['stripe_customer_id']) && !empty($selectedPlan['stripe_price_id'])) {
                try {
                    $stripe = new StripeClient(Config::get('STRIPE_SECRET_KEY', ''));
                    $subscription = $stripe->createSubscription(
                        $family['stripe_customer_id'],
                        $selectedPlan['stripe_price_id'],
                        TRIAL_DAYS,
                        ['family_account_id' => (string) $family['id'], 'subscription_id' => (string) $subscriptionId]
                    );
                    $subscriptionRepo->attachStripeSubscription($subscriptionId, $subscription['id']);
                } catch (Throwable $e) {
                    error_log('Stripe subscription creation failed (mypage_add_user): ' . $e->getMessage());
                }
            }

            // apply.phpの申込み完了画面(LINE連携ステップUI)をそのまま使う。line_login_callback.php等の
            // LINE連携まわりの経路がすべてセッションの$_SESSION['apply_result']を前提にしているため、
            // apply.phpの一人目の申込みフローと同じ形でここでもセッションにセットしておく
            $_SESSION['apply_result'] = [
                'user_id' => (int) $user['id'],
                'family_id' => (int) $family['id'],
            ];
            header('Location: /apply/?done=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('mypage_add_user.php submission failed: ' . $e->getMessage());
            $errors[] = '追加処理中にエラーが発生しました。お手数ですが時間をおいて再度お試しください。';
        }
    }
}

renderForm($planRepo->getActivePlans(), $errors, $formValues, $_SESSION['mypage_csrf_token']);

function renderForm(array $plans, array $errors, array $v, string $csrfToken): void
{
    Layout::renderHeader('mypage', '利用者を追加');
    ?>
<style>
  .card { max-width: 560px; margin: 0 auto; background:#fff; border-radius:12px; padding:28px 24px; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
  .card h1 { font-size:1.3rem; margin-top:0; }
  .card h2 { font-size:1.05rem; margin:28px 0 12px; border-left:4px solid #4B8B5A; padding-left:8px; }
  .card label { display:block; font-weight:bold; margin:14px 0 4px; font-size:0.95rem; }
  .card input[type=text], .card input[type=email], .card input[type=tel], .card input[type=date] {
    width:100%; box-sizing:border-box; padding:10px; font-size:1rem; border:1px solid #ccc; border-radius:6px;
  }
  .plan { border:1px solid #ddd; border-radius:8px; padding:12px; margin-bottom:8px; }
  .plan label { display:flex; align-items:baseline; gap:8px; font-weight:normal; margin:0; }
  .plan .price { color:#4B8B5A; font-weight:bold; }
  .plan-soon { border:1px dashed #cfc9bd; background:#faf9f6; color:#888; }
  .plan-soon .name-row { display:flex; align-items:baseline; gap:8px; }
  .plan-soon .badge-soon { display:inline-block; font-size:0.75rem; font-weight:bold; color:#fff; background:#a9915a; border-radius:10px; padding:2px 8px; }
  .hint { font-size:0.85rem; color:#777; margin-top:2px; }
  .errors { background:#fdecea; border:1px solid #f5b0a8; color:#a12a1f; padding:12px 16px; border-radius:8px; margin-bottom:16px; }
  .top-bar { display:flex; justify-content:flex-end; max-width:560px; margin:0 auto 16px; }
  .top-bar a { font-size:0.9rem; }
  .card button { display:inline-flex; align-items:center; justify-content:center; gap:10px; margin-top:24px; width:100%; padding:14px; font-size:1.05rem; background:#4B8B5A; color:#fff; border:none; border-radius:8px; cursor:pointer; }
  .card button .icon { width:1.35em; height:1.35em; }
  .card button:hover { background:#1E4729; }
</style>
<div class="top-bar"><a href="/mypage/">&larr; マイページに戻る</a></div>
<div class="card">
  <h1>利用者を追加</h1>
  <p>ご家族のマイページに、もうお一方のご利用者様を追加できます。お申込み後、<?= TRIAL_DAYS ?>日間無料でお試しいただけます。</p>

  <?php if (!empty($errors)): ?>
    <div class="errors">
      <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" action="/mypage_add_user.php">
    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

    <h2>ご利用者様(ご本人)について</h2>
    <label for="relation">続柄</label>
    <input type="text" id="relation" name="relation" value="<?= h($v['relation']) ?>" placeholder="例: 息子、娘、ケアマネージャー">

    <label for="user_phone">電話番号</label>
    <input type="tel" id="user_phone" name="user_phone" value="<?= h($v['user_phone']) ?>">

    <label for="user_zip">郵便番号</label>
    <input type="text" id="user_zip" name="user_zip" value="<?= h($v['user_zip']) ?>" inputmode="numeric" placeholder="1234567" maxlength="8">
    <div class="hint">ハイフンなしで入力すると、住所が自動で入力されます</div>

    <label for="user_address">ご住所</label>
    <input type="text" id="user_address" name="user_address" value="<?= h($v['user_address']) ?>">

    <label for="user_birthdate">生年月日</label>
    <input type="date" id="user_birthdate" name="user_birthdate" value="<?= h($v['user_birthdate']) ?>">

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

    <button type="submit">この内容で追加する<?= Layout::icon('play') ?></button>
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

<?php
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/FamilyAccountRepository.php';
require_once __DIR__ . '/../src/UserRepository.php';
require_once __DIR__ . '/../src/PlanRepository.php';
require_once __DIR__ . '/../src/SummaryRepository.php';
require_once __DIR__ . '/../src/RiskEventRepository.php';
require_once __DIR__ . '/../src/FamilyThemeRepository.php';
require_once __DIR__ . '/../src/LineLoginClient.php';
require_once __DIR__ . '/../src/Layout.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');
session_start();

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
$familyRepo = new FamilyAccountRepository($pdo);
$userRepo = new UserRepository($pdo);
$familyThemeRepo = new FamilyThemeRepository($pdo);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

const SUBSCRIPTION_STATUS_LABELS = [
    'trial' => '無料お試し中',
    'active' => 'ご利用中',
    'trial_expired' => '無料期間終了(お支払い未登録)',
    'past_due' => 'お支払いエラー',
    'cancelled' => '解約済み',
    'abandoned' => 'キャンセル済み',
];

const SUMMARY_TYPE_LABELS = [
    'schedule' => '予定',
    'relationship' => '人間関係',
    'preference' => '好み',
    'routine' => '日常のルーティン',
];

const RISK_LEVEL_LABELS = ['low' => '低', 'medium' => '中', 'high' => '高'];

// 安心レポートでリスクアラート(検知結果の表示・通知)を出すプラン。ConversationHandlerの
// RISK_NOTIFY_PLAN_CODESと揃えている(premium_medicalはfamily_watchの上位互換のため含む)。
// 対象外(寄り添いベーシック)は「本日のトーク回数」程度の軽いサマリーのみ表示する
const RISK_REPORT_PLAN_CODES = ['family_watch', 'premium_medical'];

$familyId = $_SESSION['mypage_family_id'] ?? null;
$family = $familyId !== null ? $familyRepo->find((int) $familyId) : null;

if ($family === null) {
    renderLoginScreen((string) ($_GET['login_error'] ?? ''));
    exit;
}

if (empty($_SESSION['mypage_csrf_token'])) {
    $_SESSION['mypage_csrf_token'] = bin2hex(random_bytes(32));
}

// このログイン中の家族に実際に紐づくuser_idの一覧(他家族のuser_idをPOSTで指定されても更新できないようにする、
// という所有権チェックに使う)
$stmt = $pdo->prepare('SELECT user_id FROM user_family_links WHERE family_account_id = ? AND is_active = 1');
$stmt->execute([(int) $family['id']]);
$ownedUserIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

$errors = [];
$saved = false;
$themeSaved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['mypage_csrf_token'], $token)) {
        $errors[] = 'フォームの有効期限が切れました。お手数ですがもう一度お試しください。';
    } elseif (($_POST['action'] ?? 'update_profile') === 'add_theme') {
        $themeUserId = (int) ($_POST['theme_user_id'] ?? 0);
        $themeText = trim((string) ($_POST['theme_text'] ?? ''));
        $themePlanCode = $pdo->prepare(
            'SELECT p.code FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 1'
        );
        $themePlanCode->execute([$themeUserId]);

        if (!in_array($themeUserId, $ownedUserIds, true)) {
            $errors[] = '不正な操作です。';
        } elseif ($themeText === '') {
            $errors[] = 'テーマを入力してください。';
        } elseif (!in_array($themePlanCode->fetchColumn(), RISK_REPORT_PLAN_CODES, true)) {
            $errors[] = 'テーマ設定は、寄り添いスタンダード以上のプランでご利用いただけます。';
        } else {
            $familyThemeRepo->add($themeUserId, (int) $family['id'], $themeText);
            $themeSaved = true;
        }
    } elseif (($_POST['action'] ?? '') === 'delete_theme') {
        $familyThemeRepo->cancel((int) ($_POST['theme_id'] ?? 0), (int) $family['id']);
    } else {
        $familyName = trim((string) ($_POST['family_name'] ?? ''));
        $familyEmail = trim((string) ($_POST['family_email'] ?? ''));
        $familyPhone = trim((string) ($_POST['family_phone'] ?? ''));

        if ($familyName === '') {
            $errors[] = 'お名前を入力してください。';
        }
        if ($familyEmail !== '' && !filter_var($familyEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'メールアドレスの形式が正しくありません。';
        } elseif ($familyEmail !== '' && $familyEmail !== $family['email']) {
            $duplicate = $familyRepo->findByEmail($familyEmail);
            if ($duplicate !== null && (int) $duplicate['id'] !== (int) $family['id']) {
                $errors[] = 'このメールアドレスは既に他のお申込みで使われています。';
            }
        }

        if (empty($errors)) {
            $familyRepo->update((int) $family['id'], [
                'name' => $familyName,
                'email' => $familyEmail,
                'phone' => $familyPhone,
            ]);

            foreach ($_POST['user'] ?? [] as $userId => $fields) {
                $userId = (int) $userId;
                if (!in_array($userId, $ownedUserIds, true)) {
                    // このログイン中の家族に紐づかないuser_idが送られてきた場合は無視する(改ざん対策)
                    continue;
                }
                $displayName = trim((string) ($fields['display_name'] ?? ''));
                $phone = trim((string) ($fields['phone'] ?? ''));
                $zip = preg_replace('/[^0-9]/', '', (string) ($fields['zip'] ?? ''));
                $address = trim((string) ($fields['address'] ?? ''));
                $birthdate = trim((string) ($fields['birthdate'] ?? ''));
                $relation = trim((string) ($fields['relation'] ?? ''));
                $gender = trim((string) ($fields['gender'] ?? ''));

                if ($birthdate !== '') {
                    $d = DateTime::createFromFormat('Y-m-d', $birthdate);
                    if (!$d || $d->format('Y-m-d') !== $birthdate) {
                        $birthdate = '';
                    }
                }
                if (!in_array($gender, ['male', 'female'], true)) {
                    $gender = '';
                }

                $userRepo->update($userId, [
                    'display_name' => $displayName,
                    'phone' => $phone,
                    'postal_code' => $zip,
                    'address' => $address,
                    'birthdate' => $birthdate,
                    'gender' => $gender,
                ]);

                $pdo->prepare(
                    'UPDATE user_family_links SET relation = ? WHERE user_id = ? AND family_account_id = ?'
                )->execute([$relation ?: null, $userId, (int) $family['id']]);
            }

            $family = $familyRepo->find((int) $family['id']);
            $saved = true;
        }
    }
}

$stmt = $pdo->prepare(
    'SELECT u.*, l.relation FROM users u
     JOIN user_family_links l ON l.user_id = u.id
     WHERE l.family_account_id = ? AND l.is_active = 1
     ORDER BY u.id ASC'
);
$stmt->execute([(int) $family['id']]);
$linkedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summaryRepo = new SummaryRepository($pdo);
$riskRepo = new RiskEventRepository($pdo);

$talkCountStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM conversations WHERE user_id = ? AND direction = 'inbound' AND DATE(created_at) = CURDATE()"
);

$panels = [];
foreach ($linkedUsers as $u) {
    $subStmt = $pdo->prepare(
        'SELECT s.status, s.trial_ends_at, s.current_period_end, p.code AS plan_code, p.name AS plan_name, p.price_yen
         FROM subscriptions s JOIN plans p ON p.id = s.plan_id
         WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 1'
    );
    $subStmt->execute([(int) $u['id']]);

    $talkCountStmt->execute([(int) $u['id']]);

    $panels[] = [
        'user' => $u,
        'subscription' => $subStmt->fetch(PDO::FETCH_ASSOC) ?: null,
        'summaries' => $summaryRepo->getAllForUser((int) $u['id']),
        'riskEvents' => $riskRepo->getRecentForUser((int) $u['id'], 10),
        'todayTalkCount' => (int) $talkCountStmt->fetchColumn(),
        'themes' => $familyThemeRepo->getActiveForUser((int) $u['id']),
    ];
}

renderMypage($family, $panels, $errors, $saved, $themeSaved);

function renderLoginScreen(string $loginError): void
{
    Layout::renderHeader('mypage', 'マイページ');
    ?>
<style>
  .card { max-width: 480px; margin: 0 auto; background:#fff; border-radius:12px; padding:28px 24px; box-shadow:0 2px 8px rgba(0,0,0,0.06); text-align:center; }
  .card h1 { font-size:1.3rem; margin-top:0; }
  .card p { color:#555; line-height:1.7; }
  a.button { display:inline-flex; align-items:center; gap:10px; margin-top:16px; padding:14px 32px; background:#06c755; color:#fff; text-decoration:none; border-radius:8px; font-weight:bold; }
  a.button .icon { width:1.3em; height:1.3em; }
  .errors { background:#fdecea; border:1px solid #f5b0a8; color:#a12a1f; padding:12px 16px; border-radius:8px; margin-bottom:16px; text-align:left; }
</style>
<div class="card">
  <h1>ご家族向けマイページ</h1>
  <?php if ($loginError === 'notfound'): ?>
    <div class="errors">このLINEアカウントはご登録が見つかりませんでした。お申込み時にご案内した「ご家族向け通知の連携」を先に行ってください。</div>
  <?php elseif ($loginError !== ''): ?>
    <div class="errors">ログインに失敗しました。お手数ですが、もう一度お試しください。</div>
  <?php endif; ?>
  <p>ご契約状況の確認、お支払い方法の変更、ご登録情報の編集ができます。<br>お申込み時に連携したLINEアカウントでログインしてください。</p>
  <a class="button" href="/mypage_login_start.php"><?= Layout::icon('login') ?> LINEでログイン</a>
</div>
    <?php
    Layout::renderFooter();
}

function renderMypage(array $family, array $panels, array $errors, bool $saved, bool $themeSaved): void
{
    Layout::renderHeader('mypage', 'マイページ');
    ?>
<style>
  .card { max-width: 720px; margin: 0 auto; background:#fff; border-radius:12px; padding:28px 24px; box-shadow:0 2px 8px rgba(0,0,0,0.06); margin-bottom:20px; }
  .card h1 { font-size:1.3rem; margin-top:0; }
  .card h2 { display:flex; align-items:center; gap:8px; font-size:1.05rem; margin:0 0 14px; border-left:4px solid #4B8B5A; padding-left:8px; }
  .top-bar a { display:inline-flex; align-items:center; gap:6px; }
  .top-bar { display:flex; justify-content:space-between; align-items:baseline; max-width:720px; margin:0 auto 16px; }
  .top-bar a { font-size:0.9rem; }
  .errors { background:#fdecea; border:1px solid #f5b0a8; color:#a12a1f; padding:12px 16px; border-radius:8px; margin-bottom:16px; }
  .notice { background:#eef2ea; border:1px solid #cfdbc4; color:#333; padding:12px 16px; border-radius:8px; margin-bottom:16px; }
  label { display:block; font-weight:bold; margin:14px 0 4px; font-size:0.95rem; }
  input[type=text], input[type=email], input[type=tel], input[type=date] {
    width:100%; box-sizing:border-box; padding:10px; font-size:1rem; border:1px solid #ccc; border-radius:6px;
  }
  .radio-group { display:flex; gap:16px; flex-wrap:wrap; margin:2px 0 4px; }
  .radio-group label { display:flex; align-items:baseline; gap:6px; font-weight:normal; margin:0; }
  button { margin-top:20px; padding:12px 28px; font-size:1rem; background:#4B8B5A; color:#fff; border:none; border-radius:8px; cursor:pointer; }
  button:hover { background:#1E4729; }
  .status-box { background:#f4f8f2; border:1px solid #cfdbc4; border-radius:10px; padding:16px 20px; margin-bottom:16px; }
  .status-box dt { font-size:0.85rem; color:#777; margin-top:8px; }
  .status-box dt:first-child { margin-top:0; }
  .status-box dd { margin:2px 0 0; font-weight:bold; }
  a.button-billing { display:inline-flex; align-items:center; gap:8px; margin-top:12px; padding:10px 20px; background:#4B8B5A; color:#fff; text-decoration:none; border-radius:8px; font-weight:bold; font-size:0.9rem; }
  a.button-billing .icon { width:1.25em; height:1.25em; }
  .summary-item { margin-bottom:12px; }
  .summary-item .label { font-size:0.8rem; color:#777; font-weight:bold; }
  .summary-item p { margin:2px 0 0; line-height:1.6; }
  .risk-item { border:1px solid #eee; border-radius:8px; padding:10px 14px; margin-bottom:8px; font-size:0.9rem; }
  .risk-item .level-high { color:#a12a1f; font-weight:bold; }
  .risk-item .level-medium { color:#a9711e; font-weight:bold; }
  .risk-item .level-low { color:#777; font-weight:bold; }
  .risk-item .date { color:#999; font-size:0.8rem; }
  .empty-hint { color:#999; font-size:0.9rem; }
  .theme-item { display:flex; align-items:center; gap:10px; border:1px solid #eee; border-radius:8px; padding:10px 14px; margin-bottom:8px; font-size:0.9rem; }
  .theme-item .theme-text { flex:1; }
  .theme-item .theme-expiry { color:#999; font-size:0.8rem; white-space:nowrap; }
  .theme-delete-form { margin:0; }
  .theme-delete-btn { margin:0; padding:5px 12px; font-size:0.8rem; background:#eee; color:#555; }
  .theme-delete-btn:hover { background:#ddd; }
  .theme-add-form { display:flex; gap:8px; margin-top:12px; }
  .theme-add-form input[type=text] { flex:1; margin:0; }
  .theme-add-form button { margin:0; padding:10px 20px; font-size:0.9rem; white-space:nowrap; }
  .user-block { margin-top:32px; padding-top:20px; border-top:1px solid #eee; }
  .user-block:first-child { margin-top:0; padding-top:0; border-top:none; }
</style>
<div class="top-bar">
  <span>ようこそ、<?= h($family['name']) ?>様</span>
  <a href="/mypage_logout.php"><?= Layout::icon('logout') ?> ログアウト</a>
</div>

<?php if (!empty($errors)): ?>
  <div class="card"><div class="errors"><?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?></div></div>
<?php endif; ?>
<?php if ($saved): ?>
  <div class="card"><div class="notice">登録情報を更新しました。</div></div>
<?php endif; ?>
<?php if ($themeSaved): ?>
  <div class="card"><div class="notice">テーマを設定しました。</div></div>
<?php endif; ?>

<?php foreach ($panels as $panel): ?>
  <?php $u = $panel['user']; $sub = $panel['subscription']; ?>
  <div class="card">
    <h2><?= Layout::icon('card') ?> <?= h($u['display_name'] ?: 'ご利用者様') ?>様のご契約状況</h2>
    <?php if ($sub !== null): ?>
      <dl class="status-box">
        <dt>プラン</dt>
        <dd><?= h($sub['plan_name']) ?>(月額<?= number_format((int) $sub['price_yen']) ?>円)</dd>
        <dt>状態</dt>
        <dd><?= h(SUBSCRIPTION_STATUS_LABELS[$sub['status']] ?? $sub['status']) ?></dd>
        <?php if ($sub['status'] === 'trial'): ?>
          <dt>無料期間終了日</dt>
          <dd><?= h((new DateTime($sub['trial_ends_at']))->format('Y年n月j日')) ?></dd>
        <?php elseif ($sub['current_period_end'] !== null): ?>
          <dt>次回のお支払い日</dt>
          <dd><?= h((new DateTime($sub['current_period_end']))->format('Y年n月j日')) ?></dd>
        <?php endif; ?>
      </dl>
      <?php if (!empty($family['stripe_customer_id'])): ?>
        <a class="button-billing" href="/mypage_billing.php"><?= Layout::icon('card') ?> お支払い方法・請求書を管理する</a>
      <?php else: ?>
        <p class="empty-hint">お支払い情報は未登録です。</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="empty-hint">ご契約情報が見つかりませんでした。</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2><?= Layout::icon('shield') ?> 安心レポート</h2>
    <?php if (in_array($sub['plan_code'] ?? null, RISK_REPORT_PLAN_CODES, true)): ?>
      <?php if (empty($panel['riskEvents'])): ?>
        <p class="empty-hint">これまでに気になる会話は検知されていません。</p>
      <?php else: ?>
        <?php foreach ($panel['riskEvents'] as $event): ?>
          <div class="risk-item">
            <span class="level-<?= h($event['risk_level']) ?>">危険度: <?= h(RISK_LEVEL_LABELS[$event['risk_level']] ?? $event['risk_level']) ?></span>
            <span class="date">(<?= h((new DateTime($event['created_at']))->format('Y年n月j日 H:i')) ?>)</span>
            <?php if (!empty($event['pattern_name'])): ?><div><?= h($event['pattern_name']) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <p class="empty-hint">※ 「中」以上を検知した場合はLINEでもお知らせしています。緊急の対応が必要な場合は、直接ご本人にご連絡ください。</p>
      <?php endif; ?>
    <?php else: ?>
      <dl class="status-box">
        <dt>本日のトーク回数</dt>
        <dd><?= (int) $panel['todayTalkCount'] ?>回</dd>
      </dl>
      <p class="empty-hint">気になる会話の検知・通知など、より詳しい見守りは寄り添いスタンダード以上でご利用いただけます。</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2><?= Layout::icon('chat') ?> 会話の様子</h2>
    <?php if (empty($panel['summaries'])): ?>
      <p class="empty-hint">まだ十分な会話が蓄積されていません。</p>
    <?php else: ?>
      <?php foreach (SUMMARY_TYPE_LABELS as $type => $label): ?>
        <?php if (!empty($panel['summaries'][$type])): ?>
          <div class="summary-item">
            <div class="label"><?= h($label) ?></div>
            <p><?= h($panel['summaries'][$type]) ?></p>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2><?= Layout::icon('heart') ?> 気にかけてほしいテーマ</h2>
    <?php if (in_array($sub['plan_code'] ?? null, RISK_REPORT_PLAN_CODES, true)): ?>
      <?php if (empty($panel['themes'])): ?>
        <p class="empty-hint">現在設定しているテーマはありません。</p>
      <?php else: ?>
        <?php foreach ($panel['themes'] as $theme): ?>
          <div class="theme-item">
            <span class="theme-text"><?= h($theme['theme']) ?></span>
            <span class="theme-expiry"><?= h((new DateTime($theme['expires_at']))->format('n月j日')) ?>頃まで</span>
            <form method="post" action="/mypage/" class="theme-delete-form">
              <input type="hidden" name="csrf_token" value="<?= h($_SESSION['mypage_csrf_token']) ?>">
              <input type="hidden" name="action" value="delete_theme">
              <input type="hidden" name="theme_id" value="<?= (int) $theme['id'] ?>">
              <button type="submit" class="theme-delete-btn">削除</button>
            </form>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <form method="post" action="/mypage/" class="theme-add-form">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['mypage_csrf_token']) ?>">
        <input type="hidden" name="action" value="add_theme">
        <input type="hidden" name="theme_user_id" value="<?= (int) $u['id'] ?>">
        <input type="text" name="theme_text" placeholder="例: 水分補給を気にかけてほしい" maxlength="255">
        <button type="submit">追加する</button>
      </form>
      <p class="empty-hint">
        ご家族から言われたこととは明かさず、TAYORI自身が気にかけていることとして普段の会話にさりげなく混ぜます。
        約<?= FamilyThemeRepository::DEFAULT_EXPIRES_IN_DAYS ?>日で自動的に外れます(LINEの「TAYORIサポート」から
        「◯◯をテーマにして」と送っても設定できます)。
      </p>
    <?php else: ?>
      <p class="empty-hint">テーマ設定は、寄り添いスタンダード以上でご利用いただけます。</p>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<div class="card" style="text-align:center;">
  <a class="button-billing" href="/mypage_add_user.php"><?= Layout::icon('heart') ?> 利用者を追加する</a>
  <p class="empty-hint" style="margin-top:8px;">もうお一方のご利用者様を、同じご家族アカウントに追加できます。</p>
</div>

<div class="card">
  <h2><?= Layout::icon('edit') ?> 登録情報の確認・編集</h2>
  <form method="post" action="/mypage/">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['mypage_csrf_token']) ?>">

    <h3>お申込者様(ご家族)について</h3>
    <label for="family_name">お名前</label>
    <input type="text" id="family_name" name="family_name" value="<?= h($family['name']) ?>" required>
    <label for="family_email">メールアドレス</label>
    <input type="email" id="family_email" name="family_email" value="<?= h((string) $family['email']) ?>">
    <label for="family_phone">電話番号</label>
    <input type="tel" id="family_phone" name="family_phone" value="<?= h((string) $family['phone']) ?>">

    <?php foreach ($panels as $panel): ?>
      <?php $u = $panel['user']; ?>
      <div class="user-block">
        <h3><?= h($u['display_name'] ?: 'ご利用者様') ?>様について</h3>
        <label for="user_display_name_<?= (int) $u['id'] ?>">お名前・呼び名</label>
        <input type="text" id="user_display_name_<?= (int) $u['id'] ?>" name="user[<?= (int) $u['id'] ?>][display_name]" value="<?= h((string) $u['display_name']) ?>">
        <label for="relation_<?= (int) $u['id'] ?>">続柄</label>
        <input type="text" id="relation_<?= (int) $u['id'] ?>" name="user[<?= (int) $u['id'] ?>][relation]" value="<?= h((string) $u['relation']) ?>">
        <label for="user_phone_<?= (int) $u['id'] ?>">電話番号</label>
        <input type="tel" id="user_phone_<?= (int) $u['id'] ?>" name="user[<?= (int) $u['id'] ?>][phone]" value="<?= h((string) $u['phone']) ?>">
        <label for="user_zip_<?= (int) $u['id'] ?>">郵便番号</label>
        <input type="text" id="user_zip_<?= (int) $u['id'] ?>" name="user[<?= (int) $u['id'] ?>][zip]" value="<?= h((string) $u['postal_code']) ?>" inputmode="numeric" maxlength="8">
        <label for="user_address_<?= (int) $u['id'] ?>">ご住所</label>
        <input type="text" id="user_address_<?= (int) $u['id'] ?>" name="user[<?= (int) $u['id'] ?>][address]" value="<?= h((string) $u['address']) ?>">
        <label for="user_birthdate_<?= (int) $u['id'] ?>">生年月日</label>
        <input type="date" id="user_birthdate_<?= (int) $u['id'] ?>" name="user[<?= (int) $u['id'] ?>][birthdate]" value="<?= h((string) $u['birthdate']) ?>">
        <label>性別</label>
        <div class="hint">任意です。会話の話し方・言葉選びを合わせる参考にします</div>
        <div class="radio-group">
          <label><input type="radio" name="user[<?= (int) $u['id'] ?>][gender]" value="male" <?= $u['gender'] === 'male' ? 'checked' : '' ?>> 男性</label>
          <label><input type="radio" name="user[<?= (int) $u['id'] ?>][gender]" value="female" <?= $u['gender'] === 'female' ? 'checked' : '' ?>> 女性</label>
          <label><input type="radio" name="user[<?= (int) $u['id'] ?>][gender]" value="" <?= $u['gender'] === null ? 'checked' : '' ?>> 回答しない</label>
        </div>
      </div>
    <?php endforeach; ?>

    <button type="submit">この内容で保存する</button>
  </form>
</div>
    <?php
    Layout::renderFooter();
}

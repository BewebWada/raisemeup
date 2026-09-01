<?php
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/FamilyAccountRepository.php';
require_once __DIR__ . '/../src/UserRepository.php';
require_once __DIR__ . '/../src/PlanRepository.php';
require_once __DIR__ . '/../src/SummaryRepository.php';
require_once __DIR__ . '/../src/RiskEventRepository.php';
require_once __DIR__ . '/../src/FamilyThemeRepository.php';
require_once __DIR__ . '/../src/DocumentTextRepository.php';
require_once __DIR__ . '/../src/MedicationLogRepository.php';
require_once __DIR__ . '/../src/SubscriptionRepository.php';
require_once __DIR__ . '/../src/StripeClient.php';
require_once __DIR__ . '/../src/CancellationService.php';
require_once __DIR__ . '/../src/LineLoginClient.php';
require_once __DIR__ . '/../src/LineClient.php';
require_once __DIR__ . '/../src/FriendConfirmationService.php';
require_once __DIR__ . '/../src/Layout.php';
require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');
Session::start();

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
$familyRepo = new FamilyAccountRepository($pdo);
$userRepo = new UserRepository($pdo);
$familyThemeRepo = new FamilyThemeRepository($pdo);
$documentTextRepo = new DocumentTextRepository($pdo);
$medicationLogRepo = new MedicationLogRepository($pdo);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// マイページの表示・ご家族とのやり取りでは、会話用の呼び名(display_name)ではなく
// ご家族が登録した氏名(full_name)を優先する。氏名が未登録の間だけ呼び名にフォールバックする
function userFamilyFacingName(array $u): string
{
    return (string) ($u['full_name'] ?: $u['display_name'] ?: '');
}

// カード登録(mypage_billing.phpのpayment_method_updateフロー)から戻ってきた際、まだStripe側に
// 契約が存在しないこの家族のtrial/trial_expired契約に対して、たった今登録されたカードで実際の
// Stripe Subscriptionを作成する。1家族に複数利用者がいる場合は全員分まとめて処理する。
// 個別の失敗はログに残すだけで処理を止めない(カードが結局登録されていなかった等の場合は次回再試行される)
function attachPendingStripeSubscriptions(PDO $pdo, array $family, SubscriptionRepository $subscriptionRepo): void
{
    $pending = $subscriptionRepo->findUnattachedForFamily((int) $family['id']);
    if (empty($pending)) {
        return;
    }

    $stripe = new StripeClient(Config::get('STRIPE_SECRET_KEY', ''));
    $planStmt = $pdo->prepare('SELECT stripe_price_id FROM plans WHERE id = ?');

    foreach ($pending as $sub) {
        $planStmt->execute([$sub['plan_id']]);
        $stripePriceId = $planStmt->fetchColumn();
        if (empty($stripePriceId)) {
            continue;
        }

        // 無料期間がまだ残っていればその残り日数をtrial_period_daysとして引き継ぎ、
        // 既に無料期間が終わっている(trial_expired)場合は0を渡して即時課金する
        $remainingSeconds = (new DateTime($sub['trial_ends_at']))->getTimestamp() - time();
        $trialDays = $remainingSeconds > 0 ? (int) ceil($remainingSeconds / 86400) : 0;

        try {
            $stripeSub = $stripe->createSubscription(
                (string) $family['stripe_customer_id'],
                (string) $stripePriceId,
                $trialDays,
                ['family_account_id' => (string) $family['id'], 'subscription_id' => (string) $sub['id']]
            );
            $subscriptionRepo->attachStripeSubscription((int) $sub['id'], $stripeSub['id']);
        } catch (Throwable $e) {
            error_log("Stripe subscription creation failed after card registration (subscription {$sub['id']}): " . $e->getMessage());
        }
    }
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

// mypage_billing.php(payment_method_updateフロー)からの戻り。ポータル自体はカードをCustomerに
// 登録するだけでSubscriptionは作らないため、たった今登録されたカードを使ってこの家族の未課金の
// trial/trial_expired契約に対し、ここで実際のStripe Subscriptionを作成する
if (($_GET['card_registered'] ?? '') === '1' && !empty($family['stripe_customer_id'])) {
    attachPendingStripeSubscriptions($pdo, $family, new SubscriptionRepository($pdo));
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
$savedFamily = false;
$savedUserId = 0;
$themeSavedUserId = 0;
$friendCheckedUserId = 0;
$cancelledUserId = 0;
$familyCancelledAll = false;
// タブ切り替え(CSSのみ)後の再表示で、保存/追加した内容が見えているタブをそのまま維持するための着地先
$activeTab = 'family';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');

    if (!hash_equals($_SESSION['mypage_csrf_token'], $token)) {
        $errors[] = 'フォームの有効期限が切れました。お手数ですがもう一度お試しください。';
    } elseif ($action === 'add_theme') {
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
            $activeTab = 'user-' . $themeUserId;
        } elseif (!in_array($themePlanCode->fetchColumn(), RISK_REPORT_PLAN_CODES, true)) {
            $errors[] = 'テーマ設定は、寄り添いスタンダード以上のプランでご利用いただけます。';
            $activeTab = 'user-' . $themeUserId;
        } else {
            $familyThemeRepo->add($themeUserId, (int) $family['id'], $themeText);
            $themeSavedUserId = $themeUserId;
            $activeTab = 'user-' . $themeUserId;
        }
    } elseif ($action === 'delete_theme') {
        $themeUserId = (int) ($_POST['theme_user_id'] ?? 0);
        $familyThemeRepo->cancel((int) ($_POST['theme_id'] ?? 0), (int) $family['id']);
        $activeTab = 'user-' . $themeUserId;
    } elseif ($action === 'delete_document_text') {
        $documentUserId = (int) ($_POST['document_user_id'] ?? 0);
        if (!in_array($documentUserId, $ownedUserIds, true)) {
            $errors[] = '不正な操作です。';
        } else {
            $documentTextRepo->delete((int) ($_POST['document_text_id'] ?? 0), $documentUserId);
        }
        $activeTab = 'user-' . $documentUserId;
    } elseif ($action === 'update_family') {
        $familyName = trim((string) ($_POST['family_name'] ?? ''));
        $familyEmail = trim((string) ($_POST['family_email'] ?? ''));
        $familyPhone = preg_replace('/[^0-9]/', '', (string) ($_POST['family_phone'] ?? ''));
        $activeTab = 'family';

        if ($familyName === '') {
            $errors[] = 'お名前を入力してください。';
        }
        if ($familyPhone !== '' && !in_array(strlen($familyPhone), [10, 11], true)) {
            $errors[] = '電話番号は10〜11桁の数字で入力してください。';
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
            $family = $familyRepo->find((int) $family['id']);
            $savedFamily = true;
        }
    } elseif ($action === 'update_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $activeTab = 'user-' . $userId;

        if (!in_array($userId, $ownedUserIds, true)) {
            $errors[] = '不正な操作です。';
        } else {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $phone = preg_replace('/[^0-9]/', '', (string) ($_POST['phone'] ?? ''));
            $zip = preg_replace('/[^0-9]/', '', (string) ($_POST['zip'] ?? ''));
            $address = trim((string) ($_POST['address'] ?? ''));
            $birthdate = trim((string) ($_POST['birthdate'] ?? ''));
            $relation = trim((string) ($_POST['relation'] ?? ''));
            $gender = trim((string) ($_POST['gender'] ?? ''));

            if ($birthdate !== '') {
                $d = DateTime::createFromFormat('Y-m-d', $birthdate);
                if (!$d || $d->format('Y-m-d') !== $birthdate) {
                    $birthdate = '';
                }
            }
            if (!in_array($gender, ['male', 'female'], true)) {
                $gender = '';
            }
            if ($phone !== '' && !in_array(strlen($phone), [10, 11], true)) {
                $errors[] = '電話番号は10〜11桁の数字で入力してください。';
            }

            if (empty($errors)) {
                $userRepo->update($userId, [
                    'full_name' => $fullName,
                    'phone' => $phone,
                    'postal_code' => $zip,
                    'address' => $address,
                    'birthdate' => $birthdate,
                    'gender' => $gender,
                ]);

                $pdo->prepare(
                    'UPDATE user_family_links SET relation = ? WHERE user_id = ? AND family_account_id = ?'
                )->execute([$relation ?: null, $userId, (int) $family['id']]);

                $savedUserId = $userId;
            }
        }
    } elseif ($action === 'check_friend_family') {
        $activeTab = 'family';

        if ($family['line_user_id'] === null) {
            $errors[] = '不正な操作です。';
        } elseif ($family['friend_confirmed_at'] === null) {
            $familyLineClient = new LineClient(Config::get('LINE_FAMILY_CHANNEL_SECRET'), Config::get('LINE_FAMILY_CHANNEL_ACCESS_TOKEN'));
            if ($familyLineClient->getProfile($family['line_user_id']) !== null) {
                FriendConfirmationService::confirmFamily($familyRepo, $familyLineClient, $family);
                $family = $familyRepo->find((int) $family['id']);
                $savedFamily = true;
            } else {
                $errors[] = '友だち追加がまだ確認できませんでした。「TAYORIサポート」を友だち追加のうえ、もう一度お試しください。';
            }
        }
    } elseif ($action === 'check_friend_user') {
        $checkUserId = (int) ($_POST['user_id'] ?? 0);
        $activeTab = 'user-' . $checkUserId;

        if (!in_array($checkUserId, $ownedUserIds, true)) {
            $errors[] = '不正な操作です。';
        } else {
            $checkUser = $userRepo->find($checkUserId);
            if ($checkUser === null || $checkUser['line_user_id'] === null) {
                $errors[] = '不正な操作です。';
            } elseif ($checkUser['friend_confirmed_at'] === null) {
                $userLineClient = new LineClient(Config::get('LINE_CHANNEL_SECRET'), Config::get('LINE_CHANNEL_ACCESS_TOKEN'));
                if ($userLineClient->getProfile($checkUser['line_user_id']) !== null) {
                    if ($checkUser['status'] === 'pending') {
                        FriendConfirmationService::confirmUser($pdo, $userRepo, $userLineClient, $checkUser);
                    } else {
                        FriendConfirmationService::reconnectUser($userRepo, $userLineClient, $checkUser);
                    }
                    $friendCheckedUserId = $checkUserId;
                } else {
                    $errors[] = '友だち追加がまだ確認できませんでした。ご本人に友だち追加をお願いのうえ、もう一度お試しください。';
                }
            }
        }
    } elseif ($action === 'cancel_user') {
        $cancelUserId = (int) ($_POST['user_id'] ?? 0);
        $activeTab = 'user-' . $cancelUserId;

        if (!in_array($cancelUserId, $ownedUserIds, true)) {
            $errors[] = '不正な操作です。';
        } else {
            try {
                $cancellationService = new CancellationService(
                    $pdo,
                    new StripeClient(Config::get('STRIPE_SECRET_KEY', '')),
                    new SubscriptionRepository($pdo),
                    $userRepo,
                    $familyRepo,
                    new LineClient(Config::get('LINE_CHANNEL_SECRET'), Config::get('LINE_CHANNEL_ACCESS_TOKEN')),
                    MailClient::fromConfig()
                );
                $cancellationService->requestUserCancellation($cancelUserId, (int) $family['id']);
                $cancelledUserId = $cancelUserId;
            } catch (Throwable $e) {
                error_log('mypage user cancellation failed: ' . $e->getMessage());
                $errors[] = '解約処理に失敗しました。お手数ですが時間をおいて再度お試しいただくか、support@tayori-net.jpまでご連絡ください。';
            }
        }
    } elseif ($action === 'cancel_family_all') {
        $activeTab = 'family';

        try {
            $cancellationService = new CancellationService(
                $pdo,
                new StripeClient(Config::get('STRIPE_SECRET_KEY', '')),
                new SubscriptionRepository($pdo),
                $userRepo,
                $familyRepo,
                new LineClient(Config::get('LINE_CHANNEL_SECRET'), Config::get('LINE_CHANNEL_ACCESS_TOKEN')),
                MailClient::fromConfig()
            );
            foreach ($ownedUserIds as $ownedUserId) {
                $cancellationService->requestUserCancellation($ownedUserId, (int) $family['id']);
            }
            $familyCancelledAll = true;
        } catch (Throwable $e) {
            error_log('mypage family cancellation failed: ' . $e->getMessage());
            $errors[] = '解約処理に失敗しました。お手数ですが時間をおいて再度お試しいただくか、support@tayori-net.jpまでご連絡ください。';
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

// ご利用者様のLINE連携状況(申込完了画面と同じ判定・同じURL生成方法)。未連携の場合は
// マイページからも連携用URLをコピーして送り直せるようにする
$baseUrl = rtrim(Config::get('APP_BASE_URL', ''), '/');
$addFriendUrl = Config::get('LINE_ADD_FRIEND_URL', '');
$familyAddFriendUrl = Config::get('LINE_FAMILY_ADD_FRIEND_URL', '');

$panels = [];
foreach ($linkedUsers as $u) {
    $subStmt = $pdo->prepare(
        'SELECT s.status, s.trial_ends_at, s.current_period_end, s.payment_customer_ref, s.cancel_at,
                p.code AS plan_code, p.name AS plan_name, p.price_yen
         FROM subscriptions s JOIN plans p ON p.id = s.plan_id
         WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 1'
    );
    $subStmt->execute([(int) $u['id']]);

    $talkCountStmt->execute([(int) $u['id']]);

    $userLineLinked = $u['line_user_id'] !== null;
    $userLoginUrl = null;
    if (!$userLineLinked && Config::get('LINE_LOGIN_CHANNEL_ID', '') !== '') {
        $lineLoginClient = new LineLoginClient(Config::get('LINE_LOGIN_CHANNEL_ID', ''), Config::get('LINE_LOGIN_CHANNEL_SECRET', ''));
        $userLoginUrl = $lineLoginClient->buildAuthorizeUrl($baseUrl . '/line_login_callback.php', (string) $u['invite_code']);
    }

    $panels[] = [
        'user' => $u,
        'subscription' => $subStmt->fetch(PDO::FETCH_ASSOC) ?: null,
        'summaries' => $summaryRepo->getAllForUser((int) $u['id']),
        'riskEvents' => $riskRepo->getRecentForUser((int) $u['id'], 10),
        'todayTalkCount' => (int) $talkCountStmt->fetchColumn(),
        'themes' => $familyThemeRepo->getActiveForUser((int) $u['id']),
        'documentTexts' => $documentTextRepo->getActiveForUser((int) $u['id']),
        'medicationLogs' => $medicationLogRepo->getRecentForUser((int) $u['id']),
        'userLineLinked' => $userLineLinked,
        'userFriendConfirmed' => $u['status'] !== 'pending' && $u['friend_confirmed_at'] !== null,
        'userLoginUrl' => $userLoginUrl,
        'addFriendUrl' => $addFriendUrl,
    ];
}

// POSTでアクティブタブの指定が無かった場合(=通常のGET表示)は、家族自身の情報より
// 利用者様の様子を確認したいはずなので、いる場合は1人目の利用者タブをデフォルトにする
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($panels)) {
    $activeTab = 'user-' . (int) $panels[0]['user']['id'];
}

// カード未登録(=支払いが確定していない)契約が1件でもあれば、マイページ全体でアラートを出す。
// payment_customer_refはStripeでCheckoutが完了して初めて入る値なので、これが無い間は
// 無料期間終了後に自動課金されず利用停止につながる(check_subscriptions.phpのtrial_expired判定と同じ基準)
$familyCardMissing = false;
foreach ($panels as $panel) {
    $sub = $panel['subscription'];
    if ($sub !== null && in_array($sub['status'], ['trial', 'trial_expired'], true) && empty($sub['payment_customer_ref'])) {
        $familyCardMissing = true;
        break;
    }
}

renderMypage($family, $panels, $errors, $savedFamily, $savedUserId, $themeSavedUserId, $friendCheckedUserId, $cancelledUserId, $familyCancelledAll, $activeTab, $familyAddFriendUrl, $familyCardMissing);

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

/**
 * @param array<int, array{user: array, subscription: ?array, summaries: array, riskEvents: array, todayTalkCount: int, themes: array}> $panels
 */
function renderMypage(array $family, array $panels, array $errors, bool $savedFamily, int $savedUserId, int $themeSavedUserId, int $friendCheckedUserId, int $cancelledUserId, bool $familyCancelledAll, string $activeTab, string $familyAddFriendUrl, bool $familyCardMissing): void
{
    Layout::renderHeader('mypage', 'マイページ');

    // タブの並び: 先頭が「ご家族について」、続けて利用者様ごと。表示名が未取得(LINE連携前)の場合は
    // 複数人いると見分けがつかなくなるため、その場合だけ「(n人目)」を補って区別する
    $tabs = [['key' => 'family', 'label' => 'ご家族について']];
    foreach ($panels as $i => $panel) {
        $u = $panel['user'];
        $name = userFamilyFacingName($u);
        $label = $name !== ''
            ? $name . '様'
            : 'ご利用者様' . (count($panels) > 1 ? '(' . ($i + 1) . '人目)' : '');
        $tabs[] = ['key' => 'user-' . (int) $u['id'], 'label' => $label];
    }
    ?>
<style>
  .mp-shell { max-width:760px; margin:0 auto; }
  .top-bar { display:flex; justify-content:space-between; align-items:baseline; max-width:760px; margin:0 auto 16px; }
  .top-bar a { display:inline-flex; align-items:center; gap:6px; font-size:0.9rem; text-decoration:none; color:var(--text-muted); }
  .top-bar a:hover { color:var(--text); }
  .card { background:var(--surface); border-radius:var(--radius-lg); padding:26px 24px; box-shadow:0 2px 12px rgba(61,58,53,0.08); margin-bottom:18px; }
  .card h2 { display:flex; align-items:center; gap:8px; font-size:1.05rem; margin:0 0 16px; color:var(--brand-dark); }
  .card h2 .icon { color:var(--brand); }
  .card h3 { font-size:0.95rem; margin:0 0 4px; color:var(--text); }
  .errors { background:#fdecea; border:1px solid #f5b0a8; color:#a12a1f; padding:12px 16px; border-radius:12px; margin-bottom:16px; }
  .notice { background:var(--card-mint); border:1px solid #cfdbc4; color:var(--brand-dark); padding:12px 16px; border-radius:12px; margin-bottom:16px; font-weight:bold; }
  label { display:block; font-weight:bold; margin:14px 0 4px; font-size:0.92rem; color:var(--text); }
  input[type=text], input[type=email], input[type=tel], input[type=date] {
    width:100%; box-sizing:border-box; padding:10px 12px; font-size:1rem; border:1px solid #ddd6c7; border-radius:10px; background:#fffdf8;
  }
  input:focus { outline:2px solid var(--brand); outline-offset:1px; }
  .zip-input { width:10em !important; }
  .radio-group { display:flex; gap:16px; flex-wrap:wrap; margin:2px 0 4px; }
  .radio-group label { display:flex; align-items:baseline; gap:6px; font-weight:normal; margin:0; }
  .hint { font-size:0.85rem; color:var(--text-muted); margin-top:2px; }
  button, .mp-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; margin-top:20px; padding:12px 26px; font-size:0.95rem; font-weight:bold; background:var(--brand); color:#fff; border:none; border-radius:var(--radius-pill); cursor:pointer; text-decoration:none; transition:background 0.15s; }
  button:hover, .mp-btn:hover { background:var(--brand-dark); }
  .mp-btn .icon { width:1.2em; height:1.2em; }
  .status-box { background:var(--card-mint); border-radius:14px; padding:16px 20px; margin-bottom:16px; }
  .status-box dt { font-size:0.82rem; color:var(--brand-dark); opacity:0.75; margin-top:8px; }
  .status-box dt:first-child { margin-top:0; }
  .status-box dd { margin:2px 0 0; font-weight:bold; color:var(--brand-dark); }
  .summary-item { margin-bottom:12px; }
  .summary-item .label { font-size:0.8rem; color:var(--text-muted); font-weight:bold; }
  .summary-item p { margin:2px 0 0; line-height:1.6; }
  .risk-item { border:1px solid #eee; border-radius:12px; padding:10px 14px; margin-bottom:8px; font-size:0.9rem; }
  .risk-item .level-high { color:#a12a1f; font-weight:bold; }
  .risk-item .level-medium { color:#a9711e; font-weight:bold; }
  .risk-item .level-low { color:var(--text-muted); font-weight:bold; }
  .risk-item .date { color:#999; font-size:0.8rem; }
  .scroll-list { max-height:280px; overflow-y:auto; padding-right:2px; }
  .empty-hint { color:#999; font-size:0.9rem; }
  .copy-box { display:flex; gap:8px; margin:12px 0; flex-wrap:wrap; }
  .copy-box input.copy-input { flex:1 1 200px; min-width:0; padding:10px 12px; font-size:0.82rem; border:1px solid #ddd6c7; border-radius:10px; background:#fffdf8; color:#555; }
  .copy-box button.copy-btn { width:auto; flex:0 0 auto; margin-top:0; padding:10px 16px; }
  .handoff-guide { list-style:none; margin:14px 0 0; padding:0; display:flex; flex-direction:column; gap:8px; }
  .handoff-guide li { display:flex; align-items:center; gap:10px; font-size:0.88rem; }
  .guide-icon { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:50%; background:var(--card-mint); color:var(--brand); flex-shrink:0; }
  .qr-box { text-align:center; margin-top:12px; }
  .qr-box img { width:140px; height:140px; border:1px solid #eee; border-radius:8px; padding:8px; background:#fff; }
  .code-box { background:var(--card-mint); border:1px solid #cfdbc4; border-radius:10px; padding:14px; margin:10px 0; text-align:center; }
  .code-box .code { font-size:1.5rem; font-weight:bold; letter-spacing:0.15em; color:var(--brand-dark); }
  details.fallback { margin-top:12px; font-size:0.88rem; }
  details.fallback summary { cursor:pointer; color:var(--brand); }
  .theme-item { display:flex; align-items:center; gap:10px; border:1px solid #eee; border-radius:12px; padding:10px 14px; margin-bottom:8px; font-size:0.9rem; }
  .theme-item .theme-text { flex:1; }
  .theme-item .theme-expiry { color:#999; font-size:0.8rem; white-space:nowrap; }
  .theme-delete-form { margin:0; }
  .theme-delete-btn { margin:0; padding:6px 14px; font-size:0.8rem; background:#eee; color:#555; }
  .theme-delete-btn:hover { background:#ddd; }
  .cancel-trigger-btn { display:inline-block; margin-top:14px; padding:7px 16px; font-size:0.82rem; font-weight:normal; background:none; border:1px solid #d9a6a0; color:#a12a1f; border-radius:var(--radius-pill); cursor:pointer; }
  .cancel-trigger-btn:hover { background:#fdecea; }
  .cancel-confirm-btn { margin-top:0; padding:10px 20px; font-size:0.9rem; background:#a12a1f; }
  .cancel-confirm-btn:hover { background:#7d1f17; }
  .cancel-dismiss-btn { margin-top:0; padding:10px 20px; font-size:0.9rem; background:#eee; color:#555; }
  .cancel-dismiss-btn:hover { background:#ddd; }
  .cancel-schedule-notice { color:#a12a1f; font-size:0.88rem; margin-top:8px; }
  .doc-detail-btn { display:block; justify-content:flex-start; margin:0; padding:0; background:none; border:none; color:var(--text); font:inherit; text-align:left; text-decoration:underline; text-underline-offset:2px; cursor:pointer; flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .doc-detail-btn:hover { color:var(--brand-dark); background:none; }
  dialog.doc-dialog { position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); margin:0; max-width:520px; max-height:80vh; width:90vw; overflow-y:auto; border:none; border-radius:var(--radius-lg); padding:24px; box-shadow:0 8px 32px rgba(0,0,0,0.2); }
  dialog.doc-dialog::backdrop { background:rgba(61,58,53,0.45); }
  dialog.doc-dialog h3 { margin:0 0 4px; font-size:0.95rem; color:var(--brand-dark); }
  dialog.doc-dialog .doc-dialog-date { color:#999; font-size:0.82rem; margin-bottom:14px; }
  dialog.doc-dialog .doc-dialog-text { white-space:pre-wrap; line-height:1.7; font-size:0.95rem; margin:0 0 20px; }
  dialog.doc-dialog .doc-dialog-close { margin-top:0; padding:10px 24px; }
  .theme-add-form { display:flex; gap:8px; margin-top:12px; align-items:center; }
  .theme-add-form input[type=text] { flex:1; margin:0; }
  .theme-add-form button { margin:0; padding:11px 22px; font-size:0.9rem; white-space:nowrap; }

  /* --- タブ(ラジオボタンのみで開閉するCSSタブ、JS不要) --- */
  .mp-tab-radio { position:absolute; opacity:0; pointer-events:none; }
  .mp-tabbar { display:flex; flex-wrap:wrap; border-bottom:2px solid #e3ddc9; margin-bottom:20px; }
  .mp-tabbar label {
    display:inline-flex; align-items:center; margin:0 4px -2px 0; padding:12px 20px;
    background:transparent; color:var(--text-muted); font-weight:bold; font-size:0.92rem;
    border-radius:10px 10px 0 0; border-bottom:3px solid transparent;
    cursor:pointer; transition:background 0.15s, color 0.15s, border-color 0.15s;
  }
  .mp-tabbar label:hover { background:var(--card-mint); color:var(--brand-dark); }
  .mp-panel { display:none; }
  <?php foreach ($tabs as $i => $tab): ?>
  #mp-tab-<?= $i ?>:checked ~ .mp-tabbar label[for="mp-tab-<?= $i ?>"] {
    background:var(--card-mint); color:var(--brand-dark); border-bottom-color:var(--brand);
  }
  #mp-tab-<?= $i ?>:checked ~ .mp-panels #mp-panel-<?= $i ?> { display:block; }
  <?php endforeach; ?>

  @media (max-width: 600px) {
    .mp-tabbar label { padding:9px 14px; font-size:0.85rem; }
  }
</style>

<div class="top-bar">
  <span>ようこそ、<?= h($family['name']) ?>様</span>
  <a href="/mypage_logout.php"><?= Layout::icon('logout') ?> ログアウト</a>
</div>

<div class="mp-shell">

<?php if (!empty($errors)): ?>
  <div class="card"><div class="errors"><?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?></div></div>
<?php endif; ?>

<?php if ($familyCardMissing): ?>
  <div class="card">
    <div class="errors" style="display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
      <span>まだお支払い方法(クレジットカード)が登録されていません。無料期間終了後もそのままご利用いただくために、お早めにご登録をお願いします。</span>
      <?php if (!empty($family['stripe_customer_id'])): ?>
        <a class="mp-btn" href="/mypage_billing.php?flow=payment_method_update" style="margin-top:0; white-space:nowrap;"><?= Layout::icon('card') ?> カードを登録する</a>
      <?php endif; ?>
    </div>
    <?php if (!empty($family['stripe_customer_id'])): ?>
      <div class="hint">ボタンを押すと、決済代行会社Stripeのカード入力画面に直接移動します。カード番号などを入力して保存すると、この画面に戻ります。</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php foreach ($tabs as $i => $tab): ?>
  <input type="radio" name="mp-view" id="mp-tab-<?= $i ?>" class="mp-tab-radio" <?= $tab['key'] === $activeTab ? 'checked' : '' ?>>
<?php endforeach; ?>

<div class="mp-tabbar">
  <?php foreach ($tabs as $i => $tab): ?>
    <label for="mp-tab-<?= $i ?>"><?= h($tab['label']) ?></label>
  <?php endforeach; ?>
</div>

<div class="mp-panels">
  <div class="mp-panel" id="mp-panel-0">
    <?php renderFamilyPanel($family, $savedFamily, $familyAddFriendUrl, $familyCardMissing, $familyCancelledAll, $panels); ?>
  </div>

  <?php foreach ($panels as $i => $panel): ?>
    <div class="mp-panel" id="mp-panel-<?= $i + 1 ?>">
      <?php renderUserPanel($panel, $family, $savedUserId, $themeSavedUserId, $friendCheckedUserId, $cancelledUserId); ?>
    </div>
  <?php endforeach; ?>
</div>

</div>
<script>
(function () {
  document.querySelectorAll('.copy-btn').forEach(function (btn) {
    var defaultLabel = btn.textContent;
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-copy-target'));
      if (!input) { return; }
      var markCopied = function () {
        btn.textContent = 'コピーしました';
        setTimeout(function () { btn.textContent = defaultLabel; }, 2000);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(input.value).then(markCopied).catch(function () {
          input.select();
          document.execCommand('copy');
          markCopied();
        });
      } else {
        input.select();
        document.execCommand('copy');
        markCopied();
      }
    });
  });

  document.querySelectorAll('.doc-detail-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var dialog = document.getElementById(btn.getAttribute('data-dialog-target'));
      if (dialog) { dialog.showModal(); }
    });
  });
})();
</script>
    <?php
    Layout::renderFooter();
}

function renderFamilyPanel(array $family, bool $savedFamily, string $familyAddFriendUrl, bool $cardMissing, bool $familyCancelledAll, array $panels): void
{
    $familyFriendConfirmed = $family['line_user_id'] !== null && $family['friend_confirmed_at'] !== null;
    $familyFriendBroken = $family['line_user_id'] !== null && $family['friend_confirmed_at'] === null;
    $hasUsers = !empty($panels);
    // 利用者ごとの解約ボタンと同じ基準(canCancel、renderUserPanel参照)で、全員が既に解約予約・解約済みかを判定する。
    // 1人でもまだ解約前の利用者が残っていれば「すべて解約する」ボタンを出す
    $allFamilyCancelled = $hasUsers;
    foreach ($panels as $panel) {
        $sub = $panel['subscription'];
        $alreadyCancelling = $sub !== null && (in_array($sub['status'], ['cancelled', 'abandoned'], true) || !empty($sub['cancel_at']));
        if (!$alreadyCancelling) {
            $allFamilyCancelled = false;
            break;
        }
    }
    ?>
  <?php if ($savedFamily): ?>
    <div class="notice">登録情報を更新しました。</div>
  <?php endif; ?>
  <?php if ($familyCancelledAll): ?>
    <div class="notice">解約手続きを受け付けました。各ご利用者様は、それぞれの現在のお支払い期間の終了をもってご利用終了となります。</div>
  <?php endif; ?>

  <div class="card">
    <h2><?= Layout::icon('chat') ?> 「TAYORIサポート」との連携</h2>
    <?php if ($familyFriendConfirmed): ?>
      <dl class="status-box">
        <dt>連携状況</dt>
        <dd>連携済み</dd>
      </dl>
      <p class="empty-hint">無料期間の終了案内や、気になる会話の検知結果は、こちらの「TAYORIサポート」からお届けします。</p>
    <?php elseif ($familyFriendBroken): ?>
      <p class="empty-hint" style="color:#a12a1f;">「TAYORIサポート」の友だち追加が確認できていません(ブロック等により、お支払いのご案内や見守りの通知が届かなくなっている可能性があります)。下記から友だち追加のうえ、確認ボタンを押してください。</p>
      <?php if ($familyAddFriendUrl !== ''): ?>
        <a class="mp-btn" href="<?= h($familyAddFriendUrl) ?>"><?= Layout::icon('chat') ?> 「TAYORIサポート」を友だち追加する</a>
        <div class="qr-box">
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=<?= urlencode($familyAddFriendUrl) ?>" alt="友だち追加QRコード" width="160" height="160">
        </div>
      <?php endif; ?>
      <form method="post" action="/mypage/">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['mypage_csrf_token']) ?>">
        <input type="hidden" name="action" value="check_friend_family">
        <button type="submit"><?= Layout::icon('check') ?> 今すぐ確認する</button>
      </form>
    <?php else: ?>
      <p class="empty-hint">「TAYORIサポート」との連携が完了していません。お申込み時にご案内したLINE連携をお済ませください。</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2><?= Layout::icon('edit') ?> お申込者様(ご家族)について</h2>
    <form method="post" action="/mypage/">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['mypage_csrf_token']) ?>">
      <input type="hidden" name="action" value="update_family">

      <label for="family_name">お名前</label>
      <input type="text" id="family_name" name="family_name" value="<?= h($family['name']) ?>" required>
      <label for="family_email">メールアドレス</label>
      <input type="email" id="family_email" name="family_email" value="<?= h((string) $family['email']) ?>">
      <label for="family_phone">電話番号</label>
      <input type="tel" id="family_phone" name="family_phone" value="<?= h((string) $family['phone']) ?>">

      <button type="submit">この内容で保存する</button>
    </form>
  </div>

  <div class="card">
    <h2><?= Layout::icon('card') ?> お支払い</h2>
    <?php if (!empty($family['stripe_customer_id'])): ?>
      <?php if ($cardMissing): ?>
        <p class="empty-hint" style="color:#a12a1f;">まだクレジットカードが登録されていません。無料期間終了後もそのままご利用いただくために、お早めのご登録をお願いします。</p>
        <a class="mp-btn" href="/mypage_billing.php?flow=payment_method_update"><?= Layout::icon('card') ?> カードを登録する</a>
        <div class="hint">ボタンを押すと、決済代行会社Stripeのカード入力画面に直接移動します。カード番号などを入力して保存すると、この画面に戻ります。</div>
      <?php else: ?>
        <p class="empty-hint">ご登録の全利用者様分のお支払い方法・請求書をまとめて管理できます。</p>
        <a class="mp-btn" href="/mypage_billing.php"><?= Layout::icon('card') ?> お支払い方法・請求書を管理する</a>
        <div class="hint">ボタンを押すと、決済代行会社Stripeの管理ページに移動します。操作が終わると、この画面に戻ります。</div>
      <?php endif; ?>
    <?php else: ?>
      <p class="empty-hint" style="color:#a12a1f;">お支払い情報は未登録です。お手数ですがsupport@tayori-net.jpまでご連絡ください。</p>
    <?php endif; ?>
  </div>

  <div class="card" style="text-align:center;">
    <a class="mp-btn" href="/mypage_add_user.php"><?= Layout::icon('heart') ?> 利用者を追加する</a>
    <p class="empty-hint" style="margin-top:10px;">もうお一方のご利用者様を、同じご家族アカウントに追加できます。</p>
  </div>

  <?php if ($hasUsers): ?>
  <div class="card">
    <h2><?= Layout::icon('close') ?> 解約(退会)</h2>
    <?php if ($allFamilyCancelled): ?>
      <p class="empty-hint">ご登録の全利用者様について、解約手続きが完了しています。各利用者様は、現在のお支払い期間の終了日をもってご利用終了となります。</p>
    <?php else: ?>
      <p class="empty-hint">
        ご登録の全利用者様のご契約をまとめて解約します。個別の利用者様お一人だけを解約したい場合は、それぞれの利用者様のタブの一番下から解約してください。<br>
        解約されても、各利用者様は現在のお支払い期間の終了日まではこれまで通りご利用いただけます。期間終了日に、TAYORIからそれぞれのご本人へLINEで一言お別れのご挨拶をお送りしたうえで、ご利用を終了いたします。
        いただいたお支払いの日割り返金は行っておりません。
      </p>
      <button type="button" class="cancel-trigger-btn" onclick="document.getElementById('cancel-family-dialog').showModal()">すべて解約する</button>
      <dialog class="doc-dialog" id="cancel-family-dialog">
        <h3>本当にすべて解約しますか?</h3>
        <p style="line-height:1.7;">
          ご登録の全利用者様のご利用を解約します。それぞれ現在のお支払い期間の終了日まではそのままご利用いただけ、期間終了日にTAYORIからご本人へお別れのご挨拶をお送りしたうえでご利用が終了します。
          いただいたお支払いの日割り返金は行われません。この操作は取り消せませんので、あらかじめご了承のうえお進みください。
        </p>
        <form method="post" action="/mypage/">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['mypage_csrf_token']) ?>">
          <input type="hidden" name="action" value="cancel_family_all">
          <div style="display:flex; gap:10px; margin-top:20px;">
            <button type="submit" class="cancel-confirm-btn">解約する</button>
            <button type="button" onclick="document.getElementById('cancel-family-dialog').close()" class="cancel-dismiss-btn">やめる</button>
          </div>
        </form>
      </dialog>
    <?php endif; ?>
  </div>
  <?php endif; ?>
    <?php
}

function renderUserPanel(array $panel, array $family, int $savedUserId, int $themeSavedUserId, int $friendCheckedUserId, int $cancelledUserId): void
{
    $u = $panel['user'];
    $sub = $panel['subscription'];
    $userId = (int) $u['id'];
    $riskPlan = in_array($sub['plan_code'] ?? null, RISK_REPORT_PLAN_CODES, true);
    $userName = userFamilyFacingName($u) ?: 'ご利用者様';
    $canCancel = $sub !== null && !in_array($sub['status'], ['cancelled', 'abandoned'], true) && empty($sub['cancel_at']);
    ?>
  <?php if ($savedUserId === $userId): ?>
    <div class="notice">登録情報を更新しました。</div>
  <?php endif; ?>
  <?php if ($themeSavedUserId === $userId): ?>
    <div class="notice">テーマを設定しました。</div>
  <?php endif; ?>
  <?php if ($friendCheckedUserId === $userId): ?>
    <div class="notice">友だち追加を確認しました。</div>
  <?php endif; ?>
  <?php if ($cancelledUserId === $userId): ?>
    <div class="notice">解約手続きを受け付けました。現在のお支払い期間の終了をもってご利用終了となります。</div>
  <?php endif; ?>

  <div class="card">
    <h2><?= Layout::icon('card') ?> ご契約状況</h2>
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
      <?php if (!empty($sub['cancel_at'])): ?>
        <p class="cancel-schedule-notice"><?= h((new DateTime($sub['cancel_at']))->format('Y年n月j日')) ?>をもって解約予定です。</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="empty-hint">ご契約情報が見つかりませんでした。</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2><?= Layout::icon('login') ?> LINE連携</h2>
    <?php if ($panel['userFriendConfirmed']): ?>
      <dl class="status-box">
        <dt>連携状況</dt>
        <dd>連携済み</dd>
      </dl>
    <?php elseif ($panel['userLineLinked']): ?>
      <p class="empty-hint" style="color:#a12a1f;">LINEログインは完了していますが、友だち追加が確認できていません(ブロック等により、ご家族への見守り通知が届かなくなっている可能性があります)。ご本人に公式アカウントの友だち追加をお願いし、確認ボタンを押してください。</p>
      <?php if (!empty($panel['addFriendUrl'])): ?>
        <div class="qr-box">
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=<?= urlencode($panel['addFriendUrl']) ?>" alt="友だち追加QRコード" width="160" height="160">
        </div>
        <p class="empty-hint">上記QRをご本人の端末で読み取り、友だち追加をお願いしてください。</p>
      <?php endif; ?>
      <form method="post" action="/mypage/">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['mypage_csrf_token']) ?>">
        <input type="hidden" name="action" value="check_friend_user">
        <input type="hidden" name="user_id" value="<?= $userId ?>">
        <button type="submit"><?= Layout::icon('check') ?> 今すぐ確認する</button>
      </form>
    <?php elseif ($panel['userLoginUrl'] !== null): ?>
      <p class="empty-hint">まだLINE連携が完了していません。下のURLをコピーして、ご本人のスマートフォンに送ってください。</p>
      <div class="copy-box">
        <input type="text" class="copy-input" id="userHandoffUrl-<?= $userId ?>" value="<?= h($panel['userLoginUrl']) ?>" readonly onclick="this.select()">
        <button type="button" class="button copy-btn" data-copy-target="userHandoffUrl-<?= $userId ?>"><?= Layout::icon('copy') ?> URLをコピーする</button>
      </div>
      <ol class="handoff-guide">
        <li><span class="guide-icon"><?= Layout::icon('copy') ?></span><span>上のボタンでURLをコピーする</span></li>
        <li><span class="guide-icon"><?= Layout::icon('chat') ?></span><span>LINEやSMSで、ご本人のスマートフォンに送る</span></li>
        <li><span class="guide-icon"><?= Layout::icon('login') ?></span><span>ご本人がそのURLを開き、LINEでログインする</span></li>
        <li><span class="guide-icon"><?= Layout::icon('check') ?></span><span>友だち追加まで自動で確認されます</span></li>
      </ol>
      <p class="empty-hint">連携が完了次第、こちらのLINEにお知らせします。</p>
      <?php if (!empty($panel['addFriendUrl'])): ?>
        <details class="fallback">
          <summary>URLが開けない場合はこちら</summary>
          <div class="qr-box">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=<?= urlencode($panel['addFriendUrl']) ?>" alt="友だち追加QRコード" width="160" height="160">
          </div>
          <p class="empty-hint">上記QRから友だち追加のうえ、最初のメッセージとして下記のコードをそのまま送信してください。</p>
          <div class="code-box"><div class="code"><?= h((string) $u['invite_code']) ?></div></div>
        </details>
      <?php endif; ?>
    <?php else: ?>
      <p class="empty-hint">LINE連携機能が現在ご利用いただけません。サポートまでお問い合わせください。</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2><?= Layout::icon('shield') ?> 安心レポート</h2>
    <?php if ($riskPlan): ?>
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
    <h2><?= Layout::icon('pill') ?> 服薬状況</h2>
    <?php if (empty($panel['medicationLogs'])): ?>
      <p class="empty-hint">現在、服薬のリマインドは登録されていません。</p>
    <?php else: ?>
      <?php $missedLogs = array_values(array_filter($panel['medicationLogs'], fn($l) => $l['status'] === 'missed')); ?>
      <?php if (empty($missedLogs)): ?>
        <p class="empty-hint">直近<?= MedicationLogRepository::RETENTION_DAYS ?>日間、飲み忘れは確認されていません。</p>
      <?php else: ?>
        <p class="empty-hint" style="color:#a12a1f; margin-bottom:10px;">直近<?= MedicationLogRepository::RETENTION_DAYS ?>日間で、確認が取れなかった服薬が<?= count($missedLogs) ?>件あります。</p>
        <div class="scroll-list">
          <?php foreach ($missedLogs as $log): ?>
            <div class="risk-item">
              <span class="level-medium">飲み忘れの可能性</span>
              <span class="date">(<?= h((new DateTime($log['log_date']))->format('n月j日')) ?> <?= h(substr((string) $log['scheduled_time'], 0, 5)) ?>頃)</span>
              <div><?= h($log['title']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <p class="empty-hint" style="margin-top:10px;">ご本人からLINEで「飲んだ」と返信があった分を確認済みとして記録しています。確認が取れなくても外出中など別の理由のこともあるため、ご心配な場合は直接ご様子をご確認ください。</p>
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
    <?php if ($riskPlan): ?>
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
              <input type="hidden" name="theme_user_id" value="<?= $userId ?>">
              <button type="submit" class="theme-delete-btn">削除</button>
            </form>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <form method="post" action="/mypage/" class="theme-add-form">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['mypage_csrf_token']) ?>">
        <input type="hidden" name="action" value="add_theme">
        <input type="hidden" name="theme_user_id" value="<?= $userId ?>">
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

  <div class="card">
    <h2><?= Layout::icon('check') ?> 読み取った書類の内容</h2>
    <?php if ($riskPlan): ?>
      <?php if (empty($panel['documentTexts'])): ?>
        <p class="empty-hint">まだ読み取った書類はありません。お薬の説明書やお手紙の写真をLINEで送ると、内容がここに保存されます。</p>
      <?php else: ?>
        <?php foreach ($panel['documentTexts'] as $doc): ?>
          <?php $docDialogId = 'doc-dialog-' . (int) $doc['id']; ?>
          <div class="theme-item">
            <button type="button" class="doc-detail-btn" data-dialog-target="<?= h($docDialogId) ?>"><?= h(str_replace("\n", ' ', $doc['extracted_text'])) ?></button>
            <span class="theme-expiry"><?= h((new DateTime($doc['created_at']))->format('n月j日')) ?></span>
            <form method="post" action="/mypage/" class="theme-delete-form">
              <input type="hidden" name="csrf_token" value="<?= h($_SESSION['mypage_csrf_token']) ?>">
              <input type="hidden" name="action" value="delete_document_text">
              <input type="hidden" name="document_text_id" value="<?= (int) $doc['id'] ?>">
              <input type="hidden" name="document_user_id" value="<?= $userId ?>">
              <button type="submit" class="theme-delete-btn">削除</button>
            </form>
          </div>
          <dialog class="doc-dialog" id="<?= h($docDialogId) ?>">
            <h3>読み取った書類の内容</h3>
            <div class="doc-dialog-date"><?= h((new DateTime($doc['created_at']))->format('Y年n月j日 H:i')) ?></div>
            <p class="doc-dialog-text"><?= h($doc['extracted_text']) ?></p>
            <form method="dialog"><button type="submit" class="doc-dialog-close">閉じる</button></form>
          </dialog>
        <?php endforeach; ?>
      <?php endif; ?>
      <p class="empty-hint">写真そのものは保存されず、読み取ったテキストのみを保存しています。不要な内容は削除できます。</p>
    <?php else: ?>
      <p class="empty-hint">原稿の高画質読み取り・内容の保存は、寄り添いスタンダード以上でご利用いただけます。</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2><?= Layout::icon('edit') ?> 登録情報の確認・編集</h2>
    <form method="post" action="/mypage/">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['mypage_csrf_token']) ?>">
      <input type="hidden" name="action" value="update_user">
      <input type="hidden" name="user_id" value="<?= $userId ?>">

      <label for="user_full_name_<?= $userId ?>">氏名</label>
      <input type="text" id="user_full_name_<?= $userId ?>" name="full_name" value="<?= h((string) $u['full_name']) ?>">
      <div class="hint">マイページの表示や、ご家族へのLINE通知で使用します。</div>

      <label>呼び名(会話で使用)</label>
      <?php if ($u['display_name'] !== null && $u['display_name'] !== ''): ?>
        <div class="hint"><?= h($u['display_name']) ?> — 本人との会話の中で確認でき次第、自動的に設定されます。ここからの変更はできません。</div>
      <?php else: ?>
        <div class="hint">まだ本人との会話で確認できていません。会話の中でTAYORIが自然に尋ね、確認でき次第自動的に設定されます。</div>
      <?php endif; ?>

      <label for="relation_<?= $userId ?>">ご利用者様との続柄</label>
      <div class="hint">ご利用者様から見て、ご家族様が何にあたるかをご記入ください(例: 息子、娘、ケアマネージャー)</div>
      <input type="text" id="relation_<?= $userId ?>" name="relation" value="<?= h((string) $u['relation']) ?>">
      <label for="user_phone_<?= $userId ?>">電話番号</label>
      <input type="tel" id="user_phone_<?= $userId ?>" name="phone" value="<?= h((string) $u['phone']) ?>">
      <label for="user_zip_<?= $userId ?>">郵便番号</label>
      <input type="text" id="user_zip_<?= $userId ?>" name="zip" class="zip-input" value="<?= h((string) $u['postal_code']) ?>" inputmode="numeric" maxlength="8">
      <label for="user_address_<?= $userId ?>">ご住所</label>
      <input type="text" id="user_address_<?= $userId ?>" name="address" value="<?= h((string) $u['address']) ?>">
      <label for="user_birthdate_<?= $userId ?>">生年月日</label>
      <input type="date" id="user_birthdate_<?= $userId ?>" name="birthdate" value="<?= h((string) $u['birthdate']) ?>">
      <label>性別</label>
      <div class="hint">任意です。会話の話し方・言葉選びを合わせる参考にします</div>
      <div class="radio-group">
        <label><input type="radio" name="gender" value="male" <?= $u['gender'] === 'male' ? 'checked' : '' ?>> 男性</label>
        <label><input type="radio" name="gender" value="female" <?= $u['gender'] === 'female' ? 'checked' : '' ?>> 女性</label>
        <label><input type="radio" name="gender" value="" <?= $u['gender'] === null ? 'checked' : '' ?>> 回答しない</label>
      </div>

      <button type="submit">この内容で保存する</button>
    </form>
  </div>

  <?php if ($canCancel): ?>
  <div class="card">
    <h2><?= Layout::icon('close') ?> 解約</h2>
    <p class="empty-hint">
      解約されても、現在のお支払い期間の終了日まではこれまで通りご利用いただけます。期間終了日に、TAYORIから<?= h($userName) ?>様へLINEで一言お別れのご挨拶をお送りしたうえで、ご利用を終了いたします。
      いただいたお支払いの日割り返金は行っておりません。
    </p>
    <button type="button" class="cancel-trigger-btn" onclick="document.getElementById('cancel-user-dialog-<?= $userId ?>').showModal()">この利用者様を解約する</button>
    <dialog class="doc-dialog" id="cancel-user-dialog-<?= $userId ?>">
      <h3>本当に解約しますか?</h3>
      <p style="line-height:1.7;">
        <?= h($userName) ?>様のご利用を解約します。現在のお支払い期間の終了日まではそのままご利用いただけ、期間終了日にTAYORIからご本人へお別れのご挨拶をお送りしたうえでご利用が終了します。
        いただいたお支払いの日割り返金は行われません。この操作は取り消せませんので、あらかじめご了承のうえお進みください。
      </p>
      <form method="post" action="/mypage/">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['mypage_csrf_token']) ?>">
        <input type="hidden" name="action" value="cancel_user">
        <input type="hidden" name="user_id" value="<?= $userId ?>">
        <div style="display:flex; gap:10px; margin-top:20px;">
          <button type="submit" class="cancel-confirm-btn">解約する</button>
          <button type="button" onclick="document.getElementById('cancel-user-dialog-<?= $userId ?>').close()" class="cancel-dismiss-btn">やめる</button>
        </div>
      </form>
    </dialog>
  </div>
  <?php endif; ?>
    <?php
}

<?php
// 申込完了画面(LINE連携ステップUI)の描画をapply.php(直後の表示)とapply_resume.php
// (リマインドメールからの再開)の両方から共有するためのファイル。
require_once __DIR__ . '/Layout.php';

const TRIAL_DAYS = 10;

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// $userId/$familyIdだけを受け取り、表示に必要な情報は毎回DBから取得する。
// セッションだけでなく、招待コード経由(apply_resume.php、メールでのリマインド用)からも同じ画面を出せるようにするため
function renderDone(int $userId, int $familyId, PDO $pdo, bool $paymentPending, string $lineError = ''): void
{
    $userRepo = new UserRepository($pdo);
    $familyRepo = new FamilyAccountRepository($pdo);
    $user = $userRepo->find($userId);
    $family = $familyRepo->find($familyId);
    if ($user === null || $family === null) {
        renderExpiredNotice();
        return;
    }
    $userLineLinked = $user['line_user_id'] !== null;
    $familyLineLinked = $family['line_user_id'] !== null;
    // 「連携完了」はLINEログイン(OAuth)だけでなく友だち追加まで確認できて初めて成立する。
    // 友だち追加はfollowイベントで非同期に確認されることがあるため、その場限りのセッションフラッシュではなく
    // DBの永続状態(本人:status、ご家族:friend_confirmed_at)で判定する
    $userFriendConfirmed = $user['status'] !== 'pending';
    $familyFriendConfirmed = $family['friend_confirmed_at'] !== null;

    $stmt = $pdo->prepare(
        'SELECT s.trial_ends_at, p.name AS plan_name, p.price_yen AS plan_price_yen
         FROM subscriptions s JOIN plans p ON p.id = s.plan_id
         WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 1'
    );
    $stmt->execute([$userId]);
    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($subscription === false) {
        renderExpiredNotice();
        return;
    }
    $r = [
        'user_display_name' => $user['display_name'] ?: 'ご利用者',
        'companion_name' => $user['companion_name'] ?: 'たより',
        'plan_name' => $subscription['plan_name'],
        'plan_price_yen' => $subscription['plan_price_yen'],
        'trial_ends_at' => (new DateTime($subscription['trial_ends_at']))->format('Y年n月j日'),
    ];

    $baseUrl = rtrim(Config::get('APP_BASE_URL', ''), '/');

    $userLoginUrl = null;
    if (!$userLineLinked && Config::get('LINE_LOGIN_CHANNEL_ID', '') !== '') {
        $client = new LineLoginClient(Config::get('LINE_LOGIN_CHANNEL_ID', ''), Config::get('LINE_LOGIN_CHANNEL_SECRET', ''));
        $userLoginUrl = $client->buildAuthorizeUrl($baseUrl . '/line_login_callback.php', $user['invite_code']);
    }
    $familyLoginUrl = null;
    if (!$familyLineLinked && Config::get('LINE_FAMILY_LOGIN_CHANNEL_ID', '') !== '') {
        $client = new LineLoginClient(Config::get('LINE_FAMILY_LOGIN_CHANNEL_ID', ''), Config::get('LINE_FAMILY_LOGIN_CHANNEL_SECRET', ''));
        $familyLoginUrl = $client->buildAuthorizeUrl($baseUrl . '/family_line_login_callback.php', $family['invite_code']);
    }

    $addFriendUrl = Config::get('LINE_ADD_FRIEND_URL', '');
    $familyAddFriendUrl = Config::get('LINE_FAMILY_ADD_FRIEND_URL', '');
    $allDone = $userFriendConfirmed && $familyFriendConfirmed;
    Layout::renderHeader('apply', $allDone ? 'ご登録が完了しました' : 'お申込みありがとうございます');
    ?>
<style>
  .card { max-width: 560px; margin: 0 auto; background:#fff; border-radius:12px; padding:28px 24px; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
  .card h1 { font-size:1.3rem; margin-top:0; }
  .errors { background:#fdecea; border:1px solid #f5b0a8; color:#a12a1f; padding:12px 16px; border-radius:8px; margin-bottom:16px; }
  .step { border:1px solid #eee; border-radius:10px; padding:16px 18px; margin-top:16px; }
  .step.done { background:#f4f8f2; border-color:#cfdbc4; }
  .step h3 { margin:0 0 8px; font-size:1rem; }
  .step .badge { display:inline-block; font-size:0.8rem; font-weight:bold; padding:2px 8px; border-radius:10px; margin-left:6px; }
  .step .badge.ok { background:#4B8B5A; color:#fff; }
  .step .badge.warn { background:#e8a33d; color:#fff; }
  .code-box { background:#eef2ea; border:1px solid #cfdbc4; border-radius:8px; padding:16px; margin:12px 0; text-align:center; }
  .code { font-size:1.8rem; font-weight:bold; letter-spacing:0.15em; color:#4B8B5A; }
  a.button { display:flex; align-items:center; justify-content:center; gap:10px; text-align:center; margin-top:12px; padding:12px; background:#06c755; color:#fff; text-decoration:none; border-radius:8px; font-weight:bold; }
  a.button .icon { width:1.3em; height:1.3em; }
  a.button.secondary { background:#4B8B5A; }
  .qr-box { text-align:center; margin-top:12px; }
  .qr-box img { width:160px; height:160px; border:1px solid #eee; border-radius:8px; padding:8px; background:#fff; }
  .hint { font-size:0.9rem; color:#777; margin-top:2px; }
  .optional { margin-top:24px; padding-top:16px; border-top:1px solid #eee; }
  details.fallback { margin-top:14px; font-size:0.9rem; }
  details.fallback summary { cursor:pointer; color:#4B8B5A; }
  .summary-box { background:#f4f8f2; border:1px solid #cfdbc4; border-radius:10px; padding:16px 20px; margin-top:16px; }
  .summary-box dt { font-size:0.85rem; color:#777; margin-top:10px; }
  .summary-box dt:first-child { margin-top:0; }
  .summary-box dd { margin:2px 0 0; font-weight:bold; }
  .done-icon { color:#4B8B5A; }
</style>
<div class="card">
  <?php if ($allDone): ?>
    <h1><span class="done-icon">✓</span> ご登録が完了しました</h1>
    <p>ご本人・ご家族とも、LINE連携まですべて完了しました。ここまでのお申込み内容は以下の通りです。</p>

    <dl class="summary-box">
      <dt>お申込みプラン</dt>
      <dd><?= h($r['plan_name']) ?>(月額<?= number_format((int) $r['plan_price_yen']) ?>円)</dd>
      <dt>無料お試し期間</dt>
      <dd>本日から<?= TRIAL_DAYS ?>日間(<?= h($r['trial_ends_at']) ?>まで)</dd>
      <dt>話し相手の名前</dt>
      <dd><?= h($r['companion_name']) ?></dd>
      <dt>ご利用者様</dt>
      <dd><?= h($r['user_display_name']) ?>様 (LINE連携済み)</dd>
      <dt>ご家族向け通知</dt>
      <dd>連携済み</dd>
    </dl>

    <?php if ($paymentPending): ?>
      <p style="color:#a12a1f; margin-top:16px;">お支払い情報の登録が完了していません。無料期間中はそのままご利用いただけますが、期間終了までにお支払い情報のご登録が必要です。折り返しご案内いたします。</p>
    <?php endif; ?>

    <p style="margin-top:20px;">さっそく<strong><?= h($r['companion_name']) ?></strong>からLINEにメッセージが届いているはずです。ぜひ話しかけてみてください。</p>
  <?php else: ?>
    <h1>お申込みありがとうございます</h1>
    <p><?= h($r['plan_name']) ?>(月額<?= number_format((int) $r['plan_price_yen']) ?>円)を<?= TRIAL_DAYS ?>日間無料でお試しいただけます(<?= h($r['trial_ends_at']) ?>まで)。</p>
    <?php if ($paymentPending): ?>
      <p style="color:#a12a1f;">お支払い情報の登録が完了していません。無料期間中はそのままご利用いただけますが、期間終了までにお支払い情報のご登録が必要です。折り返しご案内いたします。</p>
    <?php endif; ?>

    <p>話し相手の名前は<strong><?= h($r['companion_name']) ?></strong>に決まりました。</p>

    <?php if ($lineError !== ''): ?>
      <div class="errors">LINEとの連携がうまくいきませんでした。お手数ですが、もう一度お試しください。</div>
    <?php endif; ?>

    <p><strong><?= h($r['user_display_name']) ?></strong>様ご本人のスマートフォンで、以下のステップに沿ってLINE連携をお願いします。</p>

    <?php renderLineStep(
      '① ご本人のLINE連携(必須)',
      Config::get('LINE_BOT_DISPLAY_NAME', 'TAYORI'),
      $userLineLinked,
      $userFriendConfirmed,
      $userLoginUrl,
      $addFriendUrl,
      $user['invite_code'] ?? null
    ); ?>

    <?php if ($userFriendConfirmed): ?>
      <div class="optional">
        <p>続いて、ご家族様ご自身のLINEでも連携をお願いします。無料期間終了のお知らせなど、お支払いに関するご連絡はこちらのアカウントからお送りします。</p>
        <?php renderLineStep(
          '② ご家族向け通知の連携(必須)',
          Config::get('LINE_FAMILY_BOT_DISPLAY_NAME', 'TAYORIサポート'),
          $familyLineLinked,
          $familyFriendConfirmed,
          $familyLoginUrl,
          $familyAddFriendUrl,
          $family['invite_code'] ?? null
        ); ?>
      </div>
    <?php else: ?>
      <p class="hint">①の友だち追加まで完了すると、ご家族向けの登録(必須)が表示されます。</p>
    <?php endif; ?>

    <?php if (($userLineLinked && !$userFriendConfirmed) || ($familyLineLinked && !$familyFriendConfirmed)): ?>
      <script>setTimeout(function () { location.reload(); }, 10000);</script>
    <?php endif; ?>
  <?php endif; ?>
</div>
    <?php
    Layout::renderFooter();
}

// リンクが無効(該当データなし・既に別の手続きで解決済み等)な場合の簡易画面
function renderExpiredNotice(): void
{
    Layout::renderHeader('apply', 'このリンクは無効です');
    ?>
<style>
  .card { max-width: 560px; margin: 0 auto; background:#fff; border-radius:12px; padding:28px 24px; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
  .card h1 { font-size:1.3rem; margin-top:0; }
</style>
<div class="card">
  <h1>このリンクは無効です</h1>
  <p>お申込み内容が見つかりませんでした。既にお手続きが完了しているか、リンクの有効期限が切れている可能性があります。心当たりがない場合は<a href="mailto:support@tayori-net.jp">support@tayori-net.jp</a>までご連絡ください。</p>
</div>
    <?php
    Layout::renderFooter();
}

// LINEログイン方式(コード不要)・従来のQR+コード方式(フォールバック)のどちらかで
// 1ステップ分の連携UIを描画する。loginUrlがnull(LINEログインチャネル未設定)の場合は
// 従来方式のみを表示する
function renderLineStep(
    string $title,
    string $accountName,
    bool $lineLinked,
    bool $friendConfirmed,
    ?string $loginUrl,
    string $fallbackAddFriendUrl,
    ?string $inviteCode
): void {
    ?>
  <div class="step <?= $friendConfirmed ? 'done' : '' ?>">
    <h3><?= h($title) ?><?php if ($friendConfirmed): ?><span class="badge ok">連携済み</span><?php endif; ?></h3>
    <?php if ($friendConfirmed): ?>
      <p class="hint">連携が完了しました。</p>
    <?php elseif ($lineLinked): ?>
      <p class="hint" style="color:#a12a1f;">友だち追加がまだ完了していません。トークを受け取るには「<?= h($accountName) ?>」の友だち追加が必要です。友だち追加が確認できるまで、次のステップには進めません。</p>
      <?php if ($fallbackAddFriendUrl !== ''): ?>
        <a class="button" href="<?= h($fallbackAddFriendUrl) ?>" target="_blank" rel="noopener">
          <?= Layout::icon('chat') ?> 友だち追加はこちら(別タブで開きます)
        </a>
      <?php endif; ?>
      <p class="hint">追加後、自動的にこのページが更新されます(数秒〜数十秒かかる場合があります)。</p>
    <?php elseif ($loginUrl !== null): ?>
      <p class="hint">ボタンをタップし、LINEでログインするだけで連携できます(友だち追加もあわせて確認されます)。</p>
      <a class="button" href="<?= h($loginUrl) ?>"><?= Layout::icon('login') ?> LINEで連携する</a>
      <?php if ($fallbackAddFriendUrl !== '' && $inviteCode !== null): ?>
        <details class="fallback">
          <summary>うまくいかない場合はこちら</summary>
          <div class="qr-box">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=<?= urlencode($fallbackAddFriendUrl) ?>" alt="友だち追加QRコード" width="160" height="160">
          </div>
          <p class="hint">上記QRから「<?= h($accountName) ?>」を友だち追加のうえ、最初のメッセージとして下記のコードをそのまま送信してください。</p>
          <div class="code-box"><div class="code"><?= h($inviteCode) ?></div></div>
        </details>
      <?php endif; ?>
    <?php else: ?>
      <p class="hint">LINEで「<?= h($accountName) ?>」公式アカウントを友だち追加し、最初のメッセージとして下記のコードをそのまま送信してください。</p>
      <?php if ($fallbackAddFriendUrl !== ''): ?>
        <a class="button" href="<?= h($fallbackAddFriendUrl) ?>" target="_blank" rel="noopener">
          <?= Layout::icon('chat') ?> 友だち追加はこちら(別タブで開きます)
        </a>
        <div class="qr-box">
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=<?= urlencode($fallbackAddFriendUrl) ?>" alt="友だち追加QRコード" width="160" height="160">
        </div>
      <?php endif; ?>
      <?php if ($inviteCode !== null): ?>
        <div class="code-box"><div class="code"><?= h($inviteCode) ?></div></div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
    <?php
}

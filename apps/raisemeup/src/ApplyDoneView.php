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

    // 「次へ」ボタン(apply_check_friend.php)で確認できなかった場合に、1回だけ注意文を出すためのフラッシュ値
    $friendCheckFailed = $_SESSION['friend_check_failed'] ?? null;
    unset($_SESSION['friend_check_failed']);

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
  a.button, button.button { display:flex; align-items:center; justify-content:center; gap:10px; width:100%; text-align:center; margin-top:12px; padding:12px; background:#06c755; color:#fff; text-decoration:none; border:none; border-radius:8px; font-weight:bold; font-size:1rem; font-family:inherit; cursor:pointer; }
  a.button .icon { width:1.3em; height:1.3em; }
  a.button.secondary, button.button.secondary { background:#4B8B5A; }
  form { margin:0; }
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
  .copy-box { display:flex; gap:8px; margin:12px 0; flex-wrap:wrap; }
  .copy-box input.copy-input { flex:1 1 200px; min-width:0; padding:10px; font-size:0.82rem; border:1px solid #ccc; border-radius:6px; background:#f7f5f0; color:#555; }
  .copy-box button.copy-btn { width:auto; flex:0 0 auto; margin-top:0; padding:10px 16px; }
  .handoff-guide { list-style:none; margin:16px 0 0; padding:0; display:flex; flex-direction:column; gap:10px; }
  .handoff-guide li { display:flex; align-items:center; gap:10px; font-size:0.9rem; }
  .guide-icon { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:50%; background:#eef2ea; color:#4B8B5A; flex-shrink:0; }
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

    <?php if ($lineError === 'user_duplicate'): ?>
      <div class="errors">このLINEアカウントは、既に別の利用者様として登録済みです。ご本人様ご自身のLINEアカウントで連携してください。</div>
    <?php elseif ($lineError !== ''): ?>
      <div class="errors">LINEとの連携がうまくいきませんでした。お手数ですが、もう一度お試しください。</div>
    <?php endif; ?>

    <p>まず<strong>ご家族様ご自身</strong>のスマートフォンで①のLINE連携をお願いします。続けて②で、<strong><?= h($r['user_display_name']) ?></strong>様(ご利用者様ご本人)へ連携用のURLをお送りいただきます。</p>

    <?php renderLineStep(
      '① ご家族向け通知の連携(必須)',
      'family',
      Config::get('LINE_FAMILY_BOT_DISPLAY_NAME', 'TAYORIサポート'),
      $familyLineLinked,
      $familyFriendConfirmed,
      $familyLoginUrl,
      $familyAddFriendUrl,
      $family['invite_code'] ?? null,
      $friendCheckFailed === 'family'
    ); ?>
    <?php if (!$familyFriendConfirmed): ?>
      <p class="hint">無料期間終了のお知らせなど大切なご連絡をお送りするため、こちらが完了していないとお申込みが自動的にキャンセルとなりますのでご注意ください。</p>
    <?php endif; ?>

    <?php if ($familyFriendConfirmed): ?>
      <div class="optional">
        <p>続けて、<strong><?= h($r['user_display_name']) ?></strong>様(ご利用者様ご本人)のLINE連携をお願いします(必須)。下記のURLをコピーして、ご本人のスマートフォンにお送りください。</p>
        <?php renderUserHandoffStep(
          '② ご利用者様のLINE連携(必須)',
          Config::get('LINE_BOT_DISPLAY_NAME', 'TAYORI'),
          $userFriendConfirmed,
          $userLineLinked,
          $userLoginUrl,
          $addFriendUrl,
          $user['invite_code'] ?? null,
          $friendCheckFailed === 'user'
        ); ?>
      </div>
    <?php else: ?>
      <p class="hint">①の連携が完了すると、ご利用者様のLINE連携方法(必須)が表示されます。</p>
    <?php endif; ?>
  <?php endif; ?>
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
})();
</script>
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

// ご利用者様ご本人の端末で、LINEログイン直後にそのまま表示する専用画面。
// この画面を見るのは高齢の利用者様ご本人なので、料金・プラン・ご家族の連携状況といった
// 本人には関係のない情報は一切出さず、「友だち追加」だけに集中させたシンプルな案内にする。
function renderUserOnlyDone(array $user, bool $friendConfirmed, string $addFriendUrl, string $lineError = ''): void
{
    $companionName = $user['companion_name'] ?: 'たより';
    $accountName = Config::get('LINE_BOT_DISPLAY_NAME', 'TAYORI');
    Layout::renderHeader('apply', $friendConfirmed ? '連携が完了しました' : 'もう少しで完了です');
    ?>
<style>
  .card { max-width: 480px; margin: 0 auto; background:#fff; border-radius:12px; padding:32px 26px; box-shadow:0 2px 8px rgba(0,0,0,0.06); text-align:center; }
  .card h1 { font-size:1.4rem; margin-top:0; }
  .card p { font-size:1.05rem; line-height:1.8; color:#3D3A35; }
  .errors { background:#fdecea; border:1px solid #f5b0a8; color:#a12a1f; padding:14px 16px; border-radius:8px; margin-bottom:16px; text-align:left; font-size:1rem; }
  .done-icon { color:#4B8B5A; }
  a.button { display:flex; align-items:center; justify-content:center; gap:10px; margin:20px 0; padding:18px; background:#06c755; color:#fff; text-decoration:none; border-radius:10px; font-weight:bold; font-size:1.15rem; }
  a.button .icon { width:1.4em; height:1.4em; }
  .qr-box { text-align:center; margin-top:16px; }
  .qr-box img { width:180px; height:180px; border:1px solid #eee; border-radius:8px; padding:8px; background:#fff; }
  .hint { font-size:0.95rem; color:#777; margin-top:16px; }
</style>
<div class="card">
  <?php if ($friendConfirmed): ?>
    <h1><span class="done-icon">✓</span> 連携が完了しました</h1>
    <p>もうすぐ<strong><?= h($companionName) ?></strong>からLINEにメッセージが届きます。<br>届いたら、気軽に話しかけてみてください。</p>
  <?php else: ?>
    <h1>もう少しで完了です</h1>
    <?php if ($lineError === 'user_duplicate'): ?>
      <div class="errors">このLINEアカウントは、既に別の方としてご登録済みです。ご案内をもう一度ご確認ください。</div>
    <?php elseif ($lineError !== ''): ?>
      <div class="errors">うまく登録できませんでした。もう一度お試しください。</div>
    <?php endif; ?>
    <p>下のボタンから「<?= h($accountName) ?>」を<br>友だち追加してください。</p>
    <?php if ($addFriendUrl !== ''): ?>
      <a class="button" href="<?= h($addFriendUrl) ?>"><?= Layout::icon('chat') ?> 友だち追加する</a>
      <div class="qr-box">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode($addFriendUrl) ?>" alt="友だち追加QRコード" width="180" height="180">
      </div>
    <?php endif; ?>
    <p class="hint">友だち追加が済むと、自動的に<?= h($companionName) ?>からメッセージが届きます。</p>
  <?php endif; ?>
</div>
    <?php
    Layout::renderFooter();
}

// LINEログイン方式(コード不要)・従来のQR+コード方式(フォールバック)のどちらかで
// 1ステップ分の連携UIを描画する。loginUrlがnull(LINEログインチャネル未設定)の場合は
// 従来方式のみを表示する
function renderLineStep(
    string $title,
    string $target,
    string $accountName,
    bool $lineLinked,
    bool $friendConfirmed,
    ?string $loginUrl,
    string $fallbackAddFriendUrl,
    ?string $inviteCode,
    bool $checkFailed = false
): void {
    ?>
  <div class="step <?= $friendConfirmed ? 'done' : '' ?>">
    <h3><?= h($title) ?><?php if ($friendConfirmed): ?><span class="badge ok">連携済み</span><?php endif; ?></h3>
    <?php if ($friendConfirmed): ?>
      <p class="hint">連携が完了しました。</p>
    <?php elseif ($lineLinked): ?>
      <?php if ($checkFailed): ?>
        <p class="hint" style="color:#a12a1f;">「<?= h($accountName) ?>」の友だち追加がまだ確認できませんでした。友だち追加を済ませてから、もう一度お試しください。</p>
      <?php else: ?>
        <p class="hint" style="color:#a12a1f;">友だち追加がまだ完了していません。トークを受け取るには「<?= h($accountName) ?>」の友だち追加が必要です。友だち追加が確認できるまで、次のステップには進めません。</p>
      <?php endif; ?>
      <?php if ($fallbackAddFriendUrl !== ''): ?>
        <a class="button" href="<?= h($fallbackAddFriendUrl) ?>" target="_blank" rel="noopener">
          <?= Layout::icon('chat') ?> 友だち追加はこちら(別タブで開きます)
        </a>
      <?php endif; ?>
      <form method="post" action="/apply_check_friend.php">
        <input type="hidden" name="target" value="<?= h($target) ?>">
        <input type="hidden" name="code" value="<?= h((string) $inviteCode) ?>">
        <button type="submit" class="button secondary">友だち追加を確認して次へ</button>
      </form>
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

// ご利用者様(ご本人)向けのステップ。この画面を操作しているのはご家族の端末である前提のため、
// その場でボタンを押させる方式(renderLineStepのLINEログインURL方式)ではなく、URLをコピーして
// ご本人の端末に送ってもらう方式にしている。友だち追加未確認時の「次へ」導線・従来のQR+コード
// フォールバックはrenderLineStepと共通の考え方を踏襲する
function renderUserHandoffStep(
    string $title,
    string $accountName,
    bool $friendConfirmed,
    bool $lineLinked,
    ?string $loginUrl,
    string $fallbackAddFriendUrl,
    ?string $inviteCode,
    bool $checkFailed = false
): void {
    ?>
  <div class="step <?= $friendConfirmed ? 'done' : '' ?>">
    <h3><?= h($title) ?><?php if ($friendConfirmed): ?><span class="badge ok">連携済み</span><?php endif; ?></h3>
    <?php if ($friendConfirmed): ?>
      <p class="hint">連携が完了しました。</p>
    <?php elseif ($lineLinked): ?>
      <?php if ($checkFailed): ?>
        <p class="hint" style="color:#a12a1f;">「<?= h($accountName) ?>」の友だち追加がまだ確認できませんでした。ご本人に友だち追加を済ませていただいてから、もう一度お試しください。</p>
      <?php else: ?>
        <p class="hint" style="color:#a12a1f;">友だち追加がまだ完了していません。トークを受け取るには「<?= h($accountName) ?>」の友だち追加が必要です。</p>
      <?php endif; ?>
      <form method="post" action="/apply_check_friend.php">
        <input type="hidden" name="target" value="user">
        <input type="hidden" name="code" value="<?= h((string) $inviteCode) ?>">
        <button type="submit" class="button secondary">友だち追加を確認して次へ</button>
      </form>
    <?php elseif ($loginUrl !== null): ?>
      <p class="hint">下のURLをコピーして、ご本人のスマートフォンに送ってください。ご本人がそのURLを開いてLINEでログインするだけで、友だち追加まであわせて完了します。</p>
      <div class="copy-box">
        <input type="text" class="copy-input" id="userHandoffUrl" value="<?= h($loginUrl) ?>" readonly onclick="this.select()">
        <button type="button" class="button copy-btn" data-copy-target="userHandoffUrl"><?= Layout::icon('copy') ?> URLをコピーする</button>
      </div>
      <ol class="handoff-guide">
        <li><span class="guide-icon"><?= Layout::icon('copy') ?></span><span>上のボタンでURLをコピーする</span></li>
        <li><span class="guide-icon"><?= Layout::icon('chat') ?></span><span>LINEやSMSで、ご本人のスマートフォンに送る</span></li>
        <li><span class="guide-icon"><?= Layout::icon('login') ?></span><span>ご本人がそのURLを開き、LINEでログインする</span></li>
        <li><span class="guide-icon"><?= Layout::icon('check') ?></span><span>友だち追加まで自動で確認されます</span></li>
      </ol>
      <p class="hint" style="margin-top:12px;">現在、ご本人にLINE連携をお願いしている状態です。連携が完了次第、ご家族様のLINEにお知らせしますので、この画面を閉じてお待ちいただいて構いません。</p>
      <?php if ($fallbackAddFriendUrl !== '' && $inviteCode !== null): ?>
        <details class="fallback">
          <summary>URLが開けない場合はこちら</summary>
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

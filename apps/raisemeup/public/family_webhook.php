<?php
// 「TAYORIサポート」(ご家族向け通知専用アカウント)のWebhook。
// 利用者本人との会話ループ(webhook.php)とは別チャネル・別エンドポイント。
// 通常はClaudeを呼ばない軽量な処理だが、「テーマ」という単語を含むメッセージが届いた時だけ、
// テーマ設定の依頼文からAIへの指示文を抜き出すために1回だけ呼び出す。
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineClient.php';
require_once __DIR__ . '/../src/ClaudeClient.php';
require_once __DIR__ . '/../src/FamilyAccountRepository.php';
require_once __DIR__ . '/../src/FamilyMessageRepository.php';
require_once __DIR__ . '/../src/FamilyThemeRepository.php';
require_once __DIR__ . '/../src/SubscriptionRepository.php';
require_once __DIR__ . '/../src/FriendConfirmationService.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');

// 伝言・テーマ機能自体、寄り添いスタンダード以上の契約者限定(ConversationHandlerのリスク通知等と同じ制限)
const FAMILY_MESSAGE_PLAN_CODES = ['family_watch', 'premium_medical'];
const CANCEL_KEYWORDS = ['取り消し', 'とりけし', 'キャンセル'];
const THEME_KEYWORD = 'テーマ';

$body = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

$lineClient = new LineClient(
    Config::get('LINE_FAMILY_CHANNEL_SECRET'),
    Config::get('LINE_FAMILY_CHANNEL_ACCESS_TOKEN')
);

if (!$lineClient->verifySignature($body, $signature)) {
    http_response_code(403);
    exit('Invalid signature');
}

$events = json_decode($body, true)['events'] ?? [];

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
$familyRepo = new FamilyAccountRepository($pdo);
$familyMessageRepo = new FamilyMessageRepository($pdo);
$familyThemeRepo = new FamilyThemeRepository($pdo);
$subscriptionRepo = new SubscriptionRepository($pdo);
$claudeClient = new ClaudeClient(Config::get('ANTHROPIC_API_KEY'), Config::get('CLAUDE_MODEL'));

foreach ($events as $event) {
    if (($event['type'] ?? '') === 'follow') {
        try {
            handleFamilyFollowEvent($familyRepo, $lineClient, $event);
        } catch (Throwable $e) {
            error_log('Family webhook follow event handling failed: ' . $e->getMessage());
        }
        continue;
    }
    if (($event['type'] ?? '') === 'unfollow') {
        try {
            handleFamilyUnfollowEvent($familyRepo, $event);
        } catch (Throwable $e) {
            error_log('Family webhook unfollow event handling failed: ' . $e->getMessage());
        }
        continue;
    }
    if (($event['type'] ?? '') !== 'message' || ($event['message']['type'] ?? '') !== 'text') {
        continue;
    }
    try {
        handleFamilyMessage($familyRepo, $familyMessageRepo, $familyThemeRepo, $subscriptionRepo, $claudeClient, $lineClient, $event);
    } catch (Throwable $e) {
        error_log('Family webhook event handling failed: ' . $e->getMessage());
    }
}

http_response_code(200);
echo 'OK';

/**
 * ご家族側の友だち追加(followイベント)を処理する。
 * LINEログイン連携は済んでいるが、その時点では友だち追加していなかった場合に、
 * 後からQRコード等で友だち追加した際、ここで初めて連携完了(friend_confirmed_at)にする
 * (line_login_callback.php側で友だち追加済みだった場合の処理と同じ)。
 */
function handleFamilyFollowEvent(FamilyAccountRepository $familyRepo, LineClient $lineClient, array $event): void
{
    $lineUserId = $event['source']['userId'] ?? '';
    if ($lineUserId === '') {
        return;
    }

    $family = $familyRepo->findByLineUserId($lineUserId);
    // LINEログイン未連携、または既に連携完了済み(再フォロー等)は無視する
    if ($family === null || $family['friend_confirmed_at'] !== null) {
        return;
    }

    FriendConfirmationService::confirmFamily($familyRepo, $lineClient, $family);
}

/**
 * ご家族側のLINE公式アカウント「TAYORIサポート」ブロック(unfollowイベント)を処理する。
 * friend_confirmed_atをNULLに戻す。これによりgetNotifiableForUser()の通知対象から
 * 自動的に除外され、通知がサイレントに止まっている状態をマイページ側で検知できるようになる。
 */
function handleFamilyUnfollowEvent(FamilyAccountRepository $familyRepo, array $event): void
{
    $lineUserId = $event['source']['userId'] ?? '';
    if ($lineUserId === '') {
        return;
    }

    $family = $familyRepo->findByLineUserId($lineUserId);
    if ($family === null || $family['friend_confirmed_at'] === null) {
        return;
    }

    $familyRepo->clearFriendConfirmation((int) $family['id']);
}

function handleFamilyMessage(
    FamilyAccountRepository $familyRepo,
    FamilyMessageRepository $familyMessageRepo,
    FamilyThemeRepository $familyThemeRepo,
    SubscriptionRepository $subscriptionRepo,
    ClaudeClient $claudeClient,
    LineClient $lineClient,
    array $event
): void {
    $lineUserId = $event['source']['userId'];
    $messageText = trim($event['message']['text']);
    $replyToken = $event['replyToken'];

    $existing = $familyRepo->findByLineUserId($lineUserId);
    if ($existing === null) {
        $pendingFamily = $familyRepo->findByInviteCode($messageText);
        if ($pendingFamily !== null) {
            // この経路はLINEからメッセージが届いている時点で友だち追加済みであることが保証されているため、
            // followイベントを待たずその場で連携完了(friend_confirmed_at)にしてよい
            $familyRepo->linkLineUserId((int) $pendingFamily['id'], $lineUserId);
            $familyRepo->markFriendConfirmed((int) $pendingFamily['id']);
            $lineClient->reply($replyToken, '登録が完了しました。無料期間の終了案内など、お支払いに関するご連絡をこちらのアカウントからお送りします。');
            return;
        }
        $lineClient->reply($replyToken, 'はじめまして。ご案内した連携コードを送っていただけますか?');
        return;
    }

    if (in_array($messageText, CANCEL_KEYWORDS, true)) {
        $cancelled = $familyMessageRepo->cancelLatestPending((int) $existing['id']);
        $lineClient->reply($replyToken, $cancelled
            ? '直近の伝言を取り消しました。'
            : '取り消せる伝言(まだお伝えしていないもの)が見当たりませんでした。');
        return;
    }

    $linkedUsers = $familyRepo->getLinkedUsersFor((int) $existing['id']);
    if (empty($linkedUsers)) {
        $lineClient->reply($replyToken, '登録済みです。無料期間の終了案内などは、こちらのアカウントにお送りします。');
        return;
    }
    $userId = (int) $linkedUsers[0]['id']; // 1家族→複数利用者はUI未対応のため、現状は先頭(=notify_priority最上位)のみ対象

    $planCode = $subscriptionRepo->getCurrentPlanCodeForUser($userId);
    if (!in_array($planCode, FAMILY_MESSAGE_PLAN_CODES, true)) {
        $lineClient->reply(
            $replyToken,
            '伝言・テーマ設定は、寄り添いスタンダード以上のプランでご利用いただけます。'
                . '無料期間の終了案内などは、引き続きこちらのアカウントにお送りします。'
        );
        return;
    }

    // 「テーマ」という単語が含まれていれば、1回きりの伝言ではなく継続的なテーマの設定依頼として扱う
    if (mb_strpos($messageText, THEME_KEYWORD) !== false) {
        $themeText = $claudeClient->extractThemeText($messageText);
        if ($themeText === '') {
            $themeText = $messageText; // 抽出に失敗した場合は元の文言をそのまま使う
        }
        $familyThemeRepo->add($userId, (int) $existing['id'], $themeText);
        $lineClient->reply(
            $replyToken,
            "テーマを設定しました。「{$themeText}」を、TAYORIが普段の会話の中で自然に気にかけるようにします。"
                . "\n(" . FamilyThemeRepository::DEFAULT_EXPIRES_IN_DAYS . '日ほどで自動的に外れます。マイページからも確認・削除できます)'
        );
        return;
    }

    $familyMessageRepo->queue($userId, (int) $existing['id'], $messageText);
    $lineClient->reply(
        $replyToken,
        '伝言を承りました。今度お話しした時に、TAYORIからさりげなくお伝えしますね。'
            . "\n(取り消したい場合は「取り消し」と送ってください。「◯◯をテーマにして」のように送ると、"
            . '一度きりでなく普段から気にかけてほしいテーマとして設定できます)'
    );
}

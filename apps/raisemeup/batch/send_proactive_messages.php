<?php
// 利用者に対して、システム側(AI)から先に話しかけるバッチ(regenerate_summaries.php等と同様にcronで実行する想定)。
// cronは10分間隔で実行する想定(下記PROACTIVE_CHANCE_PER_RUNもそれに合わせた低確率にしてある)。
// 「10時ちょうどに必ず来る」ような機械的な定時感を避け、時間帯もランダムにすることで
// 友達からふと連絡が来るような自然さを出す狙い
// - 予定リマインド: 前日にreminder_sent=0の予定があれば、定型文で知らせる(送信済みフラグがあるので
//   10分間隔で実行しても二重送信はしない)
// - 気まぐれな声かけ: 「何日も既読無視されたから」ではなく、普段のLINEの友達付き合いのように、
//   直近の会話有無に関係なくPROACTIVE_CHANCE_PER_RUNの確率で軽く話しかける
//   (PROACTIVE_MIN_GAP_HOURS以内に会話していた場合だけは、直後の割り込みを避けるため今回は見送る)。
//   ただしCHECKIN_WINDOWS(朝・夜)の時間帯内に一度も会話が無い利用者は、安否確認を兼ねてその時間帯の
//   終了までに必ず1回は声がかかるよう保証モードに切り替わる(詳細は定数のコメント参照)
// - 家族への安否通知は2段構え: (3)CHECKIN_WINDOWSの固定枠終了+猶予で「様子見レベル」の通知、
//   (4)発生時刻を問わないURGENT_SILENCE_HOURS(アクティブ時間帯基準)超過で「危機感のある」通知。
//   (4)の文面は、無応答期間に重なる予定・直近の会話内容・気になる会話の記録の有無を踏まえて組み立てる
// - (5)服薬リマインド: schedules.is_medication=trueの日次/週次予定について、その時刻が来たら
//   「お薬の時間だよ」と声をかける。健康に関わるため、深夜早朝の声かけガード(1・2)の対象外にしている
//   (予定リマインド(1)は二重送信を避けるためis_medication=trueの予定を対象外にしている)。
//   このリマインドはconversations.is_notification=1で記録し、返信を前提としない事務的な通知として
//   気まぐれな声かけ(2)の間隔判定・CHECKIN_WINDOWSの「会話成立」判定からは除外する
//   (服薬の声かけだけがずっと続くと、雑談や安否確認チェックインの機会そのものが失われてしまうため)
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/LineClient.php';
require_once __DIR__ . '/../src/ClaudeClient.php';
require_once __DIR__ . '/../src/SummaryRepository.php';
require_once __DIR__ . '/../src/TopicCoverageRepository.php';
require_once __DIR__ . '/../src/UserRepository.php';
require_once __DIR__ . '/../src/SubscriptionRepository.php';
require_once __DIR__ . '/../src/FamilyAccountRepository.php';
require_once __DIR__ . '/../src/ScheduleRepository.php';
require_once __DIR__ . '/../src/MedicationLogRepository.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

// このバッチが実行されるたび(=10分に1回想定)、対象者ごとにこの確率で声かけするかどうかを抽選する。
// 深夜早朝(21:00-08:00)を除く13時間 = 78回/日の実行機会に対してこの値なので、
// 「沈黙4時間超の利用者が、その日のうちに一度もTAYORIから声をかけられない確率」は約65%
// (=1日のうちに声がかかる確率はおよそ35%。1日1回抽選していた頃とほぼ同じ体感になるよう逆算した値)。
// 判定基準そのもの(静かな時間帯の除外・沈黙時間の下限)は変えていない
//
// この確率は「毎日必ず1回は声がかかる」ことを保証しない(独立試行なので、日によっては声がかからない
// 日が続きうる)。実際に運用中、抽選が外れ続けて丸4日間声かけゼロになったケースを確認した
// (314回試行して0ヒットは約18%の確率で起こりうる範囲内であり、バグではなく設計上の性質だった)。
// 「自然な友達感」は保ちつつ、安否確認として毎日決まった時間帯には必ず会話が成立するようにするため、
// CHECKIN_WINDOWSによる下限保証を別途設けている(下記参照)。
const PROACTIVE_CHANCE_PER_RUN = 0.0055;

// 安否確認を兼ねた「必ず会話する」時間帯(24時間表記、endは含まない)。利用者の健康・安全確認のため、
// 1日2回(午前中・夜)、この時間帯の中で最低1往復(TAYORIからの声かけだけでも、それに対する返信だけでも
// どちらの方向でもよい)の会話が成立することを保証する。時間帯の中で既に会話がある利用者は
// 「確認済み」として保証モードの対象から外れ、通常のPROACTIVE_CHANCE_PER_RUNによる気まぐれな声かけのみになる。
// まだ会話が無い利用者は、この時間帯の終了までの残り実行回数の逆数を確率にして抽選するため、
// 外れ続けても時間帯最後の1回では確率1=確実に送信される(毎回同じ時刻に機械的に送られるわけではないが、
// 時間帯の終わりまでには必ず1回送られる)。時間帯は必要に応じてここで調整可能
const CHECKIN_WINDOWS = [
    ['label' => 'morning', 'start' => 8, 'end' => 12],  // 午前中の安否確認枠
    ['label' => 'evening', 'start' => 17, 'end' => 21], // 夜の安否確認枠(21時からは夜間ガード)
];

// 直近でこの時間以内に会話(inbound/outbound問わず)していた場合は、話したばかりのところに
// 割り込む形になり不自然なので、抽選対象からも外す
const PROACTIVE_MIN_GAP_HOURS = 4;

// CHECKIN_WINDOWSの時間帯が終了してから、この分数だけ猶予を置いてから「応答なし」と判定する
// (時間帯終了ぎりぎりに保証送信された場合でも、返信の時間を確保するため)
const NO_RESPONSE_GRACE_MINUTES = 60;

// 「応答なし」の家族通知は、家族への通知機能自体が対象の寄り添いスタンダード以上の契約者限定
// (ConversationHandlerのリスク通知と同じ制限。寄り添いベーシックでは通知しない)
const WELLNESS_NOTIFY_PLAN_CODES = ['family_watch', 'premium_medical'];

// 「危機感のある」家族通知(urgent_silence_alerts)を出すまでの沈黙の長さ(アクティブ時間帯=
// QUIET_HOURS_END〜QUIET_HOURS_STARTのみでカウントした時間。深夜早朝を挟んでも、寝ている間の
// 沈黙は加算されない)。CHECKIN_WINDOWSの固定枠(朝・夜それぞれの終了+猶予)による通知とは別に、
// 発生時刻に関わらずこの時間が経過した時点で即座に検知する、より速い経路として用意している
const URGENT_SILENCE_HOURS = 6;

// 深夜早朝は、全利用者一律でシステム側からの声かけを控える時間帯(24時間表記)。
// cronが10分間隔で回るようになった今、この判定が実質的な夜間ガードになる
const QUIET_HOURS_START = 21; // 21時以降
const QUIET_HOURS_END = 8;    // 翌8時まで

Env::load(__DIR__ . '/../../../.env');

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
$lineClient = new LineClient(Config::get('LINE_CHANNEL_SECRET'), Config::get('LINE_CHANNEL_ACCESS_TOKEN'));
// 家族への通知は「TAYORIサポート」チャネル(利用者本人の会話用アカウントとは別)から送る
$familyLineClient = new LineClient(Config::get('LINE_FAMILY_CHANNEL_SECRET'), Config::get('LINE_FAMILY_CHANNEL_ACCESS_TOKEN'));
$claudeClient = ClaudeClient::fromConfig();
$summaryRepo = new SummaryRepository($pdo);
$topicCoverageRepo = new TopicCoverageRepository($pdo);
$userRepo = new UserRepository($pdo);
$subscriptionRepo = new SubscriptionRepository($pdo);
$familyRepo = new FamilyAccountRepository($pdo);
$medicationLogRepo = new MedicationLogRepository($pdo);
$now = new DateTime('now', new DateTimeZone('Asia/Tokyo'));

// 繰り返し予定(毎週/毎月)を、過去日になっていれば次回occurrenceへ繰り上げる。前日リマインド(1)の
// 判定がscheduled_atを見ているため、リマインド処理より前に必ず実行する
(new ScheduleRepository($pdo))->advanceRecurringSchedules();

// $isNotification: 服薬リマインドのような、返信を前提としない事務的な一方通知の場合はtrue。
// 気まぐれ雑談・CHECKIN_WINDOWSの「会話成立」判定(下記candidatesクエリ)からは除外される
function logOutbound(PDO $pdo, int $userId, string $text, bool $isNotification = false): void
{
    $pdo->prepare(
        'INSERT INTO conversations (user_id, direction, message_type, is_notification, content) VALUES (?, "outbound", "text", ?, ?)'
    )->execute([$userId, $isNotification ? 1 : 0, $text]);
}

// $start/$end は "HH:MM:SS"(DBのTIME型)または null。$startのみ/$endのみの片方だけの申告はしない前提だが、
// 念のためどちらか片方でもnullなら「申告なし」として扱う。開始>終了は日をまたぐ範囲として扱う(例:22:00〜07:00)
function isWithinQuietHours(?string $start, ?string $end, DateTime $now): bool
{
    if ($start === null || $end === null) {
        return false;
    }
    $nowMinutes = ((int) $now->format('H')) * 60 + (int) $now->format('i');
    [$startH, $startM] = array_map('intval', explode(':', $start));
    [$endH, $endM] = array_map('intval', explode(':', $end));
    $startMinutes = $startH * 60 + $startM;
    $endMinutes = $endH * 60 + $endM;

    if ($startMinutes === $endMinutes) {
        return false; // 同時刻は「範囲なし」として扱う
    }
    if ($startMinutes < $endMinutes) {
        return $nowMinutes >= $startMinutes && $nowMinutes < $endMinutes;
    }
    // 日をまたぐ範囲(例:22:00〜07:00)
    return $nowMinutes >= $startMinutes || $nowMinutes < $endMinutes;
}

// 「今日の$endHour時ちょうど」までに残っている、このバッチの実行回数
// (このバッチはcronで10分間隔に実行される想定なので、10分刻みで数える)。今回の実行分(1回)を含む
// (例: 残り10分ちょうどなら、次の実行は$endHourと同時刻になり実行されないので、残りは今回の1回のみ)
function remainingActiveRuns(DateTime $now, int $endHour): int
{
    $end = clone $now;
    $end->setTime($endHour, 0, 0);
    $secondsLeft = $end->getTimestamp() - $now->getTimestamp();
    $minutesLeft = intdiv(max($secondsLeft, 0), 60);
    return max(1, intdiv($minutesLeft + 9, 10));
}

// $nowが該当するCHECKIN_WINDOWSの時間帯を返す(無ければnull)
function activeCheckinWindow(DateTime $now): ?array
{
    $nowMinutes = ((int) $now->format('H')) * 60 + (int) $now->format('i');
    foreach (CHECKIN_WINDOWS as $window) {
        $startMinutes = $window['start'] * 60;
        $endMinutes = $window['end'] * 60;
        if ($nowMinutes >= $startMinutes && $nowMinutes < $endMinutes) {
            return $window;
        }
    }
    return null;
}

// $since〜$nowの間で、深夜早朝(QUIET_HOURS_START〜QUIET_HOURS_END)を除いた実質経過時間(時間単位)を計算する。
// 「寝ている間ずっと連絡が無い」だけで毎朝誤検知しないよう、URGENT_SILENCE_HOURSの判定はこの値を使う
function activeHoursBetween(DateTime $since, DateTime $now): float
{
    if ($since >= $now) {
        return 0.0;
    }
    $totalSeconds = 0;
    $dayCursor = (clone $since)->setTime(0, 0, 0);
    while ($dayCursor < $now) {
        $activeStart = (clone $dayCursor)->setTime(QUIET_HOURS_END, 0, 0);
        $activeEnd = (clone $dayCursor)->setTime(QUIET_HOURS_START, 0, 0);
        $segStart = max($activeStart, $since);
        $segEnd = min($activeEnd, $now);
        if ($segStart < $segEnd) {
            $totalSeconds += $segEnd->getTimestamp() - $segStart->getTimestamp();
        }
        $dayCursor->modify('+1 day');
    }
    return $totalSeconds / 3600;
}

// $rangeStart〜$rangeEndのどこかにかかる予定(status='cancelled'を除く)を1件だけ返す(無ければnull)
function findOverlappingSchedule(PDO $pdo, int $userId, DateTime $rangeStart, DateTime $rangeEnd): ?array
{
    $stmt = $pdo->prepare(
        "SELECT title, location FROM schedules
         WHERE user_id = ? AND status != 'cancelled' AND scheduled_at IS NOT NULL
           AND scheduled_at <= ? AND COALESCE(scheduled_end_at, scheduled_at) >= ?
         ORDER BY scheduled_at ASC LIMIT 1"
    );
    $stmt->execute([$userId, $rangeEnd->format('Y-m-d H:i:s'), $rangeStart->format('Y-m-d H:i:s')]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// $userIdについて、まだ解除されていないurgent_silence_alerts(心配して位置情報を尋ねたが、まだ返信が無い状態)があるか
function hasPendingUrgentSilenceAlert(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM urgent_silence_alerts WHERE user_id = ? AND resolved_at IS NULL LIMIT 1'
    );
    $stmt->execute([$userId]);
    return (bool) $stmt->fetchColumn();
}

// 直近のinbound(利用者からの発言)本文と日時。一度も会話が無ければnull
function getLastInboundMessage(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT content, created_at FROM conversations WHERE user_id = ? AND direction = 'inbound' ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ConversationHandler::buildRecentHistory()と同じ形式(role/content)で直近の会話を返す。
// checkUrgentSilenceAndNotifyが、本人へ送る「心配になった」一言をAIに文脈込みで生成させるために使う
function getRecentHistoryForUser(PDO $pdo, int $userId, int $limit = 10): array
{
    $stmt = $pdo->prepare(
        'SELECT direction, content FROM conversations WHERE user_id = ? ORDER BY created_at DESC LIMIT ?'
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    return array_map(fn($row) => [
        'role' => $row['direction'] === 'inbound' ? 'user' : 'assistant',
        'content' => $row['content'],
    ], $rows);
}

// 直近$limit件の「こちらからの話しかけ(outbound)」を、それに続く「利用者の返信(inbound)」と
// ペアにして返す(新しい順、日時付き)。generateProactiveMessageが、同じ話題(トマトの収穫等)を
// 毎回持ち出してしまう問題を避ける材料であると同時に、その話題に利用者がどれくらい乗ってきたか
// (食いついたか、素っ気なかったか)をAIが読み取って深掘り判断する材料としても使う。
// 日時を持たせているのは、日をまたいだ朝夕のチェックインでも前日の話題を蒸し返さないようにするため
// (limit=8は、1日2回のCHECKIN_WINDOWS+気まぐれな声かけを合わせて概ね2日分をカバーする目安)。
// 返信が無かった話題はuser_reply=nullになる。取りこぼしを避けるため$limitの6倍を取得してから
// outbound起点でペアリングし、直近$limit件に絞る
function getRecentExchanges(PDO $pdo, int $userId, int $limit = 8): array
{
    $stmt = $pdo->prepare(
        "SELECT direction, content, created_at FROM conversations
         WHERE user_id = ? AND message_type = 'text'
         ORDER BY created_at DESC LIMIT ?"
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit * 6, PDO::PARAM_INT);
    $stmt->execute();
    $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC)); // 古い順に並び替えてから走査

    $exchanges = [];
    $pendingReplyParts = [];
    foreach ($rows as $row) {
        if ($row['direction'] === 'inbound') {
            if (!empty($exchanges)) {
                $pendingReplyParts[] = $row['content'];
            }
            continue;
        }
        if (!empty($exchanges) && !empty($pendingReplyParts)) {
            $exchanges[count($exchanges) - 1]['user_reply'] = implode("\n", $pendingReplyParts);
        }
        $pendingReplyParts = [];
        $exchanges[] = ['content' => $row['content'], 'created_at' => $row['created_at'], 'user_reply' => null];
    }
    if (!empty($exchanges) && !empty($pendingReplyParts)) {
        $exchanges[count($exchanges) - 1]['user_reply'] = implode("\n", $pendingReplyParts);
    }

    return array_slice(array_reverse($exchanges), 0, $limit);
}

// 初回のみ、AIコンパニオン自身の軽い自己紹介を生成して保存する(以降は固定して使い回す)。
// ConversationHandler::ensureCompanionPersonaと同じ考え方(冪等・遅延生成)をバッチ側でも行うことで、
// 新規ユーザーだけでなくこの機能導入前からの既存ユーザーにも、次に声をかけるタイミングで行き渡る
function ensureCompanionPersonaForBatchRow(array $row, ClaudeClient $claudeClient, UserRepository $userRepo): array
{
    if (!empty($row['companion_persona'])) {
        $decoded = json_decode((string) $row['companion_persona'], true);
        return is_array($decoded) ? $decoded : [];
    }

    $companionName = $row['companion_name'] ?: 'たより';
    $companionGender = ($row['companion_gender'] ?? null) === 'random' ? null : $row['companion_gender'];
    $facts = $claudeClient->generateCompanionPersona($companionName, $companionGender);
    if (!empty($facts)) {
        $userRepo->setCompanionPersona((int) $row['id'], $facts);
    }
    return $facts;
}

// 直近$hours時間以内に、様子見レベル(low)を超える(medium/high)リスク検知の記録があるか
function hasRecentNotableRiskEvent(PDO $pdo, int $userId, int $hours = 48): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM risk_events WHERE user_id = ? AND risk_level IN ('medium', 'high')
           AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR) LIMIT 1"
    );
    $stmt->execute([$userId, $hours]);
    return (bool) $stmt->fetchColumn();
}

/**
 * 「危機感のある」通知文を組み立てる。事実(最後の会話内容・重なる予定の有無・直近の気になる会話の記録)を
 * 積み上げて家族が状況を判断しやすくすることを優先し、AIによる自由生成はしない
 * (家族通知は内容の正確性が最優先のため、ConversationHandlerのリスク通知と同様テンプレートで組み立てる)。
 */
function buildUrgentSilenceMessage(
    PDO $pdo,
    int $userId,
    string $displayName,
    DateTime $lastInboundAt,
    DateTime $now,
    ?array $lastMessage
): string {
    $lines = ["【TAYORI】{$displayName}様と、しばらくTAYORIとの会話が確認できていません。"];

    if ($lastMessage !== null) {
        $content = (string) $lastMessage['content'];
        $excerpt = mb_substr($content, 0, 60) . (mb_strlen($content) > 60 ? '…' : '');
        $lastAt = (new DateTime($lastMessage['created_at']))->format('n/j H:i');
        $lines[] = "最後に確認できた会話は{$lastAt}頃でした(「{$excerpt}」)。";
    } else {
        $lines[] = "これまでのところ、TAYORIとの会話履歴自体がまだ確認できていません。";
    }

    $schedule = findOverlappingSchedule($pdo, $userId, $lastInboundAt, $now);
    if ($schedule !== null) {
        $scheduleText = $schedule['title'] . (!empty($schedule['location']) ? "({$schedule['location']})" : '');
        $lines[] = "この間「{$scheduleText}」のご予定があったため、外出中の可能性もあります。";
    }

    if (hasRecentNotableRiskEvent($pdo, $userId)) {
        $lines[] = "直近、気になる会話の記録もあります。マイページの「安心レポート」もあわせてご確認ください。";
    }

    $lines[] = "恐れ入りますが、直接ご本人にご連絡いただくか、ご様子をご確認いただけますようお願いいたします。";

    return implode("\n", $lines);
}

/**
 * URGENT_SILENCE_HOURS(アクティブ時間帯基準)を超えて利用者からのinboundが無い場合に、
 * 寄り添いスタンダード以上の契約者へ危機感のある通知を送る。CHECKIN_WINDOWSの固定枠とは独立に、
 * 発生時刻を問わず毎回の実行(cron)でチェックする。同じ沈黙エピソード(最後のinbound時刻が変わらない間)は
 * 一度だけ通知する(urgent_silence_alertsのUNIQUE制約 + INSERT IGNOREのrowCountで判定)。
 * 同じタイミングで、本人にもTAYORIから位置情報を尋ねるメッセージを送る(プラン問わず。家族通知とは別に、
 * TAYORI自身が心配して声をかける体裁のため)。冒頭の「心配になった」の一言は、直近の会話を踏まえて
 * ClaudeClient::generateUrgentSilenceOpeningが生成する(位置情報ボタンの案内文言自体は固定)。
 * 返信で位置情報が送られればConversationHandler側でurgent_silence_alertsが解除され、
 * 家族への解除連絡に反映される
 */
function checkUrgentSilenceAndNotify(
    PDO $pdo,
    LineClient $lineClient,
    LineClient $familyLineClient,
    ClaudeClient $claudeClient,
    SubscriptionRepository $subscriptionRepo,
    FamilyAccountRepository $familyRepo,
    DateTime $now
): int {
    $stmt = $pdo->query(
        "SELECT u.id, u.line_user_id, u.display_name, u.full_name, u.companion_name, u.gender, u.onboarded_at, u.created_at,
                (SELECT MAX(created_at) FROM conversations c WHERE c.user_id = u.id AND c.direction = 'inbound') AS last_inbound_at
         FROM users u
         WHERE u.status = 'active' AND u.line_user_id IS NOT NULL"
    );

    $notifiedCount = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $userId = (int) $row['id'];
        // 一度もinboundが無い利用者は、LINE連携完了時点(無ければ登録時点)を沈黙の起点とする
        $baseline = $row['last_inbound_at'] ?? $row['onboarded_at'] ?? $row['created_at'];
        $lastInboundAt = new DateTime($baseline, new DateTimeZone('Asia/Tokyo'));

        if (activeHoursBetween($lastInboundAt, $now) < URGENT_SILENCE_HOURS) {
            continue;
        }

        // 同じ沈黙エピソードでは一度だけ通知する。INSERT IGNOREの成否(rowCount)で
        // 「このエピソードで初めて閾値を超えた実行かどうか」を判定する
        $insertStmt = $pdo->prepare(
            'INSERT IGNORE INTO urgent_silence_alerts (user_id, silence_since) VALUES (?, ?)'
        );
        $insertStmt->execute([$userId, $lastInboundAt->format('Y-m-d H:i:s')]);
        if ($insertStmt->rowCount() === 0) {
            continue; // 既にこのエピソードで判定済み(通知済み、または対象外プランで記録のみ済み)
        }

        // 「心配になった」の一言部分は、直近の会話(外出先の話等)を踏まえて自然な言い方にするためAIに生成させる。
        // 例えば直前に道案内をしていた場合、それを無視して定型文で聞き返すと不自然になるため
        // (位置情報ボタンの案内自体は正確性を優先し固定文のまま)
        $companionName = $row['companion_name'] ?: 'たより';
        $history = getRecentHistoryForUser($pdo, $userId);
        $opening = $claudeClient->generateUrgentSilenceOpening($history, $companionName, (string) $row['display_name'], $row['gender']);
        if ($opening === '') {
            $opening = 'しばらくお返事が無いので、ちょっと心配になっちゃいました。今どちらにいらっしゃるか、教えてもらえますか?';
        }

        $urgentText = $opening . "\n下の「位置情報を送る」ボタンを押すと地図が出てきますので、そのまま画面の「送信」を押してくださいね。";
        if ($lineClient->push($row['line_user_id'], $urgentText, LineClient::LOCATION_QUICK_REPLY)) {
            // conversationsに記録しないと、本人が返信した際にConversationHandler::buildRecentHistory()の
            // 履歴にこのメッセージが載らず、Claudeが何への返信か分からないまま話が噛み合わなくなる
            // (例: 直前の雑談の話題を的外れに引きずる)。last_contact_atにも反映されないと、
            // CHECKIN_WINDOWSの保証チェックインが「まだ会話していない」と誤判定し重複しがちなので、
            // 他の送信経路(予定リマインド・気まぐれな声かけ)と同様にここでも記録する
            logOutbound($pdo, $userId, $urgentText);
        }

        $planCode = $subscriptionRepo->getCurrentPlanCodeForUser($userId);
        if (!in_array($planCode, WELLNESS_NOTIFY_PLAN_CODES, true)) {
            continue; // 寄り添いベーシック等、対象外プランでは記録のみでLINE通知はしない
        }
        $recipients = $familyRepo->getNotifiableForUser($userId);
        if (empty($recipients)) {
            continue;
        }

        // 家族への通知は、会話用の呼び名ではなくマイページ登録の氏名を優先する
        $displayName = $row['full_name'] ?: $row['display_name'] ?: 'ご利用者様';
        $lastMessage = getLastInboundMessage($pdo, $userId);
        $text = buildUrgentSilenceMessage($pdo, $userId, $displayName, $lastInboundAt, $now, $lastMessage);

        foreach ($recipients as $recipient) {
            $familyLineClient->push($recipient['line_user_id'], $text);
        }
        $notifiedCount++;
    }
    return $notifiedCount;
}

/**
 * 指定した安否確認枠($window)について、終了+猶予(NO_RESPONSE_GRACE_MINUTES)を過ぎていて、
 * かつまだ判定していない($checkDateの$windowLabelとしてwellness_check_alertsに記録が無い)利用者を対象に、
 * 枠開始〜現在時刻の間にinbound(利用者からの発言)が一件もなければ「応答なし」と判定し、
 * 寄り添いスタンダード以上の契約者にのみLINE通知する。プラン対象外でも判定結果自体は記録し、
 * 同じ枠を何度も再チェックしないようにする。
 * onboarded_atが枠の開始時刻より後(=その枠が始まった時点でまだ利用開始していなかった)の利用者は対象外にする。
 * これが無いと、枠の途中や終了後にオンボードした利用者を「(存在すらしていなかった時間帯に)応答が無かった」
 * と誤判定してしまう(次の枠からは正しく判定対象になる)
 */
function checkNoResponseAndNotify(
    PDO $pdo,
    LineClient $familyLineClient,
    SubscriptionRepository $subscriptionRepo,
    FamilyAccountRepository $familyRepo,
    array $window,
    DateTime $now
): int {
    $checkAt = (clone $now)->setTime($window['end'], 0, 0)->modify('+' . NO_RESPONSE_GRACE_MINUTES . ' minutes');
    if ($now < $checkAt) {
        return 0; // まだ猶予期間中
    }
    $windowStart = (clone $now)->setTime($window['start'], 0, 0);
    $checkDate = $now->format('Y-m-d');

    $stmt = $pdo->prepare(
        "SELECT u.id, u.display_name, u.full_name
         FROM users u
         WHERE u.status = 'active' AND u.line_user_id IS NOT NULL
           AND u.onboarded_at <= ?
           AND NOT EXISTS (
             SELECT 1 FROM conversations c
             WHERE c.user_id = u.id AND c.direction = 'inbound'
               AND c.created_at >= ? AND c.created_at <= ?
           )
           AND NOT EXISTS (
             SELECT 1 FROM wellness_check_alerts w
             WHERE w.user_id = u.id AND w.check_date = ? AND w.window_label = ?
           )"
    );
    $stmt->execute([
        $windowStart->format('Y-m-d H:i:s'),
        $windowStart->format('Y-m-d H:i:s'),
        $now->format('Y-m-d H:i:s'),
        $checkDate,
        $window['label'],
    ]);

    $notifiedCount = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $userId = (int) $row['id'];

        // プラン対象外でも、同じ枠を毎回再チェックしないよう判定結果は必ず記録する
        $pdo->prepare(
            'INSERT IGNORE INTO wellness_check_alerts (user_id, check_date, window_label) VALUES (?, ?, ?)'
        )->execute([$userId, $checkDate, $window['label']]);

        $planCode = $subscriptionRepo->getCurrentPlanCodeForUser($userId);
        if (!in_array($planCode, WELLNESS_NOTIFY_PLAN_CODES, true)) {
            continue; // 寄り添いベーシック等、対象外プランでは記録のみでLINE通知はしない
        }
        $recipients = $familyRepo->getNotifiableForUser($userId);
        if (empty($recipients)) {
            continue;
        }

        $displayName = $row['full_name'] ?: $row['display_name'] ?: 'ご利用者様';
        $windowText = $window['label'] === 'morning' ? '午前中' : '夜';
        $text = "【TAYORI】{$displayName}様、本日{$windowText}の時間帯でTAYORIとの会話がまだ確認できていません。"
            . "外出中や電話に気づいていないだけのことも多いですが、ご心配な場合は直接ご様子をご確認ください。";
        foreach ($recipients as $recipient) {
            $familyLineClient->push($recipient['line_user_id'], $text);
        }
        $notifiedCount++;
    }
    return $notifiedCount;
}

/**
 * schedules.is_medication=trueの日次/週次予定について、その時刻(scheduled_at)が来ていて、
 * かつ今日分のmedication_logsがまだ無い利用者へリマインドを送る。同じ時刻に複数の薬がある場合は
 * 1通にまとめて送る(利用者ごとにメッセージが連投されるのを避けるため)。
 * 深夜早朝ガード(QUIET_HOURS)の対象外(健康に関わるため、他の声かけと違って夜間でも止めない。
 * 元々深夜の時間帯に服薬予定を入れる利用者はまれだが、念のため対象外にはしていない)
 */
function sendMedicationReminders(PDO $pdo, LineClient $lineClient, MedicationLogRepository $medicationLogRepo, DateTime $now): int
{
    $stmt = $pdo->prepare(
        "SELECT s.id AS schedule_id, s.title, s.user_id, u.line_user_id
         FROM schedules s
         JOIN users u ON u.id = s.user_id
         WHERE s.is_medication = 1 AND s.status = 'upcoming'
           AND s.recurrence_type IN ('daily', 'weekly')
           AND s.scheduled_at IS NOT NULL AND TIME(s.scheduled_at) != '00:00:00'
           AND s.scheduled_at <= ?
           AND u.status = 'active' AND u.line_user_id IS NOT NULL
           AND NOT EXISTS (
             SELECT 1 FROM medication_logs m WHERE m.schedule_id = s.id AND m.log_date = CURDATE()
           )
         ORDER BY s.user_id, s.scheduled_at"
    );
    $stmt->execute([$now->format('Y-m-d H:i:s')]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        return 0;
    }

    $byUser = [];
    foreach ($rows as $row) {
        $userId = (int) $row['user_id'];
        $byUser[$userId]['line_user_id'] = $row['line_user_id'];
        $byUser[$userId]['schedules'][] = $row;
    }

    $sentCount = 0;
    foreach ($byUser as $userId => $data) {
        $titles = array_map(fn($s) => $s['title'], $data['schedules']);
        $text = count($titles) === 1
            ? "「{$titles[0]}」の時間だね。飲んだら教えてね。"
            : '「' . implode('』『', $titles) . '』の時間だね。飲んだら教えてね。';

        if ($lineClient->push($data['line_user_id'], $text)) {
            logOutbound($pdo, $userId, $text, true);
            $insertStmt = $pdo->prepare(
                'INSERT INTO medication_logs (schedule_id, user_id, log_date, reminder_sent_at, status)
                 VALUES (?, ?, CURDATE(), NOW(), "pending")'
            );
            foreach ($data['schedules'] as $s) {
                $insertStmt->execute([(int) $s['schedule_id'], $userId]);
            }
            $sentCount++;
        }
    }
    return $sentCount;
}

// 深夜早朝は利用者本人への声かけ(1・2)だけを止める。3(応答なし検知→家族通知)は、夜の安否確認枠
// (17:00-21:00)の判定タイミング(21:00+猶予)が夜間ガードに入ってしまうため、ここでexitはせず
// 通常通り最後まで実行する(利用者を起こすわけではなく、家族への通知なので夜間ガードの対象外でよい)
$isQuietHoursNow = isWithinQuietHours((string) QUIET_HOURS_START . ':00', (string) QUIET_HOURS_END . ':00', $now);

if ($isQuietHoursNow) {
    echo "[SKIP] quiet hours ({$now->format('H:i')} is within " . QUIET_HOURS_START . ":00-" . QUIET_HOURS_END . ":00), no proactive messages sent this run\n";
} else {
    // --- 1. 予定リマインド(前日) ---
    $stmt = $pdo->query(
        "SELECT s.id, s.title, s.location, s.scheduled_at, u.id AS user_id, u.line_user_id,
                u.display_name, u.companion_name, u.quiet_hours_start, u.quiet_hours_end
         FROM schedules s
         JOIN users u ON u.id = s.user_id
         WHERE s.status = 'upcoming' AND s.reminder_sent = 0 AND s.is_medication = 0 AND u.line_user_id IS NOT NULL
           AND DATE(s.scheduled_at) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)"
    );
    $reminderCount = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isWithinQuietHours($row['quiet_hours_start'], $row['quiet_hours_end'], $now)) {
            continue; // この利用者が申告した「静かにしていてほしい時間帯」。次回以降のバッチ実行に委ねる
        }
        $time = (new DateTime($row['scheduled_at']))->format('H:i');
        $companionName = $row['companion_name'] ?: 'たより';

        // AIにその人らしい言い回しで生成してもらう(内容の正確さは最優先で指示済み)。
        // 失敗時のみ、内容を確実に伝えられる固定の定型文にフォールバックする
        $text = $claudeClient->generateScheduleReminder(
            $row['title'],
            $time !== '00:00' ? $time : null,
            $row['location'],
            $companionName,
            (string) $row['display_name']
        );
        if ($text === '') {
            $text = "明日は「{$row['title']}」の予定でしたね";
            if ($time !== '00:00') {
                $text .= "({$time}〜)";
            }
            if (!empty($row['location'])) {
                $text .= "。場所は{$row['location']}です";
            }
            $text .= "。お忘れなく!";
        }

        if ($lineClient->push($row['line_user_id'], $text)) {
            logOutbound($pdo, (int) $row['user_id'], $text);
            $pdo->prepare('UPDATE schedules SET reminder_sent = 1 WHERE id = ?')->execute([$row['id']]);
            $reminderCount++;
        }
    }
    echo "[OK] schedule reminders sent: {$reminderCount}\n";

    // --- 1b. 予定リマインド(当日朝。前日分を見逃した場合の保険も兼ねる) ---
    $stmt = $pdo->query(
        "SELECT s.id, s.title, s.location, s.scheduled_at, u.id AS user_id, u.line_user_id,
                u.display_name, u.companion_name, u.quiet_hours_start, u.quiet_hours_end
         FROM schedules s
         JOIN users u ON u.id = s.user_id
         WHERE s.status = 'upcoming' AND s.same_day_reminder_sent = 0 AND s.is_medication = 0 AND u.line_user_id IS NOT NULL
           AND DATE(s.scheduled_at) = CURDATE()"
    );
    $sameDayReminderCount = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isWithinQuietHours($row['quiet_hours_start'], $row['quiet_hours_end'], $now)) {
            continue; // この利用者が申告した「静かにしていてほしい時間帯」。次回以降のバッチ実行に委ねる
        }
        $time = (new DateTime($row['scheduled_at']))->format('H:i');
        $companionName = $row['companion_name'] ?: 'たより';

        $text = $claudeClient->generateScheduleReminder(
            $row['title'],
            $time !== '00:00' ? $time : null,
            $row['location'],
            $companionName,
            (string) $row['display_name'],
            true
        );
        if ($text === '') {
            $text = "今日は「{$row['title']}」の予定でしたね";
            if ($time !== '00:00') {
                $text .= "({$time}〜)";
            }
            if (!empty($row['location'])) {
                $text .= "。場所は{$row['location']}です";
            }
            $text .= "。お忘れなく!";
        }

        if ($lineClient->push($row['line_user_id'], $text)) {
            logOutbound($pdo, (int) $row['user_id'], $text);
            $pdo->prepare('UPDATE schedules SET same_day_reminder_sent = 1 WHERE id = ?')->execute([$row['id']]);
            $sameDayReminderCount++;
        }
    }
    echo "[OK] same-day schedule reminders sent: {$sameDayReminderCount}\n";

    // --- 2. 気まぐれな声かけ(直近の会話有無によらず、友達付き合いのように一定確率で) ---
    // last_contact_at: この利用者との最新の「会話」時刻(inbound/outbound問わず)。一度も会話が無ければNULL。
    // 服薬リマインド等の事務的な一方通知(is_notification=1)は、返信を前提とせず送りっぱなしのものなので
    // ここには含めない(含めてしまうと、雑談の間隔判定・CHECKIN_WINDOWSの「会話成立」判定の両方が
    // 通知の頻度でずっと埋まってしまい、気まぐれ雑談も安否確認チェックインも発火しなくなるため)
    $stmt = $pdo->prepare(
        "SELECT u.id, u.line_user_id, u.display_name, u.companion_name, u.companion_gender, u.companion_persona,
                u.gender, u.quiet_hours_start, u.quiet_hours_end,
                MAX(c.created_at) AS last_contact_at
         FROM users u
         LEFT JOIN conversations c ON c.user_id = u.id AND c.is_notification = 0
         WHERE u.status = 'active' AND u.line_user_id IS NOT NULL
         GROUP BY u.id
         HAVING last_contact_at IS NULL OR last_contact_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)"
    );
    $stmt->execute([PROACTIVE_MIN_GAP_HOURS]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $window = activeCheckinWindow($now);

    $proactiveCount = 0;
    $guaranteedCount = 0;
    foreach ($candidates as $row) {
        if (isWithinQuietHours($row['quiet_hours_start'], $row['quiet_hours_end'], $now)) {
            continue; // この利用者が申告した「静かにしていてほしい時間帯」。次回以降のバッチ実行に委ねる
        }

        // 現在CHECKIN_WINDOWSの時間帯内で、かつこの利用者がその時間帯内でまだ一度も会話していなければ、
        // 通常の低確率抽選ではなく「この時間帯終了までに確実に送る」保証モードに切り替える
        // (残り実行回数の逆数を確率にすることで、外れ続けても最後の1回で確実に当たる)
        $isGuaranteed = false;
        $chance = PROACTIVE_CHANCE_PER_RUN;
        if ($window !== null) {
            $windowStart = (clone $now)->setTime($window['start'], 0, 0);
            $lastContactAt = $row['last_contact_at'] !== null ? new DateTime($row['last_contact_at'], new DateTimeZone('Asia/Tokyo')) : null;
            $windowAlreadyConfirmed = $lastContactAt !== null && $lastContactAt >= $windowStart;
            if (!$windowAlreadyConfirmed) {
                $isGuaranteed = true;
                $chance = 1 / remainingActiveRuns($now, $window['end']);
            }
        }

        if (mt_rand() / mt_getrandmax() > $chance) {
            continue; // 抽選漏れ。毎回送るわけではない、というのがポイントなので次回に委ねる
        }
        $summaries = $summaryRepo->getAllForUser((int) $row['id']);
        $topicCoverage = $topicCoverageRepo->getAllForUser((int) $row['id']);
        $personaFacts = ensureCompanionPersonaForBatchRow($row, $claudeClient, $userRepo);
        $companionName = $row['companion_name'] ?: 'たより';
        $hadPendingWorry = hasPendingUrgentSilenceAlert($pdo, (int) $row['id']);
        $recentExchanges = getRecentExchanges($pdo, (int) $row['id']);
        $text = $claudeClient->generateProactiveMessage(
            $summaries,
            $companionName,
            (string) $row['display_name'],
            $row['gender'],
            $isGuaranteed,
            $hadPendingWorry,
            $recentExchanges,
            $topicCoverage,
            $personaFacts
        );

        if ($text === '') {
            continue; // Claude呼び出し失敗時は無理に送らず、次回のバッチ実行に委ねる
        }

        if ($lineClient->push($row['line_user_id'], $text)) {
            logOutbound($pdo, (int) $row['id'], $text);
            $proactiveCount++;
            if ($isGuaranteed) {
                $guaranteedCount++;
                echo "[CHECKIN] user {$row['id']}: window {$window['start']}:00-{$window['end']}:00 had no conversation yet, forced check-in sent\n";
            }
        }
    }
    echo "[OK] proactive check-ins sent: {$proactiveCount} (checkin-guaranteed: {$guaranteedCount})\n";
}

// --- 3. 安否確認枠(CHECKIN_WINDOWS)で応答が無かった利用者を検知し、家族へ通知 ---
// 夜間ガード中(1・2がスキップされる時間帯)でもここは実行する。夜枠(17:00-21:00)の判定タイミングは
// 21:00+NO_RESPONSE_GRACE_MINUTES=22:00で、夜間ガード(21:00-08:00)の中に入るため
$noResponseNotifiedCount = 0;
foreach (CHECKIN_WINDOWS as $checkinWindow) {
    $noResponseNotifiedCount += checkNoResponseAndNotify($pdo, $familyLineClient, $subscriptionRepo, $familyRepo, $checkinWindow, $now);
}
echo "[OK] no-response family notifications sent: {$noResponseNotifiedCount}\n";

// --- 4. 沈黙(アクティブ時間帯基準でURGENT_SILENCE_HOURS超過)の即時検知→家族への危機感通知 ---
// 3(CHECKIN_WINDOWSの固定枠)より速い経路。発生時刻を問わず、毎回の実行(夜間ガード中も含む)でチェックする
$urgentNotifiedCount = checkUrgentSilenceAndNotify($pdo, $lineClient, $familyLineClient, $claudeClient, $subscriptionRepo, $familyRepo, $now);
echo "[OK] urgent silence family notifications sent: {$urgentNotifiedCount}\n";

// --- 5. 服薬リマインド(is_medication=trueの予定の時刻到来検知) ---
// 健康に関わるため、1・2の深夜早朝ガードとは独立に毎回の実行でチェックする
$medicationReminderCount = sendMedicationReminders($pdo, $lineClient, $medicationLogRepo, $now);
echo "[OK] medication reminders sent: {$medicationReminderCount}\n";

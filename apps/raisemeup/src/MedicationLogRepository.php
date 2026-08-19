<?php
class MedicationLogRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * その日対象になっている服薬(schedules.is_medication=true、当日occurrenceがあるもの)の状況一覧を返す。
     * まだリマインド時刻が来ていないものも含め、その日の全件を返す(会話プロンプトへの状況表示・
     * 「もう飲んだ」発言との突き合わせの両方に使う)。
     * @return array<int, array{schedule_id:int, title:string, scheduled_time:string, status:string}>
     */
    public function getTodayStatusForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.id AS schedule_id, s.title, TIME(s.scheduled_at) AS scheduled_time,
                    COALESCE(m.status, 'pending') AS status
             FROM schedules s
             LEFT JOIN medication_logs m ON m.schedule_id = s.id AND m.log_date = CURDATE()
             WHERE s.user_id = ? AND s.is_medication = 1 AND s.status = 'upcoming'
               AND s.recurrence_type IN ('daily', 'weekly')
               AND s.scheduled_at IS NOT NULL AND TIME(s.scheduled_at) != '00:00:00'
             ORDER BY TIME(s.scheduled_at) ASC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 会話プロンプトに載せる「今日のお薬状況」の1行テキストを組み立てる
    public static function formatStatusLine(array $row): string
    {
        $statusLabel = ['pending' => '未確認', 'taken' => '確認済み', 'missed' => '未確認(時間経過)'][$row['status']] ?? '未確認';
        $time = substr((string) $row['scheduled_time'], 0, 5);
        return "・{$row['title']}({$time}) / {$statusLabel}";
    }

    /**
     * 本人からの「飲んだ」発言をもとに服薬確認を記録する。$titleは当日分のschedules.titleと完全一致する前提
     * (getTodayStatusForUserで渡した一覧からClaudeに選ばせているため)。まだリマインド未送信(medication_logsの
     * 行が無い)状態で本人が自己申告した場合も、その場でtaken行を作成する(reminder_sent_atはNULLのまま)
     */
    public function markTaken(int $userId, string $title): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM schedules
             WHERE user_id = ? AND title = ? AND is_medication = 1 AND status = 'upcoming'"
        );
        $stmt->execute([$userId, $title]);
        $scheduleId = $stmt->fetchColumn();
        if ($scheduleId === false) {
            return; // 存在しない/既に対象外になった薬名(古い会話履歴からの誤抽出など)は無視する
        }

        $this->pdo->prepare(
            "INSERT INTO medication_logs (schedule_id, user_id, log_date, status, confirmed_at)
             VALUES (?, ?, CURDATE(), 'taken', NOW())
             ON DUPLICATE KEY UPDATE status = 'taken', confirmed_at = COALESCE(confirmed_at, NOW())"
        )->execute([(int) $scheduleId, $userId]);
    }

    /**
     * マイページ「服薬状況」表示用。直近$days日分の記録を新しい順で返す(タイトル・服薬時刻はschedulesから)。
     * @return array<int, array{title:string, scheduled_time:string, log_date:string, status:string}>
     */
    public function getRecentForUser(int $userId, int $days = 14): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT m.log_date, m.status, s.title, TIME(s.scheduled_at) AS scheduled_time
             FROM medication_logs m
             JOIN schedules s ON s.id = m.schedule_id
             WHERE m.user_id = ? AND m.log_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             ORDER BY m.log_date DESC, s.scheduled_at DESC"
        );
        $stmt->execute([$userId, $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 送信済みだが確認が取れなかった前日以前の分を、まとめて「見送り」として確定する(regenerate_summaries.php用)
    public function markStalePendingAsMissed(): int
    {
        return $this->pdo->exec(
            "UPDATE medication_logs SET status = 'missed' WHERE status = 'pending' AND log_date < CURDATE()"
        );
    }
}

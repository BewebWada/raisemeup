<?php
class ScheduleRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * 要約(概要)だけでは個別の予定を正確に答えられないため、AIに渡す「正確な一覧」として使う。
     * $limitを指定すると、日付が近い順(未確定日は末尾)にその件数までしか返さない。
     * リアルタイムの会話プロンプトに載せる分は、予定がどれだけ蓄積されても件数の上限を必ず設けること
     * (上限なしで呼ぶのは、出力が圧縮される要約バッチ生成時のみに限定する)。
     */
    public function getUpcomingDetailsByUserId(int $userId, ?int $limit = null): array
    {
        $sql = "SELECT title, scheduled_at, scheduled_end_at, scheduled_date_text, location
                FROM schedules WHERE user_id = ? AND status = 'upcoming'
                ORDER BY scheduled_at IS NULL, scheduled_at ASC";
        $params = [$userId];
        if ($limit !== null) {
            $sql .= ' LIMIT ?';
            $params[] = $limit;
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 1件の予定を "・タイトル / 日付 / 場所" のような1行のテキストに整形する(要約生成・プロンプト注入の両方で使う共通フォーマット)
    public static function formatScheduleLine(array $row): string
    {
        // scheduled_at(システム側で確定計算した絶対日付)を必ず優先する。scheduled_date_text(「明日」等、
        // 会話に出た生の言い回し)は、日付が解決できなかった場合(「そのうち」等)のフォールバックにのみ使う。
        // 生の言い回しを優先すると、要約(バッチで生成され長時間使い回される)に「明日」のような相対表現が
        // そのまま残ってしまい、後日読むと不正確になる。
        $dateLabel = !empty($row['scheduled_at'])
            ? self::formatDateLabel($row['scheduled_at'])
            : ($row['scheduled_date_text'] ?: '日付未定');
        if (!empty($row['scheduled_end_at'])) {
            $dateLabel .= '〜' . self::formatDateLabel($row['scheduled_end_at']);
        }
        $line = "・{$row['title']} / {$dateLabel}";
        if (!empty($row['location'])) {
            $line .= " / 場所:{$row['location']}";
        }
        if (!empty($row['status']) && $row['status'] !== 'upcoming') {
            $statusLabel = ['completed' => '完了済み', 'cancelled' => '中止/未確定'][$row['status']] ?? $row['status'];
            $line .= " / {$statusLabel}";
        }
        return $line;
    }

    // DATETIME文字列を「7月11日(土)」のような自然な日本語表記に変換する。今年と異なる年のみ「年」を付け、
    // 時刻が指定されている(00:00:00のまま=時刻不明、ではない)場合だけ時刻も付け加える。
    private static function formatDateLabel(string $datetime): string
    {
        $dt = new DateTime($datetime, new DateTimeZone('Asia/Tokyo'));
        $currentYear = (int) (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y');
        $weekdays = ['日', '月', '火', '水', '木', '金', '土'];

        $yearPart = ((int) $dt->format('Y') !== $currentYear) ? $dt->format('Y') . '年' : '';
        $label = $yearPart . $dt->format('n月j日') . '(' . $weekdays[(int) $dt->format('w')] . ')';
        if ($dt->format('H:i:s') !== '00:00:00') {
            $label .= ' ' . $dt->format('H:i');
        }
        return $label;
    }

    /**
     * リアルタイムプロンプトの上限(件数)からあふれた予定や、既に完了した過去の予定を
     * 「必要なときだけ」探すためのキーワード検索。件数上限は設けるが、期間・statusは絞らない。
     * $terms は複数キーワード(いずれか1つでも一致すればヒット)。単純な部分一致なので、
     * 呼び出し側で類義語・言い換えを含めた複数語を渡すこと。
     */
    public function search(int $userId, array $terms, int $limit = 10): array
    {
        $terms = array_values(array_filter(array_map('trim', $terms), fn($t) => $t !== ''));
        if (empty($terms)) {
            return [];
        }

        $conditions = [];
        $params = [$userId];
        foreach ($terms as $term) {
            $like = '%' . $term . '%';
            $conditions[] = '(title LIKE ? OR location LIKE ? OR scheduled_date_text LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        $stmt = $this->pdo->prepare(
            "SELECT title, scheduled_at, scheduled_end_at, scheduled_date_text, location, status
             FROM schedules
             WHERE user_id = ? AND (" . implode(' OR ', $conditions) . ")
             ORDER BY scheduled_at IS NULL, scheduled_at DESC
             LIMIT ?"
        );
        $params[] = $limit;
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function upsert(int $userId, array $schedule, int $conversationId): void
    {
        $title = trim($schedule['title'] ?? '');
        if ($title === '') {
            return;
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, scheduled_at, scheduled_end_at, scheduled_date_text, location FROM schedules
             WHERE user_id = ? AND title = ? AND status = 'upcoming'"
        );
        $stmt->execute([$userId, $title]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        $scheduledAt = $this->resolveDate($schedule['date_spec'] ?? null);
        if ($scheduledAt !== null) {
            // 今回時刻の言及が無くても、日付が変わっていなければ既存の時刻を引き継ぐ(なければ時刻不明=00:00:00)
            $scheduledAt = $this->applyTime($scheduledAt, $schedule['time_spec'] ?? null, $existing['scheduled_at'] ?? null);
        }
        $scheduledEndAt = $this->resolveDate($schedule['date_spec_end'] ?? null);
        if ($scheduledEndAt !== null && $scheduledAt !== null && $scheduledEndAt < $scheduledAt) {
            // 開始日より前の終了日は矛盾しているので採用しない(月の推定がズレた場合の保険)
            $scheduledEndAt = null;
        }
        $dateText = $schedule['date_text'] ?? null;
        $location = $schedule['location'] ?? null;

        if ($existing) {
            // 今回言及されなかった項目(null)は既存の値を残す(古い情報で上書きしない)
            $this->pdo->prepare(
                'UPDATE schedules SET scheduled_at = ?, scheduled_end_at = ?, scheduled_date_text = ?, location = ?, source_conversation_id = ?
                 WHERE id = ?'
            )->execute([
                $scheduledAt ?? $existing['scheduled_at'],
                $scheduledEndAt ?? $existing['scheduled_end_at'],
                $dateText ?? $existing['scheduled_date_text'],
                $location ?? $existing['location'],
                $conversationId,
                $existing['id'],
            ]);
            return;
        }

        $this->pdo->prepare(
            'INSERT INTO schedules (user_id, title, scheduled_at, scheduled_end_at, scheduled_date_text, location, status, source_conversation_id)
             VALUES (?, ?, ?, ?, ?, ?, "upcoming", ?)'
        )->execute([$userId, $title, $scheduledAt, $scheduledEndAt, $dateText, $location, $conversationId]);
    }

    /**
     * Claudeは「明日」「来週の火曜日」等の表現を{unit, amount, weekday, day_of_month}に分類するだけで、
     * 実際の日付計算(足し算)は行わない。計算はここでPHPが確定的に行う(モデルの計算ミスを避けるため)。
     * 時刻は00:00:00(不明)のまま返す。時刻の反映(既存値の引き継ぎ含む)は呼び出し元でapplyTime()を使う。
     */
    private function resolveDate(?array $spec): ?string
    {
        if (!$spec || empty($spec['unit'])) {
            return null;
        }

        $now = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
        $amount = (int) ($spec['amount'] ?? 0);
        if ($amount < 0 || $amount > 104) { // 異常値(2年分の週数を超える等)は信用しない
            return null;
        }

        $target = null;

        switch ($spec['unit']) {
            case 'day':
                $target = (clone $now)->modify("+{$amount} days");
                break;

            case 'week':
                $isoDow = (int) $now->format('N'); // 1(月)-7(日)
                $mondayThisWeek = (clone $now)->modify('-' . ($isoDow - 1) . ' days');
                $targetMonday = (clone $mondayThisWeek)->modify('+' . ($amount * 7) . ' days');

                $weekday = $spec['weekday'] ?? null;
                $weekday = ($weekday === null || $weekday < 0 || $weekday > 6)
                    ? (int) $now->format('w') // 曜日未指定なら今日と同じ曜日とみなす
                    : (int) $weekday;
                $isoWeekdayOffset = $weekday === 0 ? 6 : $weekday - 1; // 月曜始まりのオフセットに変換

                $target = (clone $targetMonday)->modify("+{$isoWeekdayOffset} days");
                break;

            case 'month':
                if (!isset($spec['day_of_month'])) {
                    return null; // 日にちが特定できなければ諦める
                }
                $day = (int) $spec['day_of_month'];
                if ($day < 1 || $day > 31) {
                    return null;
                }
                $firstOfTargetMonth = (clone $now)->modify('first day of this month')->modify("+{$amount} months");
                if ($day > (int) $firstOfTargetMonth->format('t')) {
                    return null; // その月に存在しない日(例:2月30日)
                }
                $target = (clone $firstOfTargetMonth)->modify('+' . ($day - 1) . ' days');
                break;

            case 'absolute':
                $target = $this->resolveAbsoluteDate($spec, $now);
                if ($target === null) {
                    return null;
                }
                break;

            default:
                return null;
        }

        // "upcoming"な予定に過去日付が入るのは想定外(absoluteで過去の年が明言された場合など)なので弾く
        $todayMidnight = (clone $now)->setTime(0, 0, 0);
        if ($target < $todayMidnight) {
            return null;
        }

        return $target->format('Y-m-d 00:00:00');
    }

    /**
     * Claudeが「昼の2時」→14時のようにAM/PMを24時間表記へ変換した結果をそのまま信用するが、
     * 範囲外の値(モデルの出力ミス)は0時0分(時刻不明)にフォールバックする。
     * @return array{0: int, 1: int} [hour, minute]
     */
    private function resolveTime(?array $timeSpec): array
    {
        if (!$timeSpec || !isset($timeSpec['hour']) || $timeSpec['hour'] === null) {
            return [0, 0];
        }

        $hour = (int) $timeSpec['hour'];
        if ($hour < 0 || $hour > 23) {
            return [0, 0];
        }

        $minute = 0;
        if (isset($timeSpec['minute']) && $timeSpec['minute'] !== null) {
            $m = (int) $timeSpec['minute'];
            if ($m >= 0 && $m <= 59) {
                $minute = $m;
            }
        }

        return [$hour, $minute];
    }

    /**
     * 今回の会話で新しい時刻の言及が無くても(time_specがnull/hour未指定)、
     * 日付が変わっていなければ既存の時刻を引き継ぐ(他のフィールドと同じ「言及されなければ上書きしない」方針)。
     * 日付そのものが変わった場合は、古い日付の時刻を新しい日付にそのまま引き継ぐと誤解を招くため時刻不明に戻す。
     */
    private function applyTime(string $resolvedDate, ?array $timeSpec, ?string $existingDateTime): string
    {
        [$hour, $minute] = $this->resolveTime($timeSpec);
        $datePart = substr($resolvedDate, 0, 10);

        if ($hour === 0 && $minute === 0 && $existingDateTime !== null && substr($existingDateTime, 0, 10) === $datePart) {
            return $datePart . ' ' . substr($existingDateTime, 11, 8);
        }

        return sprintf('%s %02d:%02d:00', $datePart, $hour, $minute);
    }

    // 「8月15日」のように年が省略された絶対日付は、今日以降で最も近い年(今年 or 来年)を採用する。
    // 「13日」のように月まで省略された場合は、今日以降で最も近い月(今月〜13ヶ月先まで探索)を採用する。
    private function resolveAbsoluteDate(array $spec, DateTime $now): ?DateTime
    {
        $day = (int) ($spec['day'] ?? 0);
        if ($day < 1 || $day > 31) {
            return null;
        }

        $todayMidnight = (clone $now)->setTime(0, 0, 0);
        $explicitYear = isset($spec['year']) && $spec['year'] ? (int) $spec['year'] : null;
        $explicitMonth = isset($spec['month']) && $spec['month'] ? (int) $spec['month'] : null;

        if ($explicitMonth !== null) {
            if ($explicitMonth < 1 || $explicitMonth > 12) {
                return null;
            }

            if ($explicitYear !== null) {
                $thisYear = (int) $now->format('Y');
                if ($explicitYear < $thisYear || $explicitYear > $thisYear + 100) {
                    return null; // 過去の年や異常に先の年は信用しない
                }
                return $this->buildDate($explicitYear, $explicitMonth, $day); // 過去日付なら呼び出し元で弾かれる
            }

            // 年が言われていない場合、今年のその月日が今日以降ならそれを、既に過ぎていれば来年を採用する
            $thisYearCandidate = $this->buildDate((int) $now->format('Y'), $explicitMonth, $day);
            if ($thisYearCandidate !== null && $thisYearCandidate >= $todayMidnight) {
                return $thisYearCandidate;
            }
            return $this->buildDate((int) $now->format('Y') + 1, $explicitMonth, $day);
        }

        // 月も言われていない場合(例:「13日」だけ):今月から順に探し、今日以降で最初に実在する日を採用する
        $baseYear = (int) $now->format('Y');
        $baseMonth = (int) $now->format('n');
        for ($i = 0; $i <= 12; $i++) {
            $totalMonth = $baseMonth - 1 + $i;
            $year = $baseYear + intdiv($totalMonth, 12);
            $month = ($totalMonth % 12) + 1;
            $candidate = $this->buildDate($year, $month, $day);
            if ($candidate !== null && $candidate >= $todayMidnight) {
                return $candidate;
            }
        }
        return null;
    }

    // Y-n-jは無効な日付(2/30など)を翌月に繰り上げてしまうことがあるため、往復変換で実在の日付か検証する
    private function buildDate(int $year, int $month, int $day): ?DateTime
    {
        $dt = DateTime::createFromFormat('Y-n-j H:i:s', "{$year}-{$month}-{$day} 00:00:00");
        if ($dt === false || (int) $dt->format('Y') !== $year || (int) $dt->format('n') !== $month || (int) $dt->format('j') !== $day) {
            return null;
        }
        return $dt;
    }
}

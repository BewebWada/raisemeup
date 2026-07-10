<?php
class SeedRunner
{
    /**
     * @param PDO $pdo
     * @param string $table 対象テーブル名
     * @param string $uniqueKey UPSERT判定に使うカラム名(例: 'pattern_name')
     * @param array $records JSONデコード済みの配列(連想配列の配列)
     */
    public static function upsert(PDO $pdo, string $table, string $uniqueKey, array $records): void
    {
        if (empty($records)) return;

        $columns = array_keys($records[0]);
        $checkStmt = $pdo->prepare("SELECT id FROM {$table} WHERE {$uniqueKey} = :key");

        $insertCols = implode(', ', $columns);
        $insertPlaceholders = implode(', ', array_map(fn($c) => ":{$c}", $columns));
        $insertStmt = $pdo->prepare("INSERT INTO {$table} ({$insertCols}) VALUES ({$insertPlaceholders})");

        $updateAssignments = implode(', ', array_map(fn($c) => "{$c} = :{$c}", array_diff($columns, [$uniqueKey])));
        $updateStmt = $pdo->prepare("UPDATE {$table} SET {$updateAssignments} WHERE {$uniqueKey} = :{$uniqueKey}");

        $inserted = 0;
        $updated = 0;

        foreach ($records as $record) {
            $params = [];
            foreach ($record as $col => $val) {
                // JSON型カラム(配列)は文字列化して渡す
                $params[":{$col}"] = is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : $val;
            }

            $checkStmt->execute([':key' => $record[$uniqueKey]]);
            if ($checkStmt->fetch()) {
                $updateStmt->execute($params);
                $updated++;
            } else {
                $insertStmt->execute($params);
                $inserted++;
            }
        }

        echo "[OK] {$table} seed: {$inserted} inserted, {$updated} updated\n";
    }
}

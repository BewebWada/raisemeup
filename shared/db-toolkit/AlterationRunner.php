<?php
class AlterationRunner
{
    // ALTER TABLE ... ADD COLUMN IF NOT EXISTS 等、既に適用済みでも安全に再実行できる文だけを想定する
    public static function run(PDO $pdo, array $alterations): void
    {
        foreach ($alterations as $name => $sql) {
            $pdo->exec($sql);
            echo "[OK] alteration applied: {$name}\n";
        }
    }
}

<?php
class MigrationRunner
{
    public static function run(PDO $pdo, array $tables): void
    {
        foreach ($tables as $name => $sql) {
            $pdo->exec($sql);
            echo "[OK] table checked/created: {$name}\n";
        }
    }
}

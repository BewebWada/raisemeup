<?php
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/MigrationRunner.php';
require_once __DIR__ . '/../../../shared/db-toolkit/AlterationRunner.php';
require_once __DIR__ . '/../../../shared/db-toolkit/SeedRunner.php';

$config = require __DIR__ . '/config.php';
$pdo = Database::connect($config);

// --- 1. テーブル作成 ---
$tables = require __DIR__ . '/schema/tables.php';
MigrationRunner::run($pdo, $tables);

// --- 2. 既存テーブルへのカラム追加等 ---
$alterations = require __DIR__ . '/schema/alterations.php';
AlterationRunner::run($pdo, $alterations);

// --- 3. シード投入(manifest.phpに定義された全ファイルを処理) ---
$manifest = require __DIR__ . '/seeds/manifest.php';

foreach ($manifest as $seedFileName => $meta) {
    $jsonPath = __DIR__ . "/seeds/{$seedFileName}.json";
    if (!file_exists($jsonPath)) {
        echo "[SKIP] seed file not found: {$seedFileName}.json\n";
        continue;
    }
    $records = json_decode(file_get_contents($jsonPath), true);
    SeedRunner::upsert($pdo, $meta['table'], $meta['unique_key'], $records);
}

echo "[DONE] migration complete for: raisemeup\n";

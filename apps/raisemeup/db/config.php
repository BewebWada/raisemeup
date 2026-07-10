<?php
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';
Env::load(__DIR__ . '/../../../.env');

return [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'dbname' => getenv('RAISEMEUP_DB_NAME') ?: 'raisemeup',
    'user' => getenv('RAISEMEUP_DB_USER'),
    'pass' => getenv('RAISEMEUP_DB_PASS'),
];

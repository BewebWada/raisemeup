<?php
// 'シードJSONファイル名(拡張子なし)' => ['table' => テーブル名, 'unique_key' => ユニークキーのカラム名]
return [
    'risk_patterns' => ['table' => 'risk_patterns', 'unique_key' => 'pattern_name'],
    'plans' => ['table' => 'plans', 'unique_key' => 'code'],
];

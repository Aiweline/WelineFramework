<?php
// 一次性脚本：将 m_w_ab_test.traffic_split 改为 VARCHAR(255)，便于 setup:upgrade 通过
$env = include __DIR__ . '/app/etc/env.php';
$db = $env['db']['master'] ?? [];
$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    $db['host'] ?? '127.0.0.1',
    $db['port'] ?? 5432,
    $db['database'] ?? ''
);
$pdo = new PDO($dsn, $db['username'] ?? '', $db['password'] ?? '');
$pdo->exec("ALTER TABLE public.m_w_ab_test ALTER COLUMN traffic_split TYPE VARCHAR(255) USING traffic_split::text");
echo "ALTER traffic_split to VARCHAR(255) done.\n";

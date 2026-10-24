<?php

declare(strict_types=1);

/**
 * T46q 静态验收：BotToAgentMigrator 不再 DROP 表；
 * 新旧表并存且新表为空时按列交集 INSERT ... SELECT，旧表保留；失败只 warning。
 */

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);

$file = BP . 'app/code/Weline/Agent/Setup/BotToAgentMigrator.php';
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$check(is_file($file), 'missing file: ' . $file);

if (is_file($file)) {
    $lintOutput = [];
    $lintExit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $lintOutput, $lintExit);
    $lintText = implode(PHP_EOL, $lintOutput);
    $check($lintExit === 0 && !str_contains($lintText, 'Errors parsing'), 'php -l failed: ' . $lintText);

    $source = (string) file_get_contents($file);
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }

    $check(!preg_match('/dropTable|DROP\s+TABLE/i', $code), 'dropTable/DROP TABLE must be removed');
    $check(str_contains($code, 'RENAME TABLE'), 'RENAME TABLE path must be kept when only the old table exists');
    $check(str_contains($code, 'INSERT INTO'), 'column-intersection INSERT path is missing');
    $check(str_contains($code, 'getTableColumns'), 'column introspection via connector is missing');
    $check(str_contains($code, 'commonColumns'), 'commonColumns() helper is missing');
    $check(str_contains($code, '$this->migrateTable($db, $from, $to)'), 'migrate() must delegate to migrateTable()');
    $check(str_contains($code, 'w_log_warning'), 'copy failures must be logged as warnings');

    require_once BP . 'app/autoload.php';
    $class = 'Weline\\Agent\\Setup\\BotToAgentMigrator';
    $check(class_exists($class), 'migrator class not loadable');
    if (class_exists($class)) {
        $ref = new ReflectionClass($class);
        $map = $ref->getConstant('TABLE_MAP');
        $check(is_array($map) && count($map) === 9, 'TABLE_MAP must keep all 9 legacy tables');
        $check(($map['weline_bot_role'] ?? null) === 'weline_agent_role', 'TABLE_MAP role mapping changed');
        $check($ref->hasMethod('commonColumns'), 'commonColumns() missing');
        $check($ref->hasMethod('warn'), 'warn() missing');
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'T46Q AGENT-MIGRATOR PASS', PHP_EOL;

<?php

declare(strict_types=1);

/**
 * T46o 静态验收（3 文件 DDL 清零 + 1 个书面例外）：
 * - Shipping / Dropship / Promotion 的 Setup/Upgrade.php：裸 SQL DDL 模式必须为 0；
 * - I18n/Setup/Upgrade.php：保留唯一书面例外——PostgreSQL 部分覆盖索引
 *   idx_locale_null_source_covering（CREATE INDEX ... INCLUDE (word, translate)
 *   WHERE (source_module IS NULL OR source_module = '')）。SchemaParser #[Index]
 *   不支持 INCLUDE/WHERE，T46o 不覆盖该索引；
 * - 4 文件 php -l 通过；注释不参与匹配。
 *
 * 说明：本验收不代表 4/4 DDL 清零，而是 3/3 清零 + 1 个显式 ALLOWED_EXCEPTION。
 */

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);

$i18nFile = BP . 'app/code/Weline/I18n/Setup/Upgrade.php';
$strictFiles = [
    BP . 'app/code/Weline/Shipping/Setup/Upgrade.php',
    BP . 'app/code/Weline/Dropship/Setup/Upgrade.php',
    BP . 'app/code/Weline/Promotion/Setup/Upgrade.php',
];

$createIndexPattern = '/create\s+(?:unique\s+)?index/i';
$nonCreatePatterns = [
    'alter table' => '/alter\s+table/i',
    'drop table' => '/drop\s+table/i',
    'drop index' => '/drop\s+index/i',
    'drop column' => '/drop\s+column/i',
    'add primary key' => '/add\s+primary\s+key/i',
    'drop constraint' => '/drop\s+constraint/i',
    'drop sequence' => '/drop\s+sequence/i',
];
$allPatterns = ['create index' => $createIndexPattern] + $nonCreatePatterns;

$i18nExceptionMarkers = [
    'CREATE INDEX',
    'INCLUDE (word, translate)',
    "WHERE (source_module IS NULL OR source_module = '')",
];

$failures = [];
$stripComments = static function (string $source): string {
    $out = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
};

$files = array_merge([$i18nFile], $strictFiles);
foreach ($files as $file) {
    if (!is_file($file)) {
        $failures[] = 'missing file: ' . str_replace(BP, '', $file);
    }
}

$strictClean = 0;
$allowedExceptions = 0;

foreach ($files as $file) {
    if (!is_file($file)) {
        continue;
    }

    $lintOutput = [];
    $lintExit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $lintOutput, $lintExit);
    $lintText = implode(PHP_EOL, $lintOutput);
    if ($lintExit !== 0 || str_contains($lintText, 'Errors parsing')) {
        $failures[] = 'php -l failed: ' . str_replace(BP, '', $file) . PHP_EOL . $lintText;
        continue;
    }

    $code = $stripComments((string) file_get_contents($file));
    $relative = str_replace(BP, '', $file);

    if ($file === $i18nFile) {
        $createIndexCount = preg_match_all($createIndexPattern, $code);
        if ($createIndexCount !== 1) {
            $failures[] = 'I18n allowed exception must be exactly one CREATE INDEX, found ' . $createIndexCount;
        }
        foreach ($i18nExceptionMarkers as $marker) {
            if (!str_contains($code, $marker)) {
                $failures[] = 'I18n allowed exception changed: missing "' . $marker . '"';
            }
        }
        foreach ($nonCreatePatterns as $name => $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $failures[] = 'unexpected raw SQL DDL (' . $name . ') in ' . $relative;
            }
        }
        ++$allowedExceptions;
        echo 'ALLOWED_EXCEPTION I18n partial covering index: CREATE INDEX idx_locale_null_source_covering '
            , '(INCLUDE (word, translate), WHERE source_module IS NULL OR source_module = \'\')', PHP_EOL;
        continue;
    }

    $clean = true;
    foreach ($allPatterns as $name => $pattern) {
        if (preg_match($pattern, $code, $match, PREG_OFFSET_CAPTURE) === 1) {
            $line = substr_count(substr($code, 0, (int) $match[0][1]), "\n") + 1;
            $failures[] = 'raw SQL DDL remains in ' . $relative . ' line ~' . $line . ': ' . $match[0][0];
            $clean = false;
        }
    }
    if ($clean) {
        ++$strictClean;
    }
    echo 'OK ', $relative, ' (php -l + no raw SQL DDL)', PHP_EOL;
}

if ($strictClean !== count($strictFiles)) {
    $failures[] = 'strict DDL-zero files: ' . $strictClean . '/' . count($strictFiles);
}
if ($allowedExceptions !== 1) {
    $failures[] = 'allowed exceptions: ' . $allowedExceptions . '/1';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'SUMMARY strict DDL-zero=', $strictClean, '/', count($strictFiles), '; allowed_exception=', $allowedExceptions, PHP_EOL;
echo 'T46O SETUP-DDL PASS', PHP_EOL;

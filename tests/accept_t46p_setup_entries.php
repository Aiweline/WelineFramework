<?php

declare(strict_types=1);

/**
 * T46p 静态验收：
 * - 8 个 Setup 文件不再含旧 `$model->setup()/install()/upgrade()` 入口；
 * - 8 文件 php -l 通过，且文件仍保留 setup() 钩子（未被删空）；
 * - 注释不参与匹配。
 */

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);

$files = [
    BP . 'app/code/Weline/B2B/Setup/Upgrade.php',
    BP . 'app/code/Weline/I18n/Setup/Upgrade.php',
    BP . 'app/code/Weline/Inventory/Setup/Upgrade.php',
    BP . 'app/code/Weline/Payment/Setup/Install.php',
    BP . 'app/code/Weline/Payment/Setup/Upgrade.php',
    BP . 'app/code/Weline/Shipping/Setup/Upgrade.php',
    BP . 'app/code/Weline/TwoFactorAuth/Setup/Install.php',
    BP . 'app/code/Weline/Websites/Setup/Upgrade.php',
];

$entryPattern = '/->\s*(?:setup|install|upgrade)\s*\(/i';

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

foreach ($files as $file) {
    $relative = str_replace(BP, '', $file);
    if (!is_file($file)) {
        $failures[] = 'missing file: ' . $relative;
        continue;
    }

    $lintOutput = [];
    $lintExit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $lintOutput, $lintExit);
    $lintText = implode(PHP_EOL, $lintOutput);
    if ($lintExit !== 0 || str_contains($lintText, 'Errors parsing')) {
        $failures[] = 'php -l failed: ' . $relative . PHP_EOL . $lintText;
        continue;
    }

    $code = $stripComments((string) file_get_contents($file));
    if (!str_contains($code, 'function setup(')) {
        $failures[] = 'setup() hook removed/emptied: ' . $relative;
    }
    if (preg_match($entryPattern, $code, $match, PREG_OFFSET_CAPTURE) === 1) {
        $line = substr_count(substr($code, 0, (int) $match[0][1]), "\n") + 1;
        $failures[] = 'legacy setup entry remains in ' . $relative . ' line ~' . $line . ': ' . $match[0][0];
        continue;
    }

    echo 'OK ', $relative, ' (php -l + no legacy setup entry)', PHP_EOL;
}

if (count($files) !== 8) {
    $failures[] = 'expected 8 files, got ' . count($files);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'T46P SETUP-ENTRIES PASS', PHP_EOL;

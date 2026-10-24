<?php
declare(strict_types=1);

/**
 * T2 验收：SocialManagementQueryProvider 暴露 getScopeSocialPhone / saveScopeSocialPhone，
 * 注入 SocialScopePhoneConfig，且语法有效。
 */
$root = \dirname(__DIR__);
$file = $root . '/app/code/Weline/Social/extends/module/Weline_Framework/Query/SocialManagementQueryProvider.php';
$fail = static function (string $msg): void {
    fwrite(STDERR, 'T2-FAIL: ' . $msg . PHP_EOL);
    exit(1);
};

if (!\is_file($file)) {
    $fail('file missing');
}
$src = (string)\file_get_contents($file);
foreach ([
    'use Weline\\Social\\Service\\SocialScopePhoneConfig;',
    'ObjectManager::getInstance(SocialScopePhoneConfig::class)',
    "'getScopeSocialPhone' =>",
    "'saveScopeSocialPhone' =>",
    "['name' => 'getScopeSocialPhone'",
    "['name' => 'saveScopeSocialPhone'",
] as $needle) {
    if (!\str_contains($src, $needle)) {
        $fail('missing: ' . $needle);
    }
}

\exec(\escapeshellarg(PHP_BINARY) . ' -l ' . \escapeshellarg($file) . ' 2>&1', $out, $code);
if ($code !== 0) {
    $fail('php -l failed: ' . \implode(' ', $out));
}

echo 'T2-OK' . PHP_EOL;
exit(0);

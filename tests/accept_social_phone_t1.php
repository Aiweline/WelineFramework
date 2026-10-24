<?php
declare(strict_types=1);

/**
 * T1 验收：SocialScopePhoneConfig 存在、语法有效、关键方法与常量齐全。
 * 不依赖数据库（DB 行为在集成验收用真机页面验证）。
 */
$root = \dirname(__DIR__);
$file = $root . '/app/code/Weline/Social/Service/SocialScopePhoneConfig.php';
$fail = static function (string $msg): void {
    fwrite(STDERR, 'T1-FAIL: ' . $msg . PHP_EOL);
    exit(1);
};

if (!\is_file($file)) {
    $fail('file missing: ' . $file);
}
$src = (string)\file_get_contents($file);
if (!\str_contains($src, 'namespace Weline\\Social\\Service;')) {
    $fail('namespace mismatch');
}
foreach ([
    'class SocialScopePhoneConfig',
    'public const MODULE',
    'public function scopeForWebsite',
    'public function getPhone',
    'public function savePhone',
    "'social/phone/'",
] as $needle) {
    if (!\str_contains($src, $needle)) {
        $fail('missing: ' . $needle);
    }
}

\exec(\escapeshellarg(PHP_BINARY) . ' -l ' . \escapeshellarg($file) . ' 2>&1', $out, $code);
if ($code !== 0) {
    $fail('php -l failed: ' . \implode(' ', $out));
}

echo 'T1-OK' . PHP_EOL;
exit(0);

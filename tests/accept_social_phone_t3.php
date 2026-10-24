<?php
declare(strict_types=1);

/**
 * T3 验收：社媒页面模板出现手机号 strip（与邮箱 strip 并列），且模板 PHP 标签配平、语法可解析。
 */
$root = \dirname(__DIR__);
$file = $root . '/app/code/Weline/Social/view/templates/backend/Social/index.phtml';
$fail = static function (string $msg): void {
    fwrite(STDERR, 'T3-FAIL: ' . $msg . PHP_EOL);
    exit(1);
};

if (!\is_file($file)) {
    $fail('file missing');
}
$src = (string)\file_get_contents($file);
foreach ([
    'id="social-scope-phone"',
    'data-testid="social-scope-phone"',
    'data-phone-label',
    'data-phone-status',
    'data-phone-actions',
    '社媒手机号',
] as $needle) {
    if (!\str_contains($src, $needle)) {
        $fail('missing: ' . $needle);
    }
}
// 邮箱 strip 必须仍然存在（不能把既有区块弄丢）
foreach (['id="social-scope-mailbox"', 'data-mailbox-label'] as $keep) {
    if (!\str_contains($src, $keep)) {
        $fail('broke existing mailbox block: ' . $keep);
    }
}
// 手机号 strip 必须在邮箱 strip 之后、platforms 面板之前
$mailboxPos = \strpos($src, 'id="social-scope-mailbox"');
$phonePos = \strpos($src, 'id="social-scope-phone"');
$platformsPos = \strpos($src, 'data-social-panel="platforms"');
if ($mailboxPos === false || $phonePos === false || $platformsPos === false) {
    $fail('anchor missing');
}
if (!($mailboxPos < $phonePos && $phonePos < $platformsPos)) {
    $fail('phone strip not between mailbox strip and platforms panel');
}

// PHP 开闭标签数量应一致（避免在注释里写闭合标签）
$open = \preg_match_all('/<\?(php|=)/', $src);
$close = \preg_match_all('/\?>/', $src);
if ($open !== $close) {
    $fail('unbalanced php tags open=' . $open . ' close=' . $close);
}

echo 'T3-OK' . PHP_EOL;
exit(0);

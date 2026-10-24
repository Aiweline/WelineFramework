<?php
declare(strict_types=1);

/**
 * T5 验收（C 方案）：邮箱/手机号 strip 已并入「当前配置范围」卡片；
 * tabs 区不再包含二者；CSS 含 scope-attributes 规则且只用 Token。
 */
$root = \dirname(__DIR__);
$tpl = $root . '/app/code/Weline/Social/view/templates/backend/Social/index.phtml';
$css = $root . '/app/code/Weline/Social/view/statics/css/backend/social-admin.css';
$fail = static function (string $m): void { fwrite(STDERR, 'T5-FAIL: ' . $m . PHP_EOL); exit(1); };

$t = (string)\file_get_contents($tpl);
$c = (string)\file_get_contents($css);

foreach (['weline-social-admin__scope-attributes', 'data-attr="email"', 'data-attr="phone"', 'id="social-scope-mailbox"', 'id="social-scope-phone"'] as $n) {
    if (!\str_contains($t, $n)) { $fail('template missing: ' . $n); }
}

// 两个 strip 必须在 scope-attributes 容器内、且在 tabs-bar 之后
$attrPos = \strpos($t, 'weline-social-admin__scope-attributes');
$mailPos = \strpos($t, 'id="social-scope-mailbox"');
$phonePos = \strpos($t, 'id="social-scope-phone"');
$tabsPos = \strpos($t, 'weline-social-admin__tabs-bar');
if (!($attrPos < $mailPos && $mailPos < $phonePos)) { $fail('strip order wrong inside scope-attributes'); }
if ($tabsPos !== false && $tabsPos < $attrPos) { $fail('scope-attributes should live outside tabs-bar (before it)'); }

// tabs-bar 区块内不得再有 mailbox strip
$tabsEnd = \strpos($t, '</div>', $tabsPos);
$between = \substr($t, $tabsPos, 800);
if (\str_contains($between, 'social-scope-mailbox')) { $fail('mailbox strip still inside tabs area'); }

foreach (['.weline-social-admin__scope-attributes', '.weline-social-admin__scope-attr', '--weline-space-2', '--weline-space-4'] as $n) {
    if (!\str_contains($c, $n)) { $fail('css missing: ' . $n); }
}
// 裸像素检查（仅针对新增块）
$i = \strpos($c, '.weline-social-admin__scope-attributes');
$block = \substr($c, $i, 1400);
if (\preg_match('/(?:padding|margin|gap)[^;]*\d+px/', $block)) { $fail('raw px in spacing'); }
if (\preg_match('/(?:color|background|border-top)\s*:[^;]*rgba?\(/', $block)) { $fail('raw color in new css block'); }

\exec(\escapeshellarg(PHP_BINARY) . ' -l ' . \escapeshellarg($tpl) . ' 2>&1', $o, $code);
if ($code !== 0) { $fail('php -l failed'); }

echo 'T5-OK' . PHP_EOL;
exit(0);

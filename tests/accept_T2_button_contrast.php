<?php

declare(strict_types=1);

/**
 * T2 验收：Weline UI 组件层 button.css 的 .w-button 基础规则必须保证
 * 实心主按钮的文字色始终可解析、可读，不会因 token 缺失而继承主色。
 *
 * 通过时输出：ACCEPT T2 PASS
 */

$root = dirname(__DIR__);
$css = (string)file_get_contents(
    $root . '/app/code/Weline/Theme/view/ui/css/components/button.css'
);

$fail = [];

// 1. 基础规则（第一个 `.w-button {`）必须存在
if (preg_match('/\.w-button\s*\{/', $css) !== 1) {
    $fail[] = 'missing base `.w-button` rule';
}

// 2. 基础规则的 color 必须首选 on-primary 语义 token
if (
    preg_match('/\.w-button\s*\{[^}]*color:\s*var\(--weline-theme-on-primary/', $css) !== 1
) {
    $fail[] = 'base `.w-button` must set color: var(--weline-theme-on-primary …)';
}

// 3. color 必须带兜底链：on-primary 缺失时不能裸奔（前台色盘 / 白色）
if (
    preg_match(
        '/\.w-button\s*\{[^}]*color:\s*var\(--weline-theme-on-primary\s*,\s*var\(--color-on-primary/',
        $css
    ) !== 1
) {
    $fail[] = 'base `.w-button` color must chain fallback var(--color-on-primary, …)';
}

// 4. 组件层仍然只消费语义 token：禁止新增颜色字面量
$body = (string)preg_replace('/\/\*.*?\*\//s', '', $css);
if (preg_match('/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(/', $body) === 1) {
    $fail[] = 'literal color found in component button.css';
}

// 5. 组件层禁止 !important（既有契约）
if (str_contains($body, '!important')) {
    $fail[] = '!important is forbidden in component layer';
}

// 6. 实心按钮文字禁止 transparent / currentColor-on-primary-bg
if (preg_match('/\.w-button\s*\{[^}]*color:\s*transparent/', $css) === 1) {
    $fail[] = 'base `.w-button` text must not be transparent';
}

if ($fail !== []) {
    fwrite(STDERR, "T2 FAIL:\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}

echo "ACCEPT T2 PASS\n";
exit(0);

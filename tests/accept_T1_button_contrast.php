<?php

declare(strict_types=1);

/**
 * T1 验收：Weline_Agent 调度页作用域内的实心 .w-button 必须显式声明
 * 可读的文字色（on-primary 语义 token），禁止让文字与品牌蓝底同化。
 *
 * 通过时输出：ACCEPT T1 PASS
 */

$root = dirname(__DIR__);
$css = (string)file_get_contents(
    $root . '/app/code/Weline/Agent/view/statics/css/agent-admin.css'
);

$fail = [];

// 1. 页面作用域内必须存在 .w-agent-admin .w-button 规则（含实心主按钮）
if (preg_match('/\.w-agent-admin\s+\.w-button\s*\{/', $css) !== 1) {
    $fail[] = 'missing `.w-agent-admin .w-button` rule';
}

// 2. 该规则必须把 color 指到 on-primary 语义 token
if (
    preg_match('/\.w-agent-admin\s+\.w-button\s*\{[^}]*color:\s*var\(--weline-theme-on-primary/', $css) !== 1
) {
    $fail[] = '`.w-agent-admin .w-button` must set color to var(--weline-theme-on-primary)';
}

// 3. 页面 CSS 禁止把按钮文字写成 transparent（同色吞字的直接原因之一）
if (preg_match('/\.w-agent-admin[^{]*\.w-button[^{]*\{[^}]*color:\s*transparent/', $css) === 1) {
    $fail[] = 'button text must never be transparent';
}

// 4. 不得引入颜色字面量（组件/页面层只消费语义 token）
$body = (string)preg_replace('/\/\*.*?\*\//s', '', $css);
if (
    preg_match('/\.w-agent-admin\s+\.w-button\s*\{[^}]*#[0-9a-fA-F]{3,8}\b/', $body) === 1
    || preg_match('/\.w-agent-admin\s+\.w-button\s*\{[^}]*\brgba?\(/', $body) === 1
) {
    $fail[] = 'literal color found in `.w-agent-admin .w-button`';
}

if ($fail !== []) {
    fwrite(STDERR, "T1 FAIL:\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}

echo "ACCEPT T1 PASS\n";
exit(0);

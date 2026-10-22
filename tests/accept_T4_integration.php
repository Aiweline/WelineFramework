<?php

declare(strict_types=1);

/**
 * T4 集成验收：
 * 1. 重跑 Weline UI 编译（与 CI/发布同一条路径），确认产物可生成；
 * 2. 校验编译产物 weline-foundation.css 携带新的按钮对比度兜底；
 * 3. 校验 Agent 页面包 weline-agent-admin.css 携带页面级兜底；
 * 4. 校验既有设计系统契约测试仍然全绿。
 *
 * 通过时输出：ACCEPT T4 PASS
 */

$root = dirname(__DIR__);
$fail = [];

/* ---- 1. 重跑 Weline UI 编译 ---- */
$cmd = 'cd ' . escapeshellarg($root)
    . ' && php -r ' . escapeshellarg(
        'require "app/bootstrap.php";'
        . ' exit((new Weline\Theme\Console\Resource\Compiler('
        . 'Weline\Framework\Manager\ObjectManager::getInstance(Weline\Framework\Output\Cli\Printing::class)'
        . '))->execute(["welineUi"]));'
    );
exec($cmd . ' 2>&1', $out, $code);
if ($code !== 0) {
    $fail[] = 'welineUi compile exited ' . $code . ': ' . implode(' | ', array_slice($out, -5));
}

/* ---- 2. 编译产物必须携带按钮对比度兜底 ---- */
$foundation = $root . '/app/code/Weline/Theme/view/statics/ui/weline-foundation.css';
if (!is_file($foundation)) {
    $fail[] = 'compiled foundation missing';
} else {
    $css = (string)file_get_contents($foundation);
    // 编译产物保留源码格式（.w-button { 带空格、属性分行），不能用压缩格式假设。
    if (
        preg_match(
            '/\.w-button\s*\{[^}]*color:\s*var\(--weline-theme-on-primary\s*,\s*var\(--color-on-primary/s',
            $css
        ) !== 1
    ) {
        $fail[] = 'compiled foundation lacks button on-primary fallback chain';
    }
}

/* ---- 3. Agent 页面包必须携带页面级兜底 ---- */
$agentCss = $root . '/app/code/Weline/Theme/view/statics/ui/pages/weline-agent-admin.css';
if (!is_file($agentCss)) {
    $fail[] = 'compiled agent-admin.css missing';
} else {
    $css = (string)file_get_contents($agentCss);
    if (preg_match('/\.w-agent-admin\s+\.w-button\s*\{[^}]*color:\s*var\(--weline-theme-on-primary/', $css) !== 1) {
        $fail[] = 'compiled agent-admin.css lacks page-scoped button contrast rule';
    }
}

/* ---- 4. 设计系统契约测试 ---- */
exec(
    'cd ' . escapeshellarg($root) . ' && php vendor/bin/phpunit --filter DesignSystemContractTest'
    . ' app/code/Weline/Theme/test/Unit/Ui/DesignSystemContractTest.php 2>&1',
    $phpunitOut,
    $phpunitCode
);
if ($phpunitCode !== 0) {
    $fail[] = 'DesignSystemContractTest failed: ' . implode(' | ', array_slice($phpunitOut, -8));
}

if ($fail !== []) {
    fwrite(STDERR, "T4 FAIL:\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}

echo "ACCEPT T4 PASS\n";
exit(0);

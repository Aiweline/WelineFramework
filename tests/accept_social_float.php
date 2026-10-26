<?php

declare(strict_types=1);

/**
 * 验收：全站共享「悬浮社媒」部件（social-float）。
 *
 * 用法：php tests/accept_social_float.php [widget|css]（缺省 all）
 *
 * 背景：社媒悬浮原来只由 footer 容器部件的 float_social 参数渲染，hanfu 主题重写
 * footer 后 markup 直接消失（线上 6 个页面 grep footer-social-float = 0），
 * 且被 hanfu CSS 全局 display:none 兜底杀。修复=改成 required default_injection，
 * 与客服悬浮同一套共享 chrome 机制，异步模块 footerSocialFloat 负责收起/展开。
 */

$root = dirname(__DIR__);
$mode = $argv[1] ?? 'all';
if (!in_array($mode, ['widget', 'css', 'all'], true)) {
    fwrite(STDERR, "usage: php tests/accept_social_float.php [widget|css]\n");
    exit(2);
}

$template = $root . '/app/code/Weline/Theme/view/theme/frontend/widgets/social/social-float/default.phtml';
$registry = $root . '/app/code/Weline/Theme/extends/module/Weline_Widget/Weline_Theme/widget.php';
$modules = $root . '/app/code/Weline/Theme/view/statics/frontend/weline.modules.js';
$css = $root . '/app/code/Weline/Theme/view/statics/css/widgets/widget-social-float-default.css';

$fail = [];

$read = static function (string $path, string $label) use (&$fail): string {
    if (!is_file($path)) {
        $fail[] = $label . ' 缺失：' . $path;
        return '';
    }
    $text = (string)file_get_contents($path);
    if (trim($text) === '') {
        $fail[] = $label . ' 为空：' . $path;
    }
    return $text;
};

$expect = static function (bool $ok, string $label) use (&$fail): void {
    if (!$ok) {
        $fail[] = $label;
    }
};

if ($mode === 'widget' || $mode === 'all') {
    $t = $read($template, '部件模板');
    $expect(str_contains($t, '@widget.code {social-float}'), '模板缺少 @widget.code {social-float}');
    $expect(str_contains($t, '@widget.page_layouts {["*"]}'), '模板必须声明 @widget.page_layouts {["*"]}');
    $expect(str_contains($t, '@widget.slot {storefront-float-start}'), '模板必须声明 @widget.slot {storefront-float-start}');
    $expect(str_contains($t, '"layout_type":"*"'), 'default_injections 必须 layout_type 为 *');
    $expect(str_contains($t, '"slot":"storefront-float-start"'), 'default_injections 必须注入 storefront-float-start');
    $expect(str_contains($t, '"required":true'), 'default_injections 必须 required=true（主题重写 footer 后仍注入）');
    $expect(str_contains($t, 'data-testid="footer-social-float"'), '模板缺少 data-testid="footer-social-float"');
    $expect(str_contains($t, 'data-weline-load="footerSocialFloat"'), '模板缺少 data-weline-load="footerSocialFloat"（异步加载触发器）');
    $expect(str_contains($t, 'data-float-panel'), '模板缺少 data-float-panel（异步收起面板）');
    $expect(str_contains($t, 'data-float-toggle'), '模板缺少 data-float-toggle（贴边把手）');
    $expect(str_contains($t, 'normalizeSocialItems'), '模板必须用 FooterDefaultLinksHelper::normalizeSocialItems 取社媒链接（与页脚同源）');
    $expect(str_contains($t, 'social-profile-links.phtml'), '模板必须复用 social-profile-links 组件');

    $reg = $read($registry, '部件注册表');
    $expect(str_contains($reg, 'Weline_Theme::theme/frontend/widgets/social/social-float/default.phtml'), 'widget.php 未登记 social-float 模板');

    $js = $read($modules, '异步模块注册表');
    $expect(str_contains($js, 'footerSocialFloat'), 'weline.modules.js 缺少 footerSocialFloat 模块');

    if ($fail === []) {
        echo "SOCIAL_FLOAT_WIDGET_OK\n";
    }
}

if ($mode === 'css' || $mode === 'all') {
    $c = $read($css, '部件皮肤');
    $expect(preg_match('/\.w-social-float\s*\{/', $c) === 1, '缺少 .w-social-float 根规则');
    $expect(str_contains($c, 'position:fixed'), '.w-social-float 必须 position:fixed（左侧中部贴边）');
    $expect(str_contains($c, 'env(safe-area-inset-left'), '缺少 safe-area-inset-left 贴边处理');
    $expect(str_contains($c, '.w-social-float.is-collapsed'), '缺少 .is-collapsed 收起态');
    $expect(str_contains($c, 'prefers-reduced-motion'), '缺少 prefers-reduced-motion 降级');
    $expect(str_contains($c, 'var(--weline-theme-'), '皮肤必须消费 --weline-theme-* Token');
    $expect(str_contains($c, ':has(.w-social-float) .footer-social-float'), '缺少与旧 footer 社媒条防双轨规则');

    if ($fail === []) {
        echo "SOCIAL_FLOAT_CSS_OK\n";
    }
}

if ($mode === 'all' && $fail === []) {
    echo "SOCIAL_FLOAT_ALL_OK\n";
}

if ($fail !== []) {
    fwrite(STDERR, "FAIL\n");
    foreach ($fail as $line) {
        fwrite(STDERR, ' - ' . $line . "\n");
    }
    exit(1);
}

exit(0);

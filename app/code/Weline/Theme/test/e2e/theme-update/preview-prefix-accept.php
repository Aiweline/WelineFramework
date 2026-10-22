<?php

declare(strict_types=1);

/**
 * 验收：预览态静态前缀不被「主题更新代次」接管。
 *
 * 断言（渲染期职责）：
 * 1. 预览页出现 `preview_pv_` 标记（确认真的进了预览态）；
 * 2. 预览静态命名空间（/static/__preview/…）存在，且覆盖 theme-head / widget-assets 的 CSS/JS；
 * 3. theme-head / widget-assets 的 CSS/JS 不得被批次前缀（/static/tu-…）接管。
 *
 * 说明：body 内的图片等资源可能来自烘焙缓存并带批次前缀，属已知低影响项，仅作 INFO 输出。
 *
 * 用法：
 *   BASE=https://host PREVIEW_TOKEN=pv_… RESOLVE_HOST=host:9555:127.0.0.1 php preview-prefix-accept.php
 */
$base = (string)(getenv('BASE') ?: 'https://p05113ef3.test.weline.com:9555');
$host = (string)(getenv('RESOLVE_HOST') ?: '');
$token = (string)(getenv('PREVIEW_TOKEN') ?: '');
if ($token === '') {
    fwrite(STDERR, "PREVIEW_TOKEN 不能为空（可用 PreviewTokenService::generateToken 铸造）\n");
    exit(2);
}
$url = rtrim($base, '/') . '/~preview/' . rawurlencode($token) . '/';
$cmd = 'curl -sk --max-time 30'
    . ($host !== '' ? ' --resolve ' . escapeshellarg($host) : '')
    . ' -A ' . escapeshellarg('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36')
    . ' -H ' . escapeshellarg('Accept: text/html')
    . ' ' . escapeshellarg($url);
$html = (string)shell_exec($cmd);

$fail = 0;
$ok = static function (string $msg) use (&$fail): void {
    echo "  [ok] {$msg}\n";
};
$bad = static function (string $msg) use (&$fail): void {
    echo "  [FAIL] {$msg}\n";
    $fail++;
};

echo "== 预览态静态前缀验收 ==\n";
echo '  size=' . strlen($html) . "\n";

$markers = substr_count($html, 'preview_pv_');
$markers > 0 ? $ok("预览标记 preview_pv_ 出现 {$markers} 次") : $bad('未进入预览态（无 preview_pv_ 标记）');

$previewNs = preg_match_all('#/static/(?:[A-Za-z0-9_-]+/)?__preview/#', $html);
$previewNs > 0 ? $ok("预览静态命名空间引用 {$previewNs} 处") : $bad('预览静态命名空间引用为 0');

$themeHeadTotal = preg_match_all('#/static/(?:[A-Za-z0-9_-]+/)?__preview/[^"\']*Weline/Theme/(?:theme-head|widget-assets)/[^"\']+\.(?:css|js)#', $html);
$themeHeadTotal > 0
    ? $ok("theme-head/widget-assets 的 CSS/JS 走预览命名空间（{$themeHeadTotal} 处）")
    : $bad('theme-head/widget-assets 的 CSS/JS 未走预览命名空间');

$batchThemed = preg_match_all('#/static/tu-[A-Za-z0-9_-]+/Weline/Theme/(?:theme-head|widget-assets)/[^"\']+\.(?:css|js)#', $html);
$batchThemed === 0
    ? $ok('theme-head/widget-assets 未被批次前缀接管')
    : $bad("theme-head/widget-assets 被批次前缀接管 {$batchThemed} 处");

$info = preg_match_all('#/static/tu-[A-Za-z0-9_-]+/#', $html);
echo "  [INFO] body 内批次前缀引用 {$info} 处（烘焙缓存的图片/部件资源，已知低影响）\n";

echo $fail === 0 ? "ALL PASS\n" : "FAILED: {$fail}\n";
exit($fail === 0 ? 0 : 1);

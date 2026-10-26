<?php

declare(strict_types=1);

/**
 * 验收：下载 App = 二维码弹窗 + 下载中心，且 Flutter web 产物不再是白屏。
 *
 * 用法：
 *   php tests/accept_app_download_qr.php --static   # 只查源码（改完即可跑）
 *   php tests/accept_app_download_qr.php --live     # 只查线上（需 server:reload + 路由已生成）
 *   php tests/accept_app_download_qr.php            # 两段都跑
 */

$root = dirname(__DIR__);
$modeStatic = in_array('--static', $argv, true) || in_array('--live', $argv, true) === false;
$modeLive = in_array('--live', $argv, true) || in_array('--static', $argv, true) === false;
$base = getenv('APP_ACCEPT_BASE') ?: 'https://p05113ef3.test.weline.com';

$fail = [];

if ($modeStatic) {
    $sources = [
        'widget' => $root . '/app/code/Weline/App/view/templates/frontend/widgets/app-download/default.phtml',
        'js' => $root . '/app/code/Weline/App/view/statics/js/widgets/app-download-async.js',
        'css' => $root . '/app/code/Weline/App/view/statics/css/widgets/app-download.css',
        'api' => $root . '/app/code/Weline/App/Api/Rest/V1/Download.php',
        'page' => $root . '/app/code/Weline/App/Controller/Frontend/Download.php',
        'pageTpl' => $root . '/app/code/Weline/App/view/templates/frontend/download/index.phtml',
        'builder' => $root . '/app/code/Weline/App/Service/Build/FlutterBuilder.php',
        'queue' => $root . '/app/code/Weline/App/Queue/AppBuildQueue.php',
        'surface' => $root . '/app/code/Weline/Framework/Deploy/StaticPublicSurface.php',
        'buildCmd' => $root . '/app/code/Weline/App/Console/App/Build.php',
        'deps' => $root . '/app/code/Weline/App/Service/Toolchain/DepInstaller.php',
    ];
    $texts = [];
    foreach ($sources as $key => $path) {
        if (!is_file($path)) {
            $fail[] = "missing source {$key}: {$path}";
            continue;
        }
        $texts[$key] = (string)file_get_contents($path);
    }

    $mustContain = [
        'widget' => [
            'data-app-download-modal',
            'data-app-download-open',
            '/api/weline_app/rest/v1/download/qr',
            'href="/app/download"',
            'aria-modal="true"',
        ],
        'js' => [
            "closest('[data-app-download-open]')",
            'function open(',
            'function close(',
            "e.key === 'Escape'",
            'function trap(',
        ],
        'css' => [
            '.app-download__modal',
            '.app-download__dialog',
            '.app-download__qr',
            '@media (max-width: 640px)',
            'prefers-reduced-motion',
        ],
        'api' => [
            'public function getQr(',
            'image/svg+xml',
            "\$target = \$scheme . '://' . \$host . '/app/download';",
        ],
        'page' => [
            'class Download extends FrontendController',
            '/api/weline_app/rest/v1/download/qr',
            "view/templates/frontend/download/index.phtml",
        ],
        'pageTpl' => [
            'app-dl__qr',
            'app-dl__cards',
            'app-dl__btn--primary',
            'prefers-reduced-motion',
        ],
        'builder' => [
            'location.replace("/")',
            'http-equiv="refresh"',
            'public function buildAndroid(',
            'public function buildIos(',
            'collectApk',
            'collectIpa',
            "'app.apk'",
            "'app.ipa'",
        ],
        'buildCmd' => [
            'missingFor(',
            'install(',
            'no-install',
            'check-only',
            'queue:run --id=',
        ],
        'deps' => [
            'public function missingFor(',
            'public function install(',
            'brew install openjdk@17',
        ],
    ];
    foreach ($mustContain as $key => $needles) {
        if (!isset($texts[$key])) {
            continue;
        }
        foreach ($needles as $needle) {
            if (!str_contains($texts[$key], $needle)) {
                $fail[] = "[{$key}] missing: {$needle}";
            }
        }
    }

    if (isset($texts['queue']) && !str_contains($texts['queue'], 'buildAndroid')) {
        $fail[] = '[queue] 队列未按平台分派到 buildAndroid';
    }
    if (isset($texts['surface']) && !str_contains($texts['surface'], "'apk'")) {
        $fail[] = '[surface] StaticPublicSurface 未把 apk 列入可服务扩展名';
    }

    if (isset($texts['deps']) && str_contains($texts['deps'], '--recursive')) {
        $fail[] = '[deps] 仍在使用 brew --recursive（Homebrew 7 已移除该参数）';
    }

    // 不写死平台占位状态：构建发布后应自动出现，不靠手改文案
    foreach (['widget', 'pageTpl'] as $key) {
        if (!isset($texts[$key])) {
            continue;
        }
        foreach (['构建中', '待上架'] as $banned) {
            if (str_contains($texts[$key], $banned)) {
                $fail[] = "[{$key}] 仍含占位状态词：{$banned}";
            }
        }
    }
}

if ($modeLive) {
    $probe = static function (string $url): array {
        $cmd = 'curl -sk --max-time 25 -o - -w "\n__HTTP__%{http_code}__%{content_type}" '
            . escapeshellarg($url);
        $raw = (string)shell_exec($cmd);
        $code = 0;
        $type = '';
        $body = $raw;
        if (preg_match('/\n__HTTP__(\d+)__(.*)$/s', $raw, $m) === 1) {
            $code = (int)$m[1];
            $type = trim($m[2]);
            $body = substr($raw, 0, (int)strpos($raw, "\n__HTTP__"));
        }

        return ['code' => $code, 'type' => $type, 'body' => $body];
    };

    $qr = $probe($base . '/api/weline_app/rest/v1/download/qr');
    if ($qr['code'] !== 200) {
        $fail[] = "[live] QR endpoint HTTP {$qr['code']} (期望 200)";
    }
    if (!str_contains($qr['type'], 'image/svg+xml')) {
        $fail[] = "[live] QR endpoint content-type: {$qr['type']} (期望 image/svg+xml)";
    }
    if (!str_contains($qr['body'], '<svg')) {
        $fail[] = '[live] QR endpoint 返回体不含 <svg';
    }

    $page = $probe($base . '/app/download');
    if ($page['code'] !== 200) {
        $fail[] = "[live] /app/download HTTP {$page['code']} (期望 200)";
    }
    foreach (['app-dl__qr', 'app-dl__card', '/api/weline_app/rest/v1/download/qr"'] as $needle) {
        if (!str_contains($page['body'], $needle)) {
            $fail[] = "[live] /app/download 缺 {$needle}";
        }
    }
    // 只列已发布产物：不得再出现「构建中 / 待上架」占位
    foreach (['构建中', '待上架', 'Building', 'Coming soon'] as $banned) {
        if (str_contains($page['body'], $banned)) {
            $fail[] = "[live] /app/download 仍出现占位状态词：{$banned}";
        }
    }

    // Android 产物：出现卡片 + 安装包能整包下载（HTTP/1.1 路径；HTTP/2 大文件截断是 WLS 既有缺陷）
    if (!str_contains($page['body'], '<h3>Android</h3>')) {
        $fail[] = '[live] /app/download 未出现 Android 卡片';
    }
    // iOS 暂不对外（无 Apple 签名凭据，可安装包做不出来）：页面不得再出现 iOS 入口
    if (str_contains($page['body'], '<h3>iOS</h3>')) {
        $fail[] = '[live] /app/download 仍出现 iOS 卡片（当前策略：iOS 暂不做）';
    }

    // PWA 三件套
    $manifest = $probe($base . '/manifest.webmanifest');
    if ($manifest['code'] !== 200 || !str_contains($manifest['type'], 'manifest')) {
        $fail[] = "[live] /manifest.webmanifest 异常：{$manifest['code']} {$manifest['type']}";
    }
    if (!str_contains($manifest['body'], '"display": "standalone"')) {
        $fail[] = '[live] manifest 缺 display:standalone';
    }
    $sw = $probe($base . '/sw.js');
    if ($sw['code'] !== 200 || !str_contains($sw['body'], "addEventListener('fetch'")) {
        $fail[] = "[live] /sw.js 异常：{$sw['code']}";
    }
    $icon = $probe($base . '/pwa-icon-192.png');
    if ($icon['code'] !== 200 || !str_contains($icon['type'], 'image/png')) {
        $fail[] = "[live] /pwa-icon-192.png 异常：{$icon['code']} {$icon['type']}";
    }
    $headHtml = $probe($base . '/en_US/categories');
    foreach (['rel="manifest" href="/manifest.webmanifest', 'name="theme-color"', 'apple-mobile-web-app-capable'] as $needle) {
        if (!str_contains($headHtml['body'], $needle)) {
            $fail[] = "[live] 页面 head 缺 PWA 标记：{$needle}";
        }
    }

    $apkSize = (int)trim((string)shell_exec(
        'curl -sk --http1.1 --max-time 240 -o /dev/null -w "%{size_download}" '
        . escapeshellarg($base . '/api/weline_app/rest/v1/download/download?platform=android')
    ));
    if ($apkSize < 40 * 1024 * 1024) {
        $fail[] = "[live] Android 安装包下载不完整：{$apkSize} bytes";
    }

    // Web 版下载入口必须落到在线商店，而不是没有商品数据的 Flutter 壳
    $web = $probe($base . '/api/weline_app/rest/v1/download/download?platform=web');
    $location = '';
    $head = (string)shell_exec('curl -sk --max-time 25 -D - -o /dev/null ' . escapeshellarg(
        $base . '/api/weline_app/rest/v1/download/download?platform=web'
    ));
    if (preg_match('/^location:\s*(\S+)/mi', $head, $m) === 1) {
        $location = trim($m[1]);
    }
    if ($web['code'] !== 302 || $location !== '/') {
        $fail[] = "[live] Web 下载入口应 302 到 /（实际 {$web['code']} → {$location}）";
    }

    // 多语言：页面跟随 locale；已翻译语言用本语言，未翻译语言回退英文（不出现整段中文）
    $enPage = $probe($base . '/en_US/app/download');
    foreach (['Download App', 'Scan to download', 'Open the online store'] as $needle) {
        if (!str_contains($enPage['body'], $needle)) {
            $fail[] = "[live] en_US 下载中心缺英文：{$needle}";
        }
    }
    $zhPage = $probe($base . '/zh_Hans_CN/app/download');
    if (!str_contains($zhPage['body'], '下载 App')) {
        $fail[] = '[live] zh_Hans_CN 下载中心缺中文标题';
    }
    $dePage = $probe($base . '/de_DE/app/download');
    $deBody = strip_tags(preg_replace('#<script\b[^>]*>.*?</script>#is', '', $dePage['body']) ?? '');
    if (preg_match('/[\x{4e00}-\x{9fff}]/u', $deBody) === 1) {
        $fail[] = '[live] de_DE 下载中心仍出现中文（未走英文回退）';
    }
    if (preg_match('/<html[^>]*lang="de-DE"/i', $dePage['body']) !== 1) {
        $fail[] = '[live] de_DE 页面 html lang 未跟随 locale';
    }
    $enManifest = $probe($base . '/manifest.webmanifest?lang=en_US');
    if (!str_contains($enManifest['body'], '"lang": "en"') || !str_contains($enManifest['body'], 'Online Store')) {
        $fail[] = '[live] manifest 未按 en_US 本地化';
    }
    $zhManifest = $probe($base . '/manifest.webmanifest?lang=zh_Hans_CN');
    if (!str_contains($zhManifest['body'], '"lang": "zh-Hans"')) {
        $fail[] = '[live] manifest 未按 zh_Hans_CN 本地化';
    }
    $enHead = $probe($base . '/en_US/categories');
    if (!str_contains($enHead['body'], '/manifest.webmanifest?lang=en_US')) {
        $fail[] = '[live] head 的 manifest 链接未带当前语言';
    }

    $version = $probe($base . '/api/weline_app/rest/v1/version/version?platform=web');
    $versionUrl = '';
    if ($version['code'] === 200) {
        $decoded = json_decode($version['body'], true);
        $versionUrl = (string)($decoded['data']['url'] ?? '');
    }
    if ($versionUrl !== '/api/weline_app/rest/v1/download/download?platform=web') {
        $fail[] = "[live] 版本接口 url 仍指向不存在的路由（实际：{$versionUrl}）";
    }
}

if ($fail !== []) {
    foreach ($fail as $line) {
        fwrite(STDOUT, "FAIL: {$line}\n");
    }
    exit(1);
}

fwrite(STDOUT, "APP_DOWNLOAD_QR_OK\n");
exit(0);

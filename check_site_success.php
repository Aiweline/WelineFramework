<?php
/**
 * 站点创建成功检查脚本
 *
 * 用法：
 *   1. 将待检查域名写入 域名.txt，每行一个（可带 http:// 或 https://，也可不带）
 *   2. 在项目根目录执行：
 *
 *      php check_site_success.php
 *          默认：非 HTTPS，先请求 HTTP，失败再试 HTTPS；未通过域名写入 失败.txt
 *
 *      php check_site_success.php --test
 *          测试模式：仅检查前 3 个域名，不写入 失败.txt
 *
 *      php check_site_success.php --https
 *          HTTPS 验证模式：仅请求 HTTPS 并验证 SSL 证书，未通过写入 失败.txt
 *
 *      php check_site_success.php --test --https
 *          测试 + HTTPS 验证（仅检查前 3 个，不写 失败.txt）
 *
 * 判定：响应内容包含「恭喜, 站点创建成功！」视为通过，否则记为失败。
 */

// 域名列表：脚本同目录 → dev/temp/ → 当前工作目录
$domainsFile = __DIR__ . DIRECTORY_SEPARATOR . '域名.txt';
if (!is_file($domainsFile)) {
    $devTemp = __DIR__ . DIRECTORY_SEPARATOR . 'dev' . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR . '域名.txt';
    if (is_file($devTemp)) {
        $domainsFile = $devTemp;
    } elseif (getcwd() !== false) {
        $cwdFile = getcwd() . DIRECTORY_SEPARATOR . '域名.txt';
        if (is_file($cwdFile)) {
            $domainsFile = $cwdFile;
        }
    }
}
$failFile = dirname($domainsFile) . DIRECTORY_SEPARATOR . '失败.txt';
$keyword  = '恭喜, 站点创建成功！';

// 参数解析
$argv = $argv ?? [];
$isTest  = in_array('--test', $argv, true);
$isHttps = in_array('--https', $argv, true);

if (!is_file($domainsFile)) {
    echo "未找到 域名.txt（已查找：脚本所在目录、当前工作目录 " . (getcwd() ?: '?') . "）。\n";
    echo "请将域名每行一个写入 域名.txt，并放在脚本同目录或执行命令所在目录。\n";
    exit(1);
}

$lines = file($domainsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$domains = array_map('trim', $lines);
$domains = array_filter($domains);

if (empty($domains)) {
    echo "域名.txt 中没有有效域名。\n";
    exit(1);
}

if ($isTest) {
    $domains = array_slice($domains, 0, 3);
    echo "[测试模式] 仅检查前 " . count($domains) . " 个域名，不写入 失败.txt\n\n";
}

$total = count($domains);
$failed = [];

$opts = [
    'http' => [
        'timeout' => 15,
        'ignore_errors' => true,
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"
    ]
];
if ($isHttps) {
    $opts['ssl'] = [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ];
}
$ctx = stream_context_create($opts);

echo ($isHttps ? "[HTTPS 验证模式] 仅请求 HTTPS，并验证 SSL 证书\n\n" : '');
echo "共 " . $total . " 个域名，开始检查...\n\n";

foreach ($domains as $i => $domain) {
    $url = $domain;
    if (stripos($url, 'http://') !== 0 && stripos($url, 'https://') !== 0) {
        // 默认非 HTTPS：无协议时先用 HTTP；--https 时用 HTTPS
        $url = $isHttps ? ('https://' . $domain) : ('http://' . $domain);
    }
    if ($isHttps && stripos($url, 'https://') !== 0) {
        $url = 'https://' . preg_replace('#^http://#i', '', $url);
    }
    $num = $i + 1;
    echo "[{$num}/{$total}] {$url} ... ";

    $content = @file_get_contents($url, false, $ctx);
    // 默认非 HTTPS：先试 HTTP 失败再试 HTTPS
    if ($content === false && !$isHttps) {
        $url2 = (stripos($url, 'http://') === 0) ? 'https://' . substr($url, 7) : 'http://' . preg_replace('#^https://#i', '', $url);
        $content = @file_get_contents($url2, false, $ctx);
    }

    if ($content === false) {
        echo "请求失败\n";
        $failed[] = $domain;
        continue;
    }

    if (strpos($content, $keyword) !== false) {
        echo "通过\n";
    } else {
        echo "未包含关键词\n";
        $failed[] = $domain;
    }
}

if ($isTest) {
    echo "\n[测试模式] 未写入 失败.txt。失败数: " . count($failed) . "\n";
} elseif (!empty($failed)) {
    file_put_contents($failFile, implode("\n", $failed) . "\n");
    echo "\n未通过域名已写入: 失败.txt（共 " . count($failed) . " 个）\n";
} else {
    if (is_file($failFile)) {
        @unlink($failFile);
    }
    echo "\n全部通过，未生成 失败.txt。\n";
}

<?php
/**
 * 非 www 是否跳转 www 子域名验证脚本
 *
 * 用法：
 *   1. 将待检查域名写入 域名.txt，每行一个（可带 http:// 或 https://，可带 www，脚本会统一用「裸域」请求）
 *   2. 在项目根目录执行：
 *
 *      php check_www_redirect.php
 *          默认：非 HTTPS，请求 http://裸域，检查是否 301/302 跳转到含 www 的地址；未跳转的写入 未跳转www.txt
 *
 *      php check_www_redirect.php --test
 *          测试模式：仅检查前 3 个域名，不写入 未跳转www.txt
 *
 *      php check_www_redirect.php --https
 *          HTTPS 验证：请求 https://裸域 并验证 SSL，检查是否跳转到 https://www.xxx
 *
 *      php check_www_redirect.php --test --https
 *          测试 + HTTPS 验证
 *
 * 判定：对「裸域」发请求，若响应为 301/302/307/308 且 Location 的 host 为 www.裸域，则通过；否则记为未跳转 www。
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
$failFile = dirname($domainsFile) . DIRECTORY_SEPARATOR . '未跳转www.txt';

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

/**
 * 从域名或 URL 中提取裸域（无协议、无 www）
 */
function bareDomain(string $input): string {
    $s = $input;
    $s = preg_replace('#^https?://#i', '', $s);
    $s = preg_replace('#^www\.#i', '', $s);
    $s = rtrim($s, '/');
    return strtolower($s);
}

/**
 * 检查一次请求是否跳转到 www（只取首段响应，不跟随重定向）
 */
function redirectsToWww(string $url, bool $verifySsl): ?bool {
    $opts = [
        'http' => [
            'timeout' => 10,
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => "User-Agent: Mozilla/5.0 (compatible; WelineRedirectCheck/1.0)\r\n"
        ]
    ];
    if ($verifySsl) {
        $opts['ssl'] = ['verify_peer' => true, 'verify_peer_name' => true];
    }
    $ctx = stream_context_create($opts);
    $headers = @get_headers($url, 0, $ctx);
    if ($headers === false || empty($headers)) {
        return null; // 请求失败
    }
    $status = $headers[0];
    if (!preg_match('#HTTP/\d\.\d\s+(\d+)#', $status, $m)) {
        return null;
    }
    $code = (int) $m[1];
    $redirectCodes = [301, 302, 307, 308];
    if (!in_array($code, $redirectCodes, true)) {
        return false; // 不是重定向
    }
    $location = null;
    foreach ($headers as $line) {
        if (stripos($line, 'Location:') === 0) {
            $location = trim(substr($line, 9));
            break;
        }
    }
    if ($location === null || $location === '') {
        return false;
    }
    $parsed = parse_url($location);
    $host = $parsed['host'] ?? '';
    return stripos($host, 'www.') === 0;
}

if ($isTest) {
    $domains = array_slice($domains, 0, 3);
    echo "[测试模式] 仅检查前 " . count($domains) . " 个域名，不写入 未跳转www.txt\n\n";
}

$total = count($domains);
$failed = [];

echo ($isHttps ? "[HTTPS 验证模式] 请求 https://裸域，并验证 SSL\n\n" : '');
echo "共 " . $total . " 个域名，检查「裸域 → www」跳转...\n\n";

foreach ($domains as $i => $domain) {
    $bare = bareDomain($domain);
    if ($bare === '') {
        echo "[" . ($i + 1) . "/{$total}] 跳过无效: {$domain}\n";
        $failed[] = $domain;
        continue;
    }
    $scheme = $isHttps ? 'https' : 'http';
    $url = $scheme . '://' . $bare;
    $num = $i + 1;
    echo "[{$num}/{$total}] {$url} ... ";

    $result = redirectsToWww($url, $isHttps);
    if ($result === null) {
        if (!$isHttps) {
            $result = redirectsToWww('https://' . $bare, false);
        }
        if ($result === null) {
            echo "请求失败\n";
            $failed[] = $domain;
            continue;
        }
    }
    if ($result === true) {
        echo "已跳转 www\n";
    } else {
        echo "未跳转 www\n";
        $failed[] = $domain;
    }
}

if ($isTest) {
    echo "\n[测试模式] 未写入 未跳转www.txt。未跳转数: " . count($failed) . "\n";
} elseif (!empty($failed)) {
    file_put_contents($failFile, implode("\n", $failed) . "\n");
    echo "\n未跳转 www 的域名已写入: 未跳转www.txt（共 " . count($failed) . " 个）\n";
} else {
    if (is_file($failFile)) {
        @unlink($failFile);
    }
    echo "\n全部已跳转 www，未生成 未跳转www.txt。\n";
}

<?php
/**
 * 站点检查脚本：站点创建成功 + 非 www 跳转 www（可选）
 * 输入输出与脚本同目录；可放在项目根或 dev/temp，执行 php 脚本路径 即可。
 */

$argv = $argv ?? [];
if (in_array('-h', $argv, true) || in_array('--help', $argv, true)) {
    echo <<<'HELP'
用法:
  php check_site_success.php [选项]

选项:
  (无参数)     默认：检查响应是否包含「恭喜, 站点创建成功！」未通过写入 失败.txt
  --www        只做「裸域 → www」跳转检查，未跳转的写入 未跳转www.txt
  --test        仅检查前 3 个域名，不写入任何结果文件
  --https       使用 HTTPS 请求并验证 SSL 证书
  -h, --help    显示本帮助

示例:
  php check_site_success.php
  php check_site_success.php --www
  php check_site_success.php --www --https
  php dev/temp/check_site_success.php --www

说明:
  域名列表从「脚本同目录」或「dev/temp」或「当前工作目录」下的 域名.txt 读取，每行一个域名。
  失败.txt、未跳转www.txt 写入 域名.txt 所在目录。

HELP;
    exit(0);
}

$isWww   = in_array('--www', $argv, true);
$isTest  = in_array('--test', $argv, true);
$isHttps = in_array('--https', $argv, true);

$dir = __DIR__;
$domainsFile = $dir . DIRECTORY_SEPARATOR . '域名.txt';
if (!is_file($domainsFile)) {
    $devTemp = $dir . DIRECTORY_SEPARATOR . 'dev' . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR . '域名.txt';
    if (is_file($devTemp)) {
        $domainsFile = $devTemp;
    } elseif (getcwd() !== false) {
        $cwdFile = getcwd() . DIRECTORY_SEPARATOR . '域名.txt';
        if (is_file($cwdFile)) {
            $domainsFile = $cwdFile;
        }
    }
}
$baseDir   = dirname($domainsFile);
$failFile  = $baseDir . DIRECTORY_SEPARATOR . '失败.txt';
$wwwFile   = $baseDir . DIRECTORY_SEPARATOR . '未跳转www.txt';
$keyword   = '恭喜, 站点创建成功！';

if (!is_file($domainsFile)) {
    echo "未找到 域名.txt（已查找：脚本所在目录、dev/temp、当前工作目录）。\n";
    exit(1);
}

$lines   = file($domainsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$domains = array_filter(array_map('trim', $lines));

if (empty($domains)) {
    echo "域名.txt 中没有有效域名。\n";
    exit(1);
}

if ($isTest) {
    $domains = array_slice($domains, 0, 3);
    echo "[测试模式] 仅检查前 " . count($domains) . " 个，不写入结果文件\n\n";
}

$total  = count($domains);
$failed  = [];
$noWww   = [];

$httpOpts = [
    'http' => [
        'timeout' => 15,
        'ignore_errors' => true,
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"
    ]
];
if ($isHttps) {
    $httpOpts['ssl'] = ['verify_peer' => true, 'verify_peer_name' => true];
}
$ctx = stream_context_create($httpOpts);

// ---------- 仅 www 跳转检查 ----------
if ($isWww) {
    $opts = [
        'http' => [
            'timeout' => 10,
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => "User-Agent: Mozilla/5.0 (compatible; WelineRedirectCheck/1.0)\r\n"
        ]
    ];
    if ($isHttps) {
        $opts['ssl'] = ['verify_peer' => true, 'verify_peer_name' => true];
    }
    $ctxWww = stream_context_create($opts);

    function bareDomain(string $input): string {
        $s = preg_replace('#^https?://#i', '', $input);
        $s = preg_replace('#^www\.#i', '', $s);
        return strtolower(rtrim($s, '/'));
    }

    function redirectsToWww(string $url, $ctx): ?bool {
        $headers = @get_headers($url, 0, $ctx);
        if ($headers === false || empty($headers)) {
            return null;
        }
        if (!preg_match('#HTTP/\d\.\d\s+(\d+)#', $headers[0], $m)) {
            return null;
        }
        $code = (int) $m[1];
        if (!in_array($code, [301, 302, 307, 308], true)) {
            return false;
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
        $host = parse_url($location, PHP_URL_HOST);
        return $host !== null && stripos($host, 'www.') === 0;
    }

    echo ($isHttps ? "[HTTPS] " : '');
    echo "共 {$total} 个域名，检查「裸域 → www」跳转...\n\n";

    foreach ($domains as $i => $domain) {
        $bare = bareDomain($domain);
        if ($bare === '') {
            echo "[" . ($i + 1) . "/{$total}] 跳过无效: {$domain}\n";
            $noWww[] = $domain;
            continue;
        }
        $url = ($isHttps ? 'https' : 'http') . '://' . $bare;
        $num = $i + 1;
        echo "[{$num}/{$total}] {$url} ... ";

        $result = redirectsToWww($url, $ctxWww);
        if ($result === null && !$isHttps) {
            $result = redirectsToWww('https://' . $bare, $ctxWww);
        }
        if ($result === null) {
            echo "请求失败\n";
            $noWww[] = $domain;
            continue;
        }
        if ($result) {
            echo "已跳转 www\n";
        } else {
            echo "未跳转 www\n";
            $noWww[] = $domain;
        }
    }

    if ($isTest) {
        echo "\n[测试模式] 未写入文件。未跳转数: " . count($noWww) . "\n";
    } elseif (!empty($noWww)) {
        file_put_contents($wwwFile, implode("\n", $noWww) . "\n");
        echo "\n未跳转 www 已写入: " . basename($wwwFile) . "（" . count($noWww) . " 个）\n";
    } else {
        if (is_file($wwwFile)) {
            @unlink($wwwFile);
        }
        echo "\n全部已跳转 www。\n";
    }
    exit(0);
}

// ---------- 默认：站点创建成功检查 ----------
echo ($isHttps ? "[HTTPS] " : '');
echo "共 {$total} 个域名，检查「恭喜, 站点创建成功！」...\n\n";

foreach ($domains as $i => $domain) {
    $url = $domain;
    if (stripos($url, 'http://') !== 0 && stripos($url, 'https://') !== 0) {
        $url = $isHttps ? ('https://' . $domain) : ('http://' . $domain);
    }
    if ($isHttps && stripos($url, 'https://') !== 0) {
        $url = 'https://' . preg_replace('#^http://#i', '', $url);
    }
    $num = $i + 1;
    echo "[{$num}/{$total}] {$url} ... ";

    $content = @file_get_contents($url, false, $ctx);
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
    echo "\n[测试模式] 未写入文件。失败数: " . count($failed) . "\n";
} elseif (!empty($failed)) {
    file_put_contents($failFile, implode("\n", $failed) . "\n");
    echo "\n未通过已写入: " . basename($failFile) . "（" . count($failed) . " 个）\n";
} else {
    if (is_file($failFile)) {
        @unlink($failFile);
    }
    echo "\n全部通过。\n";
}

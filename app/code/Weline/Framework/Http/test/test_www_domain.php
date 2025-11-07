<?php
/**
 * WWW 和非 WWW 域名测试脚本
 * 
 * 使用方法：
 * 1. 在 hosts 文件中添加测试域名：
 *    127.0.0.1 test.example.com
 *    127.0.0.1 www.test.example.com
 * 
 * 2. 在浏览器中访问：
 *    http://test.example.com/test
 *    http://www.test.example.com/test
 * 
 * 3. 或者直接运行此脚本进行测试
 */

require_once __DIR__ . '/../../../../../../app/bootstrap.php';

use Weline\Framework\Http\Url;

// 保存原始 $_SERVER
$originalServer = $_SERVER;

// 测试场景1：网站配置为 example.com，访问 www.example.com
echo "=== 测试场景1：网站配置为 example.com，访问 www.example.com ===\n";
Url::$parserSites = [
    'http://example.com' => [
        'website_id' => 1,
        'name' => '测试网站',
        'code' => 'test',
        'url' => 'http://example.com',
        'default_currency' => 'CNY',
        'default_language' => 'zh_Hans_CN',
        'default_timezone' => 'Asia/Shanghai',
    ]
];

$_SERVER['HTTP_HOST'] = 'www.example.com';
$_SERVER['REQUEST_URI'] = '/test';
$_SERVER['REQUEST_SCHEME'] = 'http';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['HTTPS'] = '';
$_SERVER['SERVER_NAME'] = 'www.example.com';
unset($_SERVER['WELINE_WEBSITE_URL']);
unset($_SERVER['WELINE_WEBSITE_ID']);
unset($_SERVER['WELINE_WEBSITE_CODE']);

Url::$parserCache = [];
Url::$parserUrlCache = [];
Url::$splitUrlCache = [];
Url::$parserServer = [];

$result1 = Url::parser('http://www.example.com/test');
echo "匹配结果: " . (isset($result1['website']) ? "成功" : "失败") . "\n";
if (isset($result1['website'])) {
    echo "网站URL: " . ($result1['website_url'] ?? 'N/A') . "\n";
    echo "REQUEST_URI: " . ($result1['server']['REQUEST_URI'] ?? 'N/A') . "\n";
}
echo "\n";

// 测试场景2：网站配置为 www.example.com，访问 example.com
echo "=== 测试场景2：网站配置为 www.example.com，访问 example.com ===\n";
Url::$parserSites = [
    'http://www.example.com' => [
        'website_id' => 1,
        'name' => '测试网站',
        'code' => 'test',
        'url' => 'http://www.example.com',
        'default_currency' => 'CNY',
        'default_language' => 'zh_Hans_CN',
        'default_timezone' => 'Asia/Shanghai',
    ]
];

$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['REQUEST_URI'] = '/test';
$_SERVER['REQUEST_SCHEME'] = 'http';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['HTTPS'] = '';
$_SERVER['SERVER_NAME'] = 'example.com';

Url::$parserCache = [];
Url::$parserUrlCache = [];
Url::$splitUrlCache = [];
Url::$parserServer = [];

$result2 = Url::parser('http://example.com/test');
echo "匹配结果: " . (isset($result2['website']) ? "成功" : "失败") . "\n";
if (isset($result2['website'])) {
    echo "网站URL: " . ($result2['website_url'] ?? 'N/A') . "\n";
    echo "REQUEST_URI: " . ($result2['server']['REQUEST_URI'] ?? 'N/A') . "\n";
}
echo "\n";

// 测试场景3：测试域名规范化函数
echo "=== 测试场景3：域名规范化函数 ===\n";
$hosts = [
    'www.example.com',
    'example.com',
    'www.test.example.com',
    'test.example.com',
];

foreach ($hosts as $host) {
    $normalized = Url::normalizeHost($host, true);
    echo "原始: $host => 规范化: $normalized\n";
}
echo "\n";

// 测试场景4：测试主机名匹配函数
echo "=== 测试场景4：主机名匹配函数 ===\n";
$urlPairs = [
    ['http://www.example.com/test', 'http://example.com'],
    ['http://example.com/test', 'http://www.example.com'],
    ['https://www.example.com/test', 'https://example.com'],
    ['http://api.example.com/test', 'http://example.com'], // 子域名不应该匹配
];

foreach ($urlPairs as $pair) {
    $match = Url::isHostMatch($pair[0], $pair[1]);
    echo "URL1: {$pair[0]}\n";
    echo "URL2: {$pair[1]}\n";
    echo "匹配: " . ($match ? "是" : "否") . "\n\n";
}

// 恢复原始 $_SERVER
$_SERVER = $originalServer;

echo "=== 测试完成 ===\n";


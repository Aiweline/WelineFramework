<?php
declare(strict_types=1);

namespace Weline\Framework\Manager {
    class ObjectManager { public static function getInstance(string $class): object { return new $class(); } }
}
namespace Weline\Seo\Service\Adapter {
    function curl_init() { return new \stdClass(); }
    function curl_setopt_array($handle, array $options) { $handle->options = $options; return true; }
    function curl_exec($handle) { $GLOBALS['requests'][] = $handle->options; $handle->response = array_shift($GLOBALS['responses']) ?? ['code' => 200, 'body' => '']; return $handle->response['body'] ?? ''; }
    function curl_getinfo($handle, $option) { return $handle->response['code']; }
    function curl_error($handle) { return $handle->response['error'] ?? ''; }
    function curl_close($handle) {}
}
namespace Weline\Seo\Adapter {
    function curl_init() { return \Weline\Seo\Service\Adapter\curl_init(); }
    function curl_setopt_array($h, array $o) { return \Weline\Seo\Service\Adapter\curl_setopt_array($h, $o); }
    function curl_exec($h) { return \Weline\Seo\Service\Adapter\curl_exec($h); }
    function curl_getinfo($h, $o) { return \Weline\Seo\Service\Adapter\curl_getinfo($h, $o); }
    function curl_error($h) { return \Weline\Seo\Service\Adapter\curl_error($h); }
    function curl_close($h) {}
}
namespace {
    function __(string $message, ...$args): string {
        $args = count($args) === 1 && is_array($args[0]) ? $args[0] : $args;
        foreach ($args as $index => $value) { $message = str_replace('%{' . ($index + 1) . '}', (string)$value, $message); }
        return $message;
    }
    define('BP', dirname(__DIR__, 5));
    spl_autoload_register(function ($class) {
        if (str_starts_with($class, 'Weline\\Seo\\')) {
            $path = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen('Weline\\Seo\\'))) . '.php';
            if (is_file($path)) { require_once $path; }
        }
    });
    $failures = [];
    function check(bool $ok, string $label): void { if (!$ok) { $GLOBALS['failures'][] = $label; } }
    function fixture(array $responses): void { $GLOBALS['requests'] = []; $GLOBALS['responses'] = $responses; }
    $index = new \Weline\Seo\Service\Adapter\IndexNowSearchEngineAdapter();
    $config = ['indexnow_key' => 'test-key-123', 'key_location' => 'https://example.com/test-key-123.txt'];
    fixture([['code' => 202, 'body' => '']]);
    $result = $index->pushUrls(['https://example.com/a'], ['config' => $config]);
    check(($result['status'] ?? '') === 'pending_verification' && ($result['accepted'] ?? false) && !($result['success'] ?? false), 'IndexNow 202 is accepted pending verification, not verified success');
    fixture([]);
    $result = $index->pushUrls(['https://example.com/a', 'https://other.example/b'], ['config' => $config]);
    check(count($GLOBALS['requests']) === 0 && !($result['success'] ?? false), 'IndexNow rejects mixed hosts before network');
    fixture([]);
    $result = $index->pushUrls(['https://example.com/a'], ['config' => $config + ['site_url' => 'https://other.example']]);
    check(count($GLOBALS['requests']) === 0 && !($result['success'] ?? false), 'IndexNow rejects host outside configured site');
    fixture([['code' => 200, 'body' => '']]);
    $result = $index->pushUrls(['https://example.com/a', 'https://example.com/a'], ['config' => $config]);
    check(count(json_decode($GLOBALS['requests'][0][CURLOPT_POSTFIELDS] ?? '{}', true)['urlList'] ?? []) === 1, 'IndexNow deduplicates each request');

    $bing = new \Weline\Seo\Service\Adapter\BingSearchEngineAdapter();
    fixture([['code' => 200, 'body' => '{"d":null}'], ['code' => 200, 'body' => '{"d":null}']]);
    $urls = array_map(fn($n) => 'https://example.com/p/' . $n, range(1, 501));
    $result = $bing->pushUrls($urls, ['config' => ['api_key' => 'secret', 'site_url' => 'https://example.com']]);
    check(count($GLOBALS['requests']) === 2 && ($result['data']['submitted_urls'] ?? 0) === 501, 'Bing batches at 500 URLs');
    fixture([['code' => 200, 'body' => '{"ErrorCode":4,"Message":"bad secret"}']]);
    $result = $bing->pushUrls(['https://example.com/a'], ['config' => ['api_key' => 'secret', 'site_url' => 'https://example.com']]);
    check(!($result['success'] ?? false) && !str_contains(json_encode($result), 'secret'), 'Bing does not accept API errors or echo credentials');
    fixture([['code' => 200, 'body' => '']]);
    $result = $bing->pushUrls(['https://example.com/a'], ['config' => $config + ['use_indexnow' => true]]);
    check(($result['success'] ?? false) && count($GLOBALS['requests']) === 1, 'Bing IndexNow does not require Bing API credential');

    fixture([]);
    $result = $bing->pushUrls(['https://example.com/a'], ['action' => 'delete', 'config' => ['api_key' => 'secret', 'site_url' => 'https://example.com']]);
    check(count($GLOBALS['requests']) === 0 && ($result['status'] ?? '') === 'unsupported', 'Bing Webmaster deletion is not misreported as accepted URL addition');
    $baidu = new \Weline\Seo\Service\Adapter\BaiduSearchEngineAdapter();
    fixture([['code' => 200, 'body' => '{"success":1,"remain":99,"not_valid":["https://example.com/b"]}']]);
    $result = $baidu->pushUrls(['https://example.com/a', 'https://example.com/b'], ['config' => ['token' => 'secret', 'site' => 'https://example.com']]);
    check(($result['status'] ?? '') === 'partial' && !($result['success'] ?? false) && ($result['data']['submitted_urls'] ?? 0) === 1 && ($result['data']['rejected_urls'] ?? []) === ['https://example.com/b'], 'Baidu reports partial acceptance and rejected URLs');
    fixture([['code' => 500, 'body' => '{"success":1}']]);
    $result = $baidu->pushUrls(['https://example.com/a'], ['config' => ['token' => 'secret', 'site' => 'https://example.com']]);
    check(!($result['success'] ?? false), 'Baidu checks HTTP status');
    fixture([]);
    $result = $baidu->submitSitemap('https://example.com/sitemap.xml', ['config' => ['token' => 'secret', 'site' => 'https://example.com']]);
    check(count($GLOBALS['requests']) === 0 && ($result['status'] ?? '') === 'unsupported', 'Baidu does not mislabel sitemap URL as sitemap API');

    $registry = new \Weline\Seo\Service\SearchEngineAdapterRegistry(new \Weline\Framework\Manager\ObjectManager());
    check($registry->getAdapter('google') instanceof \Weline\Seo\Service\Adapter\GoogleSearchConsoleAdapter, 'Google default uses Search Console');
    $capability = new \Weline\Seo\Service\SeoPlatformCapabilityService(new \Weline\Seo\Service\SitemapAdapterRegistry(), $registry);
    check($capability->getCapability('indexnow') !== null, 'IndexNow is directly configurable as a backend account platform');
    check(!$capability->supportsUrlPush('google'), 'Google does not expose generic URL push');
    $securityClass = \Weline\Seo\Service\SeoAccountConfig::class;
    if (class_exists($securityClass)) {
        $security = new $securityClass();
        $fields = [['key' => 'token', 'type' => 'password', 'required' => true], ['key' => 'site', 'type' => 'website_url', 'required' => true]];
        $merged = $security->merge(['token' => 'secret', 'site' => 'https://example.com'], ['token' => '', 'site' => 'https://example.com'], $fields);
        check($merged['token'] === 'secret', 'Editing empty credential preserves saved secret');
        check(!str_contains(json_encode($security->forDisplay($merged, $fields)), 'secret'), 'Display config never exposes saved credential');
        check($security->validate('baidu', ['site' => 'https://example.com'], $fields) !== [], 'Unconfigured account fails validation');
    } else { check(false, 'Credential configuration boundary exists'); }
    fixture([['code' => 200, 'body' => '{"d":null}']]);
    $result = (new \Weline\Seo\Adapter\BingSitemapAdapter())->submitSitemap('https://example.com/sitemap.xml', ['config' => ['api_key' => 'secret', 'site_url' => 'https://example.com']]);
    check(($result['success'] ?? false) && str_contains($GLOBALS['requests'][0][CURLOPT_URL] ?? '', '/SubmitFeed?'), 'Bing sitemap uses documented SubmitFeed JSON API');
    check(!(new \Weline\Seo\Adapter\BaiduSitemapAdapter())->supportsAutoSubmit(), 'Baidu does not advertise nonexistent sitemap API');
    check(!$registry->getAdapter('google_indexing_api') instanceof \Weline\Seo\Service\Adapter\GoogleIndexingApiAdapter, 'Legacy Google account does not push ordinary pages to restricted Indexing API');
    if (class_exists(\Weline\Seo\Service\SeoAccountVerifier::class)) {
        fixture([['code' => 200, 'body' => '{"d":{"DailyQuota":7}}']]);
        $result = (new \Weline\Seo\Service\SeoAccountVerifier())->verify('bing', ['api_key' => 'secret', 'site_url' => 'https://example.com']);
        check(($result['remote_verified'] ?? false) && str_contains($GLOBALS['requests'][0][CURLOPT_URL] ?? '', '/GetUrlSubmissionQuota?') && !isset($GLOBALS['requests'][0][CURLOPT_POSTFIELDS]), 'Account verification reads Bing quota without URL submission');
        $result = (new \Weline\Seo\Service\SeoAccountVerifier())->verify('baidu', ['token' => 'secret', 'site' => 'https://example.com']);
        check(($result['status'] ?? '') === 'configuration_valid' && !($result['remote_verified'] ?? true), 'Baidu verification does not claim remote token acceptance');
    } else { check(false, 'Read-only account verification exists'); }
    if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
    echo "SEO submission adapter contracts passed\n";
}

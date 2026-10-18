<?php
// One-shot probe for FPC invalidation closed loop (also mirrored in e2e F-case).
require dirname(__DIR__, 7) . '/bootstrap.php';

use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Controller\Extra\ExtraPolicyResolver;
use Weline\Framework\Event\Changed\ChangedPipeline;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Http\Fpc\FpcPayloadPathIndex;
use Weline\Framework\Http\Fpc\FpcStoreAdapterRegistry;
use Weline\Framework\Manager\ObjectManager;

$r = ObjectManager::getInstance(ExtraPolicyResolver::class);
$ns = ObjectManager::getInstance(NamespaceGenerationInterface::class);
$factory = ObjectManager::getInstance(ResourceChangeFactory::class);
$pipeline = ObjectManager::getInstance(ChangedPipeline::class);

$checks = [
    ['path' => '/product/x', 'need' => 'website/default/catalog'],
    ['path' => '/about', 'need' => 'website/default/theme'],
    ['path' => '/currency', 'need' => 'global/storefront/price'],
    ['path' => '/faq', 'need' => 'website/default/cms'],
    ['path' => '/blog', 'need' => 'global/storefront/blog'],
];
foreach ($checks as $c) {
    $row = $r->resolveForPath($c['path'], 'fpc');
    if (!$row) {
        fwrite(STDERR, 'NO_EXTRA ' . $c['path'] . PHP_EOL);
        exit(2);
    }
    $nslist = (array)($row['namespaces'] ?? []);
    if (!in_array($c['need'], $nslist, true)) {
        fwrite(STDERR, 'NS_MISS ' . $c['path'] . ' need=' . $c['need'] . ' have=' . json_encode($nslist) . PHP_EOL);
        exit(3);
    }
}

$probes = [
    ['type' => 'product_search_projection', 'ns' => ['website/default/catalog'], 'urls' => ['/product/demo', '/', '/products'], 'action' => 'upsert'],
    ['type' => 'catalog_category', 'ns' => ['website/default/catalog'], 'urls' => ['/', '/categories', '/category/probe'], 'action' => 'upsert'],
    ['type' => 'theme_layout', 'ns' => ['website/default/theme'], 'urls' => ['/'], 'action' => 'upsert'],
    ['type' => 'theme_layout', 'ns' => ['website/default/theme'], 'urls' => ['/policy/privacy'], 'action' => 'publish', 'id' => 'policy'],
    ['type' => 'blog.post', 'ns' => ['global/storefront/blog'], 'urls' => ['/blog', '/blog/probe-post'], 'action' => 'upsert'],
    ['type' => 'cms_page', 'ns' => ['website/default/cms'], 'urls' => ['/cms-demo'], 'action' => 'upsert'],
    ['type' => 'faq.item', 'ns' => ['website/default/cms', 'website/default/theme'], 'urls' => ['/faq'], 'action' => 'upsert'],
];
foreach ($probes as $i => $p) {
    $before = $ns->fingerprint($p['ns']);
    $change = $factory->create(
        resourceType: $p['type'],
        resourceId: 'probe-' . ($p['id'] ?? ($i + 1)),
        action: (string)($p['action'] ?? 'upsert'),
        revision: time() + $i,
        websiteId: 0,
        websiteCode: 'default',
        before: ['k' => 1],
        after: ['k' => 2, 'status' => 'published', 'layout_type' => 'policy', 'layout_option' => 'privacy'],
        changedFields: ['k'],
        impact: [
            'namespaces' => $p['ns'],
            'urls' => $p['urls'],
            'previous_urls' => [],
            'previous_namespaces' => [],
        ],
        origin: ['entry' => 'fpc_loop_probe'],
    );
    try {
        $pipeline->process($change);
    } catch (\Throwable $e) {
        // AFTER_COMMIT CDN purge may fail without a bound Cloudflare account; SYNC bump still counts.
        $msg = $e->getMessage();
        if (!str_contains($msg, '账户') && !str_contains($msg, 'account') && !str_contains($msg, 'credential')) {
            fwrite(STDERR, 'PROCESS_FAIL ' . $p['type'] . ' ' . $msg . PHP_EOL);
            exit(7);
        }
    }
    $after = $ns->fingerprint($p['ns']);
    if ($before === $after) {
        fwrite(STDERR, 'NO_BUMP ' . $p['type'] . PHP_EOL);
        exit(4);
    }
    $label = $p['type'] . (isset($p['id']) ? ':' . $p['id'] : '');
    echo 'BUMP_OK ' . $label . PHP_EOL;
}

// Disk path-index purge: target gone, control path still indexed.
$adapter = ObjectManager::getInstance(FpcStoreAdapterRegistry::class)->active();
if ($adapter === null) {
    fwrite(STDERR, 'NO_FPC_ADAPTER' . PHP_EOL);
    exit(5);
}
$payloadDir = VAR_DIR . 'cache' . DIRECTORY_SEPARATOR . 'router-fpc-payloads'
    . DIRECTORY_SEPARATOR . 'ff';
if (!is_dir($payloadDir)) {
    @mkdir($payloadDir, 0775, true);
}
$pairs = [
    ['url' => 'https://probe.test.weline.com/product/fpc-path-index-probe', 'file' => 'closed-loop-probe.br'],
    ['url' => 'https://probe.test.weline.com/blog/fpc-control-still-hit', 'file' => 'closed-loop-control.br'],
];
foreach ($pairs as $row) {
    $body = $payloadDir . DIRECTORY_SEPARATOR . $row['file'];
    file_put_contents($body, 'probe-br');
    FpcPayloadPathIndex::remember($row['url'], 'unified-fpc-probe-' . md5($row['url']), $body);
}
$targetUrl = $pairs[0]['url'];
$controlUrl = $pairs[1]['url'];
$targetBody = $payloadDir . DIRECTORY_SEPARATOR . $pairs[0]['file'];
$controlBody = $payloadDir . DIRECTORY_SEPARATOR . $pairs[1]['file'];
$adapter->purgeUrls([$targetUrl]);
if (is_file($targetBody)) {
    fwrite(STDERR, 'DISK_PURGE_FAIL ' . $targetUrl . PHP_EOL);
    exit(6);
}
if (!is_file($controlBody) || FpcPayloadPathIndex::lookup($controlUrl) === []) {
    fwrite(STDERR, 'CONTROL_DISK_GONE ' . $controlUrl . PHP_EOL);
    exit(8);
}
echo 'DISK_PURGE_OK ' . $targetUrl . PHP_EOL;
echo 'CONTROL_HIT_OK ' . $controlUrl . PHP_EOL;
$adapter->purgeUrls([$controlUrl]);
echo 'LOOP_OK' . PHP_EOL;

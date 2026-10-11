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
    ['type' => 'product_search_projection', 'ns' => ['website/default/catalog'], 'urls' => ['/product/demo', '/', '/products']],
    ['type' => 'catalog_category', 'ns' => ['website/default/catalog'], 'urls' => ['/', '/categories', '/category/probe']],
    ['type' => 'theme_layout', 'ns' => ['website/default/theme'], 'urls' => ['/']],
    ['type' => 'blog.post', 'ns' => ['global/storefront/blog'], 'urls' => ['/blog', '/blog/probe-post']],
    ['type' => 'cms_page', 'ns' => ['website/default/cms'], 'urls' => ['/cms-demo']],
    ['type' => 'faq.item', 'ns' => ['website/default/cms', 'website/default/theme'], 'urls' => ['/faq']],
];
foreach ($probes as $i => $p) {
    $before = $ns->fingerprint($p['ns']);
    $change = $factory->create(
        resourceType: $p['type'],
        resourceId: 'probe-' . ($i + 1),
        action: 'upsert',
        revision: time() + $i,
        websiteId: 0,
        websiteCode: 'default',
        before: ['k' => 1],
        after: ['k' => 2, 'status' => 'published'],
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
    echo 'BUMP_OK ' . $p['type'] . PHP_EOL;
}

// Disk path-index purge: body file must disappear after adapter purgeUrls.
$probePath = '/product/fpc-path-index-probe';
$payloadDir = BP . 'var' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'router-fpc-payloads'
    . DIRECTORY_SEPARATOR . 'ff';
if (!is_dir($payloadDir)) {
    @mkdir($payloadDir, 0775, true);
}
$body = $payloadDir . DIRECTORY_SEPARATOR . 'closed-loop-probe.br';
file_put_contents($body, 'probe-br');
FpcPayloadPathIndex::remember($probePath, 'unified-fpc-probe-key', $body);
$adapter = ObjectManager::getInstance(FpcStoreAdapterRegistry::class)->active();
if ($adapter === null) {
    fwrite(STDERR, 'NO_FPC_ADAPTER' . PHP_EOL);
    exit(5);
}
$adapter->purgeUrls([$probePath]);
if (is_file($body)) {
    fwrite(STDERR, 'DISK_PURGE_FAIL ' . $probePath . PHP_EOL);
    exit(6);
}
echo 'DISK_PURGE_OK ' . $probePath . PHP_EOL;
echo 'LOOP_OK' . PHP_EOL;

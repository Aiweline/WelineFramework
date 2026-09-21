<?php
// One-shot probe for FPC invalidation closed loop (also mirrored in e2e F-case).
require dirname(__DIR__, 7) . '/bootstrap.php';

use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Controller\Extra\ExtraPolicyResolver;
use Weline\Framework\Event\Changed\ChangedPipeline;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
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
    ['type' => 'product_search_projection', 'ns' => ['website/default/catalog'], 'urls' => ['/product/demo']],
    ['type' => 'theme_layout', 'ns' => ['website/default/theme'], 'urls' => []],
    ['type' => 'blog.post', 'ns' => ['global/storefront/blog'], 'urls' => []],
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
        after: ['k' => 2],
        changedFields: ['k'],
        impact: [
            'namespaces' => $p['ns'],
            'urls' => $p['urls'],
            'previous_urls' => [],
            'previous_namespaces' => [],
        ],
        origin: ['entry' => 'fpc_loop_probe'],
    );
    $pipeline->process($change);
    $after = $ns->fingerprint($p['ns']);
    if ($before === $after) {
        fwrite(STDERR, 'NO_BUMP ' . $p['type'] . PHP_EOL);
        exit(4);
    }
    echo 'BUMP_OK ' . $p['type'] . PHP_EOL;
}
echo 'LOOP_OK' . PHP_EOL;

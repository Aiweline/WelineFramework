<?php

declare(strict_types=1);

/**
 * Seed PlayingInChina categories + published products for website code=playinginchina.
 *
 * Usage:
 *   php app/design/Weline/playinginchina/scripts/seed-catalog.php
 *   php app/design/Weline/playinginchina/scripts/seed-catalog.php playinginchina
 *   php app/design/Weline/playinginchina/scripts/seed-catalog.php playinginchina --no-tickets
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Websites\Model\Website;

require dirname(__DIR__, 5) . '/app/bootstrap.php';
require_once __DIR__ . '/PlayingInChinaCatalogSeeder.php';
require_once dirname(__DIR__) . '/frontend/includes/PlayingInChinaData.php';

$requestedCode = trim((string)($argv[1] ?? 'playinginchina'));
if ($requestedCode === '' || $requestedCode === '0' || $requestedCode === 'default') {
    fwrite(STDERR, "Refuse: PlayingInChina catalog seed must target code=playinginchina, not default/0.\n");
    exit(1);
}
if ($requestedCode !== 'playinginchina') {
    fwrite(STDERR, "Refuse: only website code playinginchina is accepted.\n");
    exit(1);
}
$withTickets = !in_array('--no-tickets', $argv, true);

$websiteModel = ObjectManager::getInstance(Website::class);
$rows = $websiteModel->reset()->where(Website::schema_primary_key, 0, '>=')->select()->fetchArray();
$websiteId = 0;
foreach ($rows as $row) {
    if ((string)($row['code'] ?? '') === $requestedCode) {
        $websiteId = (int)($row['website_id'] ?? 0);
        break;
    }
}
if ($websiteId <= 0 || $websiteId === Website::ID_DEFAULT) {
    fwrite(STDERR, "Website playinginchina not found or resolved to default.\n");
    exit(1);
}

ObjectManager::getInstance(ProductShardProvisioner::class)->provisionWebsite($websiteId);

$php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
$categoryScript = dirname(__DIR__) . '/data/seed-categories.php';
passthru(escapeshellarg($php) . ' ' . escapeshellarg($categoryScript) . ' playinginchina', $catExit);
if ($catExit !== 0) {
    exit($catExit);
}

$result = \Weline\Design\PlayingInChina\PlayingInChinaCatalogSeeder::create()
    ->seed($websiteId, 'CNY', 99, $withTickets);

echo json_encode([
    'ok' => true,
    'website_id' => $websiteId,
    'website_code' => $requestedCode,
    'with_tickets' => $withTickets,
    'seed' => $result,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

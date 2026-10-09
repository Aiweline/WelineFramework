<?php

declare(strict_types=1);

/**
 * 为本机 grocery 站开启地点跟随售卖双开关（website 存储 scope）。
 *
 * Usage:
 *   php app/design/Weline/grocery/scripts/enable-location-sell.php
 *   php app/design/Weline/grocery/scripts/enable-location-sell.php grocery
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Shipping\Service\LocationSellConfig;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;
use Weline\Websites\Model\Website;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$requestedCode = trim((string)($argv[1] ?? 'grocery'));
if ($requestedCode === '' || $requestedCode === '0' || $requestedCode === 'default') {
    fwrite(STDERR, "Refuse: must target website code grocery (or explicit non-default code).\n");
    exit(1);
}

/** @var Website $websiteModel */
$websiteModel = ObjectManager::getInstance(Website::class);
$websiteId = 0;
$websiteCode = '';
foreach ($websiteModel->reset()->where(Website::schema_primary_key, 0, '>=')->select()->fetchArray() as $row) {
    $code = (string)($row['code'] ?? '');
    $id = (int)($row['website_id'] ?? 0);
    $url = (string)($row['url'] ?? '');
    if ($code === $requestedCode || $id === 544 || str_contains($url, 'grocery.test.weline.com')) {
        if ($requestedCode !== 'grocery' && $code !== $requestedCode) {
            continue;
        }
        $websiteId = $id;
        $websiteCode = $code !== '' ? $code : $requestedCode;
        break;
    }
}

if ($websiteId <= 0 || $websiteId === Website::ID_DEFAULT) {
    fwrite(STDERR, "Website grocery not found (expected code=grocery or id=544).\n");
    exit(1);
}

$identity = ScopeIdentity::website($websiteId, $websiteCode !== '' ? $websiteCode : 'grocery');
/** @var SystemConfigScopeResolver $resolver */
$resolver = ObjectManager::getInstance(SystemConfigScopeResolver::class);
$storageScope = $resolver->toStorageScope($identity);

/** @var SystemConfig $config */
$config = ObjectManager::getInstance(SystemConfig::class);
$keys = [
    LocationSellConfig::KEY_LOCATION_SELL_FILTER,
    LocationSellConfig::KEY_SELL_ONLY_FULFILLMENT_COUNTRIES,
];
$ok = true;
foreach ($keys as $key) {
    $saved = $config->setScopedConfig(
        $key,
        '1',
        LocationSellConfig::MODULE,
        LocationSellConfig::AREA,
        $storageScope,
    );
    $ok = $ok && $saved;
    $read = (string)$config->getConfig($key, LocationSellConfig::MODULE, LocationSellConfig::AREA, '0', $storageScope);
    echo sprintf(
        "website_id=%d code=%s scope=%s key=%s saved=%s value=%s\n",
        $websiteId,
        $websiteCode,
        $storageScope,
        $key,
        $saved ? 'yes' : 'no',
        $read
    );
}

exit($ok ? 0 : 1);

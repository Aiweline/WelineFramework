<?php

declare(strict_types=1);

/**
 * E2E 辅助：创建投放渠道（同库）。
 * 用法：php create-channel.php <code> <name> [traffic_type=paid] [website_id=0]
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Service\PixelChannelCreateService;

require dirname(__DIR__, 7) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$code = (string)($argv[1] ?? '');
$name = (string)($argv[2] ?? '');
$trafficType = (string)($argv[3] ?? 'paid');
$websiteId = (int)($argv[4] ?? 0);

if ($code === '' || $name === '') {
    fwrite(STDERR, "usage: php create-channel.php <code> <name> [traffic_type] [website_id]\n");
    exit(2);
}

/** @var PixelChannelCreateService $create */
$create = ObjectManager::getInstance(PixelChannelCreateService::class);
$result = $create->createCampaign([
    'code' => $code,
    'name' => $name,
    'traffic_type' => $trafficType,
    'website_id' => $websiteId,
    'description' => 'e2e',
    'enabled' => 1,
    'utm_source' => 'weline_e2e',
    'utm_medium' => 'cpc',
]);

echo json_encode([
    'ok' => !empty($result['ok']),
    'result' => $result,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit(!empty($result['ok']) ? 0 : 1);

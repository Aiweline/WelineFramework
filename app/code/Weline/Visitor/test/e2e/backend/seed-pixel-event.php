<?php

declare(strict_types=1);

/**
 * E2E 辅助：同进程调用 PixelEventService::track + 强制 flush。
 * 用法：php seed-pixel-event.php <channel_code> <landing_url> <session_id>
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Service\PixelEventService;
use Weline\Visitor\Service\PixelHotBufferService;

require dirname(__DIR__, 7) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$code = (string)($argv[1] ?? '');
$landingUrl = (string)($argv[2] ?? '');
$sessionId = (string)($argv[3] ?? ('e2e_sess_' . $code));

if ($code === '' || $landingUrl === '') {
    fwrite(STDERR, "usage: php seed-pixel-event.php <channel_code> <landing_url> [session_id]\n");
    exit(2);
}

/** @var PixelEventService $tracker */
$tracker = ObjectManager::getInstance(PixelEventService::class);
$track = $tracker->track([
    'event' => 'page_view',
    'eventName' => 'page_view',
    'url' => $landingUrl,
    'websiteId' => 0,
    'website_id' => 0,
    'session_id' => $sessionId,
    'additionalInfo' => [
        'environment' => [
            'session_id' => $sessionId,
            'page_path' => '/',
        ],
        'funnel' => [
            'session_id' => $sessionId,
        ],
    ],
]);

/** @var PixelHotBufferService $buffer */
$buffer = ObjectManager::getInstance(PixelHotBufferService::class);
$flush = $buffer->flushDue(true, 500);

echo json_encode([
    'ok' => true,
    'channel_code' => $code,
    'track' => $track,
    'flush' => $flush,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

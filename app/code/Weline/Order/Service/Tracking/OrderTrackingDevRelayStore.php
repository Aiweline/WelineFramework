<?php

declare(strict_types=1);

namespace Weline\Order\Service\Tracking;

/**
 * 本机开发中继事件环形缓冲（文件存储，非生产权威源）。
 */
final class OrderTrackingDevRelayStore
{
    private function path(): string
    {
        $dir = BP . 'var';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . '/order-tracking-dev-relay-events.json';
    }

    /**
     * @param array<string, mixed> $event
     */
    public function append(array $event): void
    {
        $events = $this->listRecent(200);
        array_unshift($events, $event);
        $events = array_slice($events, 0, 200);
        file_put_contents($this->path(), json_encode($events, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecent(int $limit = 50): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_slice($decoded, 0, max(1, $limit)));
    }
}

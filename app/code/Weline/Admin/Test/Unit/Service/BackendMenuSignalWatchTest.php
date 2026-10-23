<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Admin\Api\BackendMenuSignalItemsProviderInterface;
use Weline\Admin\Api\BackendMenuSignalProviderInterface;
use Weline\Admin\Service\BackendMenuSignalAggregator;
use Weline\Admin\Service\BackendMenuSignalProviderRegistry;
use Weline\Admin\Service\BackendMenuSignalWatch;

final class BackendMenuSignalWatchTest extends TestCase
{
    public function testEmitsProviderSnapshotWhenUnreadChangesAndSkipsUnchangedSlice(): void
    {
        $provider = new MenuSignalWatchProbeProvider(1);
        $watch = $this->watch($provider);

        $first = iterator_to_array($watch->watch(7, '', 0, 0));
        self::assertCount(1, $first);
        self::assertSame('menu_signals', $first[0]['event']);
        self::assertSame(1, $first[0]['data']['total']);
        self::assertSame('新消息', $first[0]['data']['by_code']->{'Weline_Admin::watch_probe'}['items'][0]['title']);
        $fingerprint = (string)$first[0]['data']['fingerprint'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fingerprint);

        $quiet = iterator_to_array($watch->watch(7, $fingerprint, 0, 0));
        self::assertSame([], $quiet);

        $provider->count = 2;
        $changed = iterator_to_array($watch->watch(7, $fingerprint, 0, 0));
        self::assertCount(1, $changed);
        self::assertSame(2, $changed[0]['data']['total']);
        self::assertNotSame($fingerprint, $changed[0]['data']['fingerprint']);
    }

    public function testInvalidSinceIsIgnoredSoTheCurrentProviderSnapshotIsSent(): void
    {
        $watch = $this->watch(new MenuSignalWatchProbeProvider(1));
        $events = iterator_to_array($watch->watch(7, 'not-a-fingerprint', 0, 0));

        self::assertCount(1, $events);
        self::assertSame('menu_signals', $events[0]['event']);
    }

    private function watch(MenuSignalWatchProbeProvider $provider): BackendMenuSignalWatch
    {
        return new BackendMenuSignalWatch(new BackendMenuSignalAggregator(
            BackendMenuSignalProviderRegistry::forTesting([$provider])
        ));
    }
}

final class MenuSignalWatchProbeProvider implements
    BackendMenuSignalProviderInterface,
    BackendMenuSignalItemsProviderInterface
{
    public function __construct(public int $count)
    {
    }

    public function code(): string
    {
        return 'Weline_Admin::watch_probe';
    }

    public function count(int $backendUserId): int
    {
        return $backendUserId > 0 ? $this->count : 0;
    }

    public function items(int $backendUserId): array
    {
        if ($backendUserId <= 0 || $this->count <= 0) {
            return [];
        }

        $items = [];
        for ($i = 1; $i <= $this->count; $i++) {
            $items[] = [
                'id' => (string)$i,
                'title' => '新消息',
                'href' => '/admin/watch-probe?id=' . $i,
                'unread' => true,
            ];
        }

        return $items;
    }
}

<?php

declare(strict_types=1);

namespace Weline\Admin\Service;

use Weline\Framework\Runtime\SchedulerSystem;

/**
 * Bounded SSE watch over registered menu-signal providers.
 *
 * Each provider keeps its own unread logic (count/items). This watcher only
 * re-reads them and emits a snapshot when that aggregate changes.
 */
final class BackendMenuSignalWatch
{
    public const DEFAULT_SLICE_SECONDS = 20;

    public const DEFAULT_POLL_MILLISECONDS = 2000;

    public function __construct(
        private readonly BackendMenuSignalAggregator $aggregator,
    ) {
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function watch(
        int $backendUserId,
        string $since = '',
        int $sliceSeconds = self::DEFAULT_SLICE_SECONDS,
        int $pollMilliseconds = self::DEFAULT_POLL_MILLISECONDS,
    ): \Generator {
        $backendUserId = max(0, $backendUserId);
        $previous = $this->normalizeSince($since);
        $deadline = \microtime(true) + max(0, $sliceSeconds);
        $pollMilliseconds = max(0, $pollMilliseconds);

        do {
            $snapshot = $backendUserId > 0
                ? $this->aggregator->aggregate($backendUserId)
                : ['total' => 0, 'by_code' => []];
            $fingerprint = self::fingerprint($snapshot);
            if ($fingerprint !== $previous) {
                $previous = $fingerprint;
                yield [
                    'event' => 'menu_signals',
                    'data' => [
                        'total' => (int)($snapshot['total'] ?? 0),
                        'by_code' => (object)($snapshot['by_code'] ?? []),
                        'fingerprint' => $fingerprint,
                    ],
                ];
            }

            if (\microtime(true) >= $deadline) {
                return;
            }

            yield ['transport' => 'heartbeat'];
            SchedulerSystem::yieldDelay($pollMilliseconds);
        } while (true);
    }

    /**
     * @param array{total?:int, by_code?:array<string, mixed>} $snapshot
     */
    public static function fingerprint(array $snapshot): string
    {
        $encoded = \json_encode(
            [
                'total' => (int)($snapshot['total'] ?? 0),
                'by_code' => $snapshot['by_code'] ?? [],
            ],
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
        );

        return \hash('sha256', \is_string($encoded) ? $encoded : '');
    }

    private function normalizeSince(string $since): string
    {
        $since = \strtolower(\trim($since));

        return \preg_match('/^[a-f0-9]{64}$/', $since) === 1 ? $since : '';
    }
}

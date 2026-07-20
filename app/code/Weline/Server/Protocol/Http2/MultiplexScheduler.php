<?php
declare(strict_types=1);

namespace Weline\Server\Protocol\Http2;

/**
 * Canonical (connection, stream) identity used by the TLS Worker Fiber map.
 */
final class MultiplexScheduler
{
    public static function key(int $connectionId, int $streamId): string
    {
        if ($connectionId <= 0 || $streamId <= 0) {
            throw new \InvalidArgumentException('HTTP/2 Fiber identity requires positive connection and stream ids.');
        }
        return 'h2:' . $connectionId . ':' . $streamId;
    }

    /** @param array<string,mixed> $state */
    public static function connectionId(int|string $key, array $state): int
    {
        if (isset($state['conn_id'])) {
            return (int)$state['conn_id'];
        }
        if (\is_int($key)) {
            return $key;
        }
        if (\preg_match('/^h2:(\d+):\d+$/D', $key, $matches) === 1) {
            return (int)$matches[1];
        }
        return 0;
    }

    /** @param array<string,mixed> $state */
    public static function streamId(int|string $key, array $state): int
    {
        if (isset($state['http2_stream_id'])) {
            return (int)$state['http2_stream_id'];
        }
        if (\is_string($key) && \preg_match('/^h2:\d+:(\d+)$/D', $key, $matches) === 1) {
            return (int)$matches[1];
        }
        return 0;
    }

    /** @param array<int|string,array<string,mixed>> $activeFibers */
    public static function activeStreamCount(array $activeFibers, int $connectionId): int
    {
        $count = 0;
        foreach ($activeFibers as $key => $state) {
            if (self::connectionId($key, $state) === $connectionId
                && self::streamId($key, $state) > 0
            ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param array<int|string,array<string,mixed>> $activeFibers
     * @return list<int|string>
     */
    public static function keysForConnection(array $activeFibers, int $connectionId, ?int $streamId = null): array
    {
        $keys = [];
        foreach ($activeFibers as $key => $state) {
            if (self::connectionId($key, $state) !== $connectionId) {
                continue;
            }
            if ($streamId !== null && self::streamId($key, $state) !== $streamId) {
                continue;
            }
            $keys[] = $key;
        }
        return $keys;
    }

    /** @return array{ok:bool,reason:string} */
    public static function selfTest(): array
    {
        $first = self::key(11, 1);
        $second = self::key(11, 3);
        $states = [
            $first => ['conn_id' => 11, 'http2_stream_id' => 1],
            $second => ['conn_id' => 11, 'http2_stream_id' => 3],
        ];
        $ok = $first !== $second
            && self::connectionId($first, $states[$first]) === 11
            && self::streamId($second, $states[$second]) === 3
            && self::activeStreamCount($states, 11) === 2
            && self::keysForConnection($states, 11, 1) === [$first];

        return [
            'ok' => $ok,
            'reason' => $ok
                ? 'HTTP/2 (connection,stream) scheduler identity self-test passed'
                : 'HTTP/2 scheduler identity collision',
        ];
    }
}

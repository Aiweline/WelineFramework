<?php

declare(strict_types=1);

namespace Weline\Search\Service;

/** 只确认显式保留的事件身份，不能用最大序号推断中间事件已完成。 */
final class SearchIncrementalEventCoverage
{
    /**
     * @param list<array{event_id:string,event_seq:int}> $coveredEvents
     * @return list<array{event_seq:int,idempotency_key:string}>
     */
    public static function identities(int $eventSeq, string $idempotencyKey, array $coveredEvents): array
    {
        $events = [$eventSeq => ['event_seq' => $eventSeq, 'idempotency_key' => $idempotencyKey]];
        $keys = [$idempotencyKey => $eventSeq];
        foreach ($coveredEvents as $covered) {
            if (!is_array($covered)
                || count($covered) !== 2
                || !is_int($covered['event_seq'] ?? null)
                || $covered['event_seq'] < 1
                || $covered['event_seq'] > $eventSeq
                || !is_string($covered['event_id'] ?? null)
                || preg_match('/^[a-f0-9]{32}$/D', $covered['event_id']) !== 1
            ) {
                throw new \InvalidArgumentException('search_incremental_coverage_invalid');
            }
            $sequence = $covered['event_seq'];
            $key = 'resource-change:' . $covered['event_id'];
            if ((isset($events[$sequence]) && $events[$sequence]['idempotency_key'] !== $key)
                || (isset($keys[$key]) && $keys[$key] !== $sequence)
            ) {
                throw new \InvalidArgumentException('search_incremental_coverage_identity_conflict');
            }
            $events[$sequence] = ['event_seq' => $sequence, 'idempotency_key' => $key];
            $keys[$key] = $sequence;
        }
        ksort($events, SORT_NUMERIC);
        return array_values($events);
    }
}

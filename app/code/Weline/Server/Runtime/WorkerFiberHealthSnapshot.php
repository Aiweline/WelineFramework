<?php

declare(strict_types=1);

namespace Weline\Server\Runtime;

/**
 * Fiber 健康检查快照（明文 / SSL Worker 共用，避免 bin 脚本双份维护）。
 */
final class WorkerFiberHealthSnapshot
{
    /**
     * @param array<int, array{fiber?: \Fiber, rawRequest?: string}> $activeFibers
     * @return list<array{conn_id: int, status: string, protocol: string}>
     */
    public static function build(array $activeFibers): array
    {
        static $resolver = null;
        if ($resolver === null) {
            $resolver = new \Weline\Server\Service\Protocol\LongLived\ProtocolResolver();
        }

        $list = [];
        foreach ($activeFibers as $connId => $afData) {
            $fiber = $afData['fiber'] ?? null;
            $raw = $afData['rawRequest'] ?? '';
            $status = ($fiber instanceof \Fiber && $fiber->isSuspended()) ? 'idle' : 'busy';
            $detected = $resolver->detect($raw);
            $protocol = (string) ($detected['protocol'] ?? 'http');
            $list[] = ['conn_id' => $connId, 'status' => $status, 'protocol' => $protocol];
        }

        return $list;
    }
}

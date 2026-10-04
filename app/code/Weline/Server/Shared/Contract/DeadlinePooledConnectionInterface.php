<?php
declare(strict_types=1);

namespace Weline\Server\Shared\Contract;

/** 可选能力：在当前独占租约内，按绝对单调时钟截止时间发送并收取原请求回复。 */
interface DeadlinePooledConnectionInterface extends PooledConnectionInterface
{
    public function sendUntil(string $payload, float $deadline): bool;

    public function readUntil(float $deadline): ?array;
}

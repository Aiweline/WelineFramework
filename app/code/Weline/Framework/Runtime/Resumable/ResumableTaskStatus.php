<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

enum ResumableTaskStatus: string
{
    case STARTING = 'starting';
    case RUNNING = 'running';
    case RECOVERING = 'recovering';
    case CANCEL_REQUESTED = 'cancel_requested';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
    case RECOVERY_UNSAFE = 'recovery_unsafe';
    case EVENT_BACKLOG_LIMIT = 'event_backlog_limit';

    public function isTerminal(): bool
    {
        return \in_array($this, [
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
            self::EXPIRED,
            self::RECOVERY_UNSAFE,
            self::EVENT_BACKLOG_LIMIT,
        ], true);
    }

    public function isActive(): bool
    {
        return !$this->isTerminal();
    }
}

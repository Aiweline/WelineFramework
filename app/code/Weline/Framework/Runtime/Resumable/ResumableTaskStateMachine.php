<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Central, side-effect-free definition of the persisted task lifecycle.
 */
final class ResumableTaskStateMachine
{
    /**
     * @return list<ResumableTaskStatus>
     */
    public static function transitionsFrom(ResumableTaskStatus $from): array
    {
        return match ($from) {
            ResumableTaskStatus::STARTING => [
                ResumableTaskStatus::RUNNING,
                // The independently launched CLI process can fail before it
                // ever acquires its reservation. Keep that transient launch
                // failure recoverable; no task business code has run yet.
                ResumableTaskStatus::RECOVERING,
                ResumableTaskStatus::FAILED,
                ResumableTaskStatus::CANCELLED,
                ResumableTaskStatus::EXPIRED,
                // A launch identity that cannot be proven safe to stop or
                // resume must be surfaced instead of blindly retrying it.
                ResumableTaskStatus::RECOVERY_UNSAFE,
            ],
            ResumableTaskStatus::RUNNING => [
                ResumableTaskStatus::COMPLETED,
                ResumableTaskStatus::FAILED,
                ResumableTaskStatus::RECOVERING,
                ResumableTaskStatus::CANCEL_REQUESTED,
                ResumableTaskStatus::RECOVERY_UNSAFE,
                ResumableTaskStatus::EVENT_BACKLOG_LIMIT,
            ],
            ResumableTaskStatus::RECOVERING => [
                ResumableTaskStatus::RUNNING,
                ResumableTaskStatus::FAILED,
                ResumableTaskStatus::CANCEL_REQUESTED,
                ResumableTaskStatus::RECOVERY_UNSAFE,
                ResumableTaskStatus::EVENT_BACKLOG_LIMIT,
            ],
            ResumableTaskStatus::CANCEL_REQUESTED => [
                ResumableTaskStatus::CANCELLED,
                ResumableTaskStatus::EXPIRED,
            ],
            ResumableTaskStatus::COMPLETED,
            ResumableTaskStatus::FAILED,
            ResumableTaskStatus::CANCELLED,
            ResumableTaskStatus::EXPIRED,
            ResumableTaskStatus::RECOVERY_UNSAFE,
            ResumableTaskStatus::EVENT_BACKLOG_LIMIT => [],
        };
    }

    public static function canTransition(ResumableTaskStatus $from, ResumableTaskStatus $to): bool
    {
        return \in_array($to, self::transitionsFrom($from), true);
    }

    public static function assertTransition(ResumableTaskStatus $from, ResumableTaskStatus $to): void
    {
        if (!self::canTransition($from, $to)) {
            throw new InvalidTaskStateTransition($from, $to);
        }
    }
}

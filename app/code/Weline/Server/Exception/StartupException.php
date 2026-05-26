<?php
declare(strict_types=1);

namespace Weline\Server\Exception;

class StartupException extends WlsException
{
    /**
     * @param list<string> $pending
     * @param array<string, mixed> $context
     * @param list<string> $diagnostics
     */
    public static function readyTimeout(
        float $timeoutSec,
        float $elapsedSec,
        array $pending,
        array $context,
        array $diagnostics
    ): self {
        $pendingLabel = $pending !== [] ? \implode(', ', $pending) : '(none)';
        $message = '启动验收超时：计划进程 '
            . \number_format($timeoutSec, 2, '.', '')
            . 's 内未全部 READY，pending='
            . $pendingLabel
            . ', elapsed='
            . \number_format($elapsedSec, 2, '.', '')
            . 's';

        return new self(
            'WLS_STARTUP_READY_TIMEOUT',
            $message,
            $context + [
                'timeout_sec' => \number_format($timeoutSec, 2, '.', ''),
                'elapsed_sec' => \number_format($elapsedSec, 2, '.', ''),
                'pending' => $pending,
            ],
            $diagnostics
        );
    }

    /**
     * @param list<string> $pending
     * @param array<string, mixed> $context
     * @param list<string> $diagnostics
     */
    public static function failFast(
        string $reason,
        array $pending,
        array $context,
        array $diagnostics
    ): self {
        $pendingLabel = $pending !== [] ? \implode(', ', $pending) : '(none)';

        return new self(
            'WLS_STARTUP_FAIL_FAST',
            '启动快速失败：' . $reason . ', pending=' . $pendingLabel,
            $context + [
                'reason' => $reason,
                'pending' => $pending,
            ],
            $diagnostics
        );
    }
}

<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl;

use Weline\Framework\System\IPC\IpcLoggerInterface;
use Weline\Framework\System\IPC\OrphanGuard as FrameworkOrphanGuard;
use Weline\Server\Log\WlsLogger;

/**
 * WLS Master 孤儿保护
 *
 * 继承框架层 OrphanGuard，注入 WlsLogger 适配器。
 *
 * @see \Weline\Framework\System\IPC\OrphanGuard 框架通用版
 */
final class MasterOrphanGuard extends FrameworkOrphanGuard
{
    public function __construct(
        int $checkIntervalSec = 30,
        int $deadThreshold = 3,
        int $unknownMasterDisconnectThreshold = 12,
    ) {
        parent::__construct(
            $checkIntervalSec,
            $deadThreshold,
            $unknownMasterDisconnectThreshold,
            new class implements IpcLoggerInterface {
                public function debug(string $message, array $context = []): void
                {
                    WlsLogger::debug_($message);
                }
                public function info(string $message, array $context = []): void
                {
                    WlsLogger::info_($message);
                }
                public function warning(string $message, array $context = []): void
                {
                    WlsLogger::warning_($message);
                }
                public function error(string $message, array $context = []): void
                {
                    WlsLogger::error_($message);
                }
            }
        );
    }
}

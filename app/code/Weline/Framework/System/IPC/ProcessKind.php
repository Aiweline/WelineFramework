<?php
declare(strict_types=1);

namespace Weline\Framework\System\IPC;

/**
 * 进程归属类型常量
 *
 * 所有使用框架 IPC 通道的进程均需声明其归属：
 * - FRAMEWORK：框架内置进程（WLS Worker、Dispatcher、Cron 等）
 * - MODULE：第三方模块注册的自定义子进程（如支付模块的队列 Worker）
 */
final class ProcessKind
{
    /** 框架内置进程 */
    public const FRAMEWORK = 'framework';

    /** 第三方模块注册的自定义子进程 */
    public const MODULE = 'module';

    private function __construct() {}
}

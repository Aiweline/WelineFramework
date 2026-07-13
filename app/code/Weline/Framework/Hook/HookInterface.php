<?php

declare(strict_types=1);

namespace Weline\Framework\Hook;

/**
 * Hook 扩展点标记契约。
 *
 * @deprecated 2.0 Hook 的唯一事实源是各模块 hook.php 经编译后的注册表。
 *             模块若需 PHP 常量，应在自身 Api 命名空间发布，Framework 不再
 *             声明 Theme、Checkout、Admin 等具体模块的 Hook。
 */
interface HookInterface
{
}

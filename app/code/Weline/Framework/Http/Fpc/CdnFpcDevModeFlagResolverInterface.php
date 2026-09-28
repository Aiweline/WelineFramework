<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

/**
 * CDN Scope「开发模式」开关：按当前请求 Scope 是否绕过公共 FPC。
 * 实现由 Weline_Cdn provides；无实现时恒为 false（fail-open 不旁路）。
 */
interface CdnFpcDevModeFlagResolverInterface
{
    public function isEnabledForCurrentRequest(): bool;
}

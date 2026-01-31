<?php

declare(strict_types=1);

/**
 * Weline Framework - Taglib Circular Dependency Exception
 *
 * @DESC          | 循环依赖异常
 * @Author        | Weline Framework
 * @Package       | Weline\Framework\View\Taglib\Resolver
 */

namespace Weline\Framework\View\Taglib\Resolver;

use Weline\Framework\App\Exception;

/**
 * 循环依赖异常
 *
 * 当标签定义中存在循环依赖时抛出此异常
 */
class CircularDependencyException extends Exception
{
}

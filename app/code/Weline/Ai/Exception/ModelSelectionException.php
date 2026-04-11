<?php

declare(strict_types=1);

namespace Weline\Ai\Exception;

/**
 * 未解析到可用 AI 模型（场景默认 / 全局默认等均不可用）。
 * 使用标准 RuntimeException，避免继承 {@see \Weline\Framework\App\Exception} 时在构造阶段写入 exception.log
 *（上层常捕获后转为业务提示，不应视为未处理异常刷屏日志）。
 */
class ModelSelectionException extends \RuntimeException
{
}

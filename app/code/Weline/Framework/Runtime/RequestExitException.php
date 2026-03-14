<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * 请求退出异常
 *
 * WLS 模式下替代 exit()/die()。Worker 捕获后关闭当前连接，进程继续运行。
 * 继承 \Error 使业务层 catch(\Exception) 不会意外捕获控制流异常。
 */
class RequestExitException extends \Error
{
    public function __construct(
        private readonly int $exitCode = 0,
    ) {
        parent::__construct("Request exit with code {$exitCode}", $exitCode);
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }
}

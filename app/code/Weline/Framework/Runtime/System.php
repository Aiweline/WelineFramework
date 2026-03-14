<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * 运行时系统原语
 *
 * 统一 exit/die 入口：
 * - FPM / CLI：调用原生 exit()
 * - WLS：抛出 RequestExitException，由 Worker 捕获后关闭连接
 *
 * 业务代码应使用 System::exit() 替代原生 exit()/die()。
 */
class System
{
    /**
     * 替代 exit() / die()
     *
     * @param int $code 退出码（0 = 正常退出）
     * @throws RequestExitException 仅 WLS 模式
     */
    public static function exit(int $code = 0): never
    {
        if (Runtime::isWls()) {
            throw new RequestExitException($code);
        }

        exit($code);
    }
}

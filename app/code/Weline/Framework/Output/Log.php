<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：24/1/2024 15:58:32
 */

namespace Weline\Framework\Output;

use Weline\Framework\App\Env;
use Weline\Framework\Output\Debug\AbstractPrint;

class Log extends Debug\AbstractPrint
{
    public bool $printing = false;

    /**
     * @DESC         |日志记录
     *
     * 参数区：
     *
     * @param             $message
     * @param string|null $log_path
     * @param int         $message_type
     *
     * @throws \Weline\Framework\App\Exception
     */
    public function debug($message, string $log_path = null, int $message_type = 3)
    {
        $this->doPrint($data, $message, self::ERROR, 0, $message_type,'debug');
    }

    /**
     * @DESC         |信息日志记录
     *
     * 参数区：
     *
     * @param             $message
     * @param string|null $log_path
     * @param int         $message_type
     *
     * @throws \Weline\Framework\App\Exception
     */
    public function info($message, string $log_path = null, int $message_type = 3)
    {
        $this->doPrint($data, $message, self::NOTE, 0, $message_type,'info');
    }
}
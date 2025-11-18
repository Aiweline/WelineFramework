<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Output\Debug;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;

class Printing extends AbstractPrint
{
    private ?Env $etc;

    public function __construct()
    {
        $this->etc = Env::getInstance();
    }

    public function printing(string $data = 'Printing!', string $message = 'Debug', string $color = self::NOTE, int $pad_length = 0): void
    {
        if (php_sapi_name() !== 'cli') {
            $data = explode(PHP_EOL, $data);
            d($data);
            return;
        }

        $doc_tmp = '【' . $message . '】' . ($pad_length ? str_pad($data, $pad_length) : $data);
        $doc = <<<COMMAND_LIST
        $doc_tmp
        COMMAND_LIST;
        echo $doc;
    }

    /**
     * @DESC         |日志记录
     *
     * 参数区：
     *
     * @param             $message
     * @param string $log_path
     * @param int $message_type
     *
     * @throws Exception
     */
    public function debug($message, string $log_path = '', int $message_type = 3): void
    {
        if ($log_path === '') {
            $log_path = str_replace('\\', DS, $this->etc->getLogPath(Env::log_path_ERROR));
        }
        $this->write($log_path, is_array($message) ? var_export($message, true) : $message, $message_type);
    }
}

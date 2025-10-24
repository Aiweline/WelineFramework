<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Exception;

use Weline\Framework\App\Env;
use Weline\Framework\System\File\Io\File;
use Weline\Framework\Output\Debug\Printing;

class Core extends \Exception
{
    /**
     * @var Env|null
     */
    private ?Env $etc;

    private Printing $_debug;

    private array $config;

    /**
     * Exception 初始函数...
     *
     * @param string $message
     * @param \Exception|null $cause
     * @param int $code
     */
    public function __construct(string $message = '', null|\Exception $cause = null, $code = 0)
    {
        $this->init();

        $this->etc = Env::getInstance();
        $this->config = (array)$this->etc->getConfig();
        $this->_debug = new Printing();

        parent::__construct($message, $code, $cause);
        $this->__toString();


        return $this;
    }

    /**
     * @DESC         |初始化异常或者错误的处理
     *
     * 参数区：
     */
    public function init()
    {
        /**
         * 异常
         */
        set_exception_handler([$this, 'exception']);

        /**
         * 提示
         */
        set_error_handler([$this, 'note'], E_USER_NOTICE);

        /**
         * 错误
         */
        set_error_handler([$this, 'error'], E_USER_ERROR);

        /**
         * 警告
         */
        set_error_handler([$this, 'warning'], E_USER_WARNING);

        /**
         * — 注册一个会在php中止时执行的函数
         */
        register_shutdown_function([$this, 'last_error']);
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     */
    public function note()
    {
        if (DEV) {
            echo $this->prepareHtmlMessage();
        } else {
            print_r('运行警告：请联系管理员进行修复！日志：var/log/note.log' . (CLI ? PHP_EOL : '<br>'));
        }
        $log_path = $this->etc->getLogPath($this->etc::log_path_NOTICE);
        $this->_debug->debug($this->prepareMessage(), $log_path);
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     */
    public function warning()
    {
        $log_path = $this->etc->getLogPath($this->etc::log_path_WARNING);
        if (DEV) {
            echo $this->prepareHtmlMessage();
        } else {
            print_r('运行警告：请联系管理员进行修复！日志：var/log/warning.log' . (CLI ? PHP_EOL : '<br>'));
        }
        $this->_debug->debug($this->prepareMessage(), $log_path, 2);
    }

    /**
     * @DESC         |异常接管
     *
     * 参数区：
     */
    public function exception()
    {
        if (DEV) {
            echo $this->prepareHtmlMessage();
        } else {
            print_r('程序异常：请联系管理员进行修复！日志：var/log/exception.log' . (CLI ? PHP_EOL : '<br>'));
        }
        $log_path = $this->etc->getLogPath($this->etc::log_path_EXCEPTION);
        $this->_debug->debug($this->prepareMessage(), $log_path);
    }

    /**
     * @DESC         |错误接管
     *
     * 参数区：
     */
    public function error()
    {
        // 如果不接收错误则不管
        if (0 === error_reporting()) {
            return false;
        }
        if (DEV) {
            echo $this->prepareHtmlMessage();
        } else {
            print_r('程序异常：请联系管理员进行修复！日志：var/log/error.log' . (CLI ? PHP_EOL : '<br>'));
        }
        $log_path = $this->etc->getLogPath($this->etc::log_path_ERROR);
        $this->_debug->debug($this->prepareMessage(), $log_path, 3);
    }

    /**
     * @DESC         |脚本结束前获取最后错误
     *
     * 参数区：
     */
    public function last_error()
    {
        $last = error_get_last();
        if ($last) {
            if (DEV) {
                echo json_encode($last);
            } else {
                print_r('程序错误：请联系管理员进行修复！日志：var/log/error.log' . (CLI ? PHP_EOL : '<br>'));
            }
            $log_path = $this->etc->getLogPath($this->etc::log_path_ERROR);
            $this->_debug->debug(json_encode($last), $log_path, 3);
        }
    }

    /**
     * @DESC         |准备消息
     *
     * 参数区：
     *
     * @param string $code
     * @param string $message
     * @param string $file
     * @param string $line
     *
     * @return string
     */
    private function prepareMessage($code = '', $message = '', $file = '', $line = '')
    {
        // 拼接错误信息
        $err_str = date('Y-m-d h:i:s') . PHP_EOL;
        $err_str .= '级别：' . ($code ? $code : $this->code) . PHP_EOL;
        $err_str .= '信息：' . ($message ? $message : $this->message) . PHP_EOL;
        $err_str .= '文件：' . ($file ? $file : $this->file) . PHP_EOL;
        $err_str .= '行数：' . ($line ? $line : $this->line) . PHP_EOL;
        $err_str .= PHP_EOL;

        return $err_str;
    }

    /**
     * @DESC         |准备消息
     *
     * 参数区：
     *
     * @param string $code
     * @param string $message
     * @param string $file
     * @param string $line
     *
     * @return string
     */
    private function prepareHtmlMessage($code = '', $message = '', $file = '', $line = ''): string
    {
        $track_str_arr_str = $this->getTraceAsString();

        // 拼接错误信息
        $err_str = '<style>body{background-color:#151d1c}</style>';
        $err_str .= "<div style='padding:25px;'><h3 style='color: #ad2d2d'>" . date('Y-m-d h:i:s') . '</h3><br>';
        $err_str .= '<div style="color:#a0a2a5">级别：' . ($code ? $code : $this->code) . '<br>';
        $err_str .= '文件：' . ($file ? $file : $this->file) . '<br>';
        $err_str .= '行数：' . ($line ? $line : $this->line) . '<br>';
        $err_str .= '信息：' . ($message ? $message : $this->message) . '<br>';
        $err_str .= '位置：' . $this->getErrorCode() . '<br>';
        $err_str .= '追踪：<br><pre>' . $track_str_arr_str . '</pre><br>';
        $err_str .= '<br></div></div>';

        return $err_str;
    }

    /**
     * @DESC         |构造
     *
     * 参数区：
     *
     * @param string $ele
     * @param string $message
     * @param string $color
     *
     * @return string
     */
    private function preHtmlFrontColor(string $ele, string $message, string $color): string
    {
        return "<$ele style='color:$color'>" . $message . "</$ele>";
    }

    private function preCliFrontColor()
    {
        return chr(27) . '[34m' . $this->message . chr(27) . '[0m ';
    }

    public function __toString()
    {
        return $this->message = CLI ? $this->preCliFrontColor() : $this->preHtmlFrontColor('b', $this->message, '#945252');
    }

    /**
     * @DESC         |获取出错代码
     *
     * 参数区：
     *
     * @return string
     * @throws \Weline\Framework\App\Exception
     */
    public function getErrorCode(): string
    {
        $startColor = chr(27) . '[36m ';
        $endColor = chr(27) . '[0m ';
        $heightColor = chr(27) . '[34m';

        $isCli = (PHP_SAPI === 'cli');
        $file = new File();
        $fileSource = $file->open($this->file, $file::mode_r)->getSource();

        $returnTxt = $isCli ? $startColor : '<div style="padding:25px;color:#767678;background-color:#9e9e9e42;margin: 15px 8px 8px 8px">'; // 初始化返回
        $i = 1;                                                                                                                     // 行数
        $start_line = $this->line - 2;
        $end_line = $this->line + 2;
        while (!feof($fileSource)) {
            $buffer = fgets($fileSource);
            $buffer = $isCli ? $buffer : str_replace(' ', '&nbsp;', $buffer);
            $line = $isCli ? '第 ' . $i . ' 行# ' : '<b style="color: gray">第 ' . $i . ' 行#</b>';
            // 指定行范围读取
            if ($i > $start_line && $i < $end_line) {
                if ($isCli) {
                    if ($this->line === $i) {
                        $buffer = $endColor . $heightColor . $buffer . $endColor . $startColor;
                    }
                    $returnTxt .= $line . $buffer . PHP_EOL;
                } else {
                    if ($this->line === $i) {
                        $buffer = '<b style="font-weight: bolder;color:#a01b00">' . $buffer . '</b>';
                    }
                    $returnTxt .= $line . $buffer . '<br>';
                }
            }
            $i++;
        }
        $file->close();

        return $isCli ? $returnTxt . $endColor : $returnTxt . '</div>';
    }
}

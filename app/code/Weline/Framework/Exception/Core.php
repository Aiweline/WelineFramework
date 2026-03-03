<?php

declare(strict_types=1);

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

/**
 * 框架核心异常基类
 * 
 * 重构说明：
 * - 移除构造函数中的 init() 调用，避免每次抛异常都重新注册处理器
 * - 异常/错误处理器的初始化应在应用启动时通过 ExceptionBootstrap::init() 调用一次
 * - 保留日志记录功能
 */
class Core extends \Exception
{
    /**
     * 是否已初始化处理器
     */
    private static bool $initialized = false;

    /**
     * @var Env|null
     */
    private ?Env $etc = null;

    private ?Printing $_debug = null;

    private array $config = [];

    /**
     * Exception 初始函数（与 PHP \Exception 参数顺序一致：message, code, previous）
     *
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        
        // 自动记录异常到日志
        $this->logException();
    }

    /**
     * 记录异常到日志
     */
    private function logException(): void
    {
        try {
            if (function_exists('w_log_exception')) {
                w_log_exception($this);
            } elseif (class_exists(Env::class)) {
                $this->etc = Env::getInstance();
                $this->_debug = new Printing();
                $log_path = $this->etc->getLogPath($this->etc::log_path_EXCEPTION);
                $this->_debug->debug($this->prepareMessage(), $log_path);
            }
        } catch (\Throwable) {
            // 日志失败不影响异常传播
        }
    }

    /**
     * 初始化异常和错误处理器
     * 
     * @deprecated 请使用 ExceptionBootstrap::init() 代替
     * 此方法保留用于向后兼容，但不再推荐直接调用
     */
    public static function bootstrap(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        // 注册统一的错误处理器
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * 统一的错误处理器
     * 
     * @param int $errno 错误级别
     * @param string $errstr 错误消息
     * @param string $errfile 错误文件
     * @param int $errline 错误行号
     * @return bool
     */
    public static function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        // 如果错误被抑制（@ 操作符），不处理
        if (!(error_reporting() & $errno)) {
            return false;
        }

        // 确定日志级别
        $level = match ($errno) {
            E_ERROR, E_USER_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR => 'error',
            E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'warning',
            E_NOTICE, E_USER_NOTICE => 'notice',
            E_DEPRECATED, E_USER_DEPRECATED => 'debug',
            E_STRICT => 'info',
            default => 'warning',
        };

        // 记录日志
        $message = sprintf('%s in %s on line %d', $errstr, $errfile, $errline);
        
        if (function_exists('w_log')) {
            w_log($level, $message, [
                '_errno' => $errno,
                '_file' => $errfile,
                '_line' => $errline,
            ], 'php_error');
        }

        // 对于严重错误，输出或转换为异常
        $fatalErrors = [E_ERROR, E_USER_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR];
        if (in_array($errno, $fatalErrors, true)) {
            if (defined('DEV') && DEV) {
                self::outputError($errno, $errstr, $errfile, $errline);
            }
            // 可以选择抛出异常
            // throw new Type\ErrorException($errstr, $errno);
        }

        return true;
    }

    /**
     * 统一的异常处理器
     */
    public static function handleException(\Throwable $exception): void
    {
        // 记录日志
        if (function_exists('w_log_exception')) {
            w_log_exception($exception);
        }

        // 输出错误
        if (defined('DEV') && DEV) {
            self::outputException($exception);
        } else {
            $message = CLI 
                ? "程序异常：请联系管理员进行修复！日志：var/log/exception.log" . PHP_EOL
                : "程序异常：请联系管理员进行修复！日志：var/log/exception.log<br>";
            echo $message;
        }
    }

    /**
     * 脚本结束时的错误处理
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null) {
            return;
        }

        $fatalErrors = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if (!in_array($error['type'], $fatalErrors, true)) {
            return;
        }

        // 记录致命错误
        $message = sprintf(
            'Fatal error: %s in %s on line %d',
            $error['message'],
            $error['file'],
            $error['line']
        );

        if (function_exists('w_log_error')) {
            w_log_error($message, [
                '_error_type' => $error['type'],
                '_file' => $error['file'],
                '_line' => $error['line'],
            ], 'fatal_error');
        }

        // 输出错误
        if (defined('DEV') && DEV) {
            self::outputError($error['type'], $error['message'], $error['file'], $error['line']);
        } else {
            $msg = CLI 
                ? "程序错误：请联系管理员进行修复！日志：var/log/error.log" . PHP_EOL
                : "程序错误：请联系管理员进行修复！日志：var/log/error.log<br>";
            echo $msg;
        }
    }

    /**
     * 输出错误信息（开发模式）
     */
    private static function outputError(int $errno, string $errstr, string $errfile, int $errline): void
    {
        if (CLI) {
            echo sprintf(
                "\033[31m[ERROR %d]\033[0m %s\n  in %s on line %d\n",
                $errno,
                $errstr,
                $errfile,
                $errline
            );
        } else {
            echo sprintf(
                '<div style="padding:15px;background:#2d1515;color:#ff6b6b;border-left:4px solid #ff0000;margin:10px;">
                    <strong>[ERROR %d]</strong> %s<br>
                    <small>%s : %d</small>
                </div>',
                $errno,
                htmlspecialchars($errstr),
                htmlspecialchars($errfile),
                $errline
            );
        }
    }

    /**
     * 输出异常信息（开发模式）
     */
    private static function outputException(\Throwable $e): void
    {
        if (CLI) {
            echo sprintf(
                "\033[31m[%s]\033[0m %s\n  in %s on line %d\n\nStack trace:\n%s\n",
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            );
        } else {
            echo sprintf(
                '<style>body{background-color:#151d1c}</style>
                <div style="padding:25px;">
                    <h3 style="color:#ad2d2d">%s</h3>
                    <div style="color:#a0a2a5">
                        <p><strong>%s</strong> [Code: %d]</p>
                        <p>File: %s</p>
                        <p>Line: %d</p>
                        <pre style="background:#1a1a1a;padding:15px;overflow:auto;color:#888;">%s</pre>
                    </div>
                </div>',
                date('Y-m-d H:i:s'),
                htmlspecialchars($e->getMessage()),
                $e->getCode(),
                htmlspecialchars($e->getFile()),
                $e->getLine(),
                htmlspecialchars($e->getTraceAsString())
            );
        }
    }

    /**
     * 重置初始化状态（用于 WLS 状态管理）
     */
    public static function reset(): void
    {
        self::$initialized = false;
    }

    /**
     * 准备日志消息
     */
    private function prepareMessage(string $code = '', string $message = '', string $file = '', string $line = ''): string
    {
        $err_str = date('Y-m-d H:i:s') . PHP_EOL;
        $err_str .= '级别：' . ($code ?: $this->code) . PHP_EOL;
        $err_str .= '信息：' . ($message ?: $this->message) . PHP_EOL;
        $err_str .= '文件：' . ($file ?: $this->file) . PHP_EOL;
        $err_str .= '行数：' . ($line ?: $this->line) . PHP_EOL;
        $err_str .= '追踪：' . $this->getTraceAsString() . PHP_EOL;
        $err_str .= PHP_EOL;

        return $err_str;
    }

    /**
     * @deprecated 使用 bootstrap() 代替
     */
    public function init(): void
    {
        // 保留空方法用于向后兼容
        // 实际初始化应通过 bootstrap() 或 ExceptionBootstrap::init()
    }

    /**
     * @deprecated 处理器方法已移至静态方法
     */
    public function note(): void
    {
        // 保留空方法用于向后兼容
    }

    /**
     * @deprecated 处理器方法已移至静态方法
     */
    public function warning(): void
    {
        // 保留空方法用于向后兼容
    }

    /**
     * @deprecated 处理器方法已移至静态方法
     */
    public function exception(): void
    {
        // 保留空方法用于向后兼容
    }

    /**
     * @deprecated 处理器方法已移至静态方法
     */
    public function error(): void
    {
        // 保留空方法用于向后兼容
    }

    /**
     * @deprecated 处理器方法已移至静态方法
     */
    public function last_error(): void
    {
        // 保留空方法用于向后兼容
    }

    public function __toString(): string
    {
        if (CLI) {
            return chr(27) . '[34m' . $this->message . chr(27) . '[0m ';
        }
        return '<b style="color:#945252">' . htmlspecialchars($this->message) . '</b>';
    }

    /**
     * 获取出错代码上下文
     */
    public function getErrorCode(): string
    {
        try {
            $isCli = (PHP_SAPI === 'cli');
            $file = new File();
            $fileSource = $file->open($this->file, $file::mode_r)->getSource();

            $startColor = chr(27) . '[36m ';
            $endColor = chr(27) . '[0m ';
            $heightColor = chr(27) . '[34m';

            $returnTxt = $isCli ? $startColor : '<div style="padding:25px;color:#767678;background-color:#9e9e9e42;margin: 15px 8px 8px 8px">';
            $i = 1;
            $start_line = $this->line - 2;
            $end_line = $this->line + 2;
            
            while (!feof($fileSource)) {
                $buffer = fgets($fileSource);
                $buffer = $isCli ? $buffer : str_replace(' ', '&nbsp;', $buffer);
                $line = $isCli ? '第 ' . $i . ' 行# ' : '<b style="color: gray">第 ' . $i . ' 行#</b>';
                
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
        } catch (\Throwable) {
            return '';
        }
    }
}

<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Output\Debug;

use Weline\Framework\App\Env;
use Weline\Framework\System\File\Io\File;

abstract class AbstractPrint extends \Weline\Framework\Output\AbstractPrint implements PrintInterface
{
    public $out;
    private ?File $file = null;
    public bool $printing = true;

    /**
     * @DESC         |错误
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param array|string $data
     * @param string $message
     * @param string $color
     * @param int $pad_length
     *
     * @return mixed|void
     */
    public function error($data = 'Error!', string $message = '', string $color = self::ERROR, int $pad_length = 25): mixed
    {
        return $this->doPrint($data, $message, $color, $pad_length, 3, 'error');
    }

    /**
     * @DESC         |成功
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $data
     * @param string $message
     * @param string $color
     * @param int $pad_length
     *
     * @return mixed|void
     */
    public function success(string $data = 'Success!', string $message = '', string $color = self::SUCCESS, int $pad_length = 25): mixed
    {
        return $this->doPrint($data, $message, $color, $pad_length, 3, 'success');
    }

    /**
     * @DESC         |警告
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $data
     * @param string $message
     * @param string $color
     * @param int $pad_length
     *
     * @return mixed|void
     */
    public function warning(string $data = 'Warning!', string $message = '', string $color = self::WARNING, int $pad_length = 25): mixed
    {
        return $this->doPrint($data, $message, $color, $pad_length, 3, 'warning');
    }

    /**
     * @DESC         |提示
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $data
     * @param string $message
     * @param string $color
     * @param int $pad_length
     *
     * @return mixed|void
     */
    public function note(string $data = 'Note!', string $message = '', string $color = self::NOTE, int $pad_length = 25): mixed
    {
        return $this->doPrint($data, $message, $color, $pad_length, 3, 'note');
    }

    /**
     * ----------------辅助方法-------------------
     *
     * @param mixed $data
     * @param mixed $message
     * @param mixed $color
     * @param mixed $pad_length
     */

    /**
     * @DESC         |方法描述
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param array|string $data
     * @param string $message
     * @param string $color
     * @param int $pad_length
     */
    public function doPrint(array|string $data, string $message, string $color, int $pad_length = 0, int $message_type = 0, string $filename = 'dev'): mixed
    {
        $message = empty($message) ? __('系统') : $message;
        if ($message_type == 3) {
            $log_file = BP . DS . 'var' . DS . 'log' . DS . $filename . '.log';
            if (!is_dir(BP . DS . 'var' . DS . 'log')) {
                mkdir(BP . DS . 'var' . DS . 'log', 0777, true);
            }
            if (!is_file($log_file)) {
                touch($log_file);
            }
            ini_set('error_log', $log_file);
            if (is_string($data)) {
                error_log('[' . $message . ']' . '|' . $data, 0, empty($file) ? $log_file : $file);
            } else {
                error_log('[' . $message . ']' . '|' . var_export($data, true), 0, empty($file) ? $log_file : $file);
            }
        }
        if ($this->printing) {
            if (is_array($data)) {
                foreach ($data as $msg) {
                    $this->printing($msg, $message, $color, $pad_length);
                }
            } else {
                $this->printing($data, $message, $color, $pad_length);
            }
        }
        return '';
    }

    /**
     * @DESC         |打印消息
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $data
     * @param string $message
     * @param string $color
     * @param int $pad_length
     */
    public function printing(string $data = 'Printing!', string $message = '', string $color = self::NOTE, int $pad_length = 0): void
    {
        $doc_tmp = $this->colorize('【' . $message . '】：', self::NOTE) . $this->colorize(($pad_length ? str_pad($data, $pad_length) : $data), $color);
        $doc = <<<COMMAND_LIST

$doc_tmp

COMMAND_LIST;
        echo $doc;
    }

    /**
     * @DESC         |终端输出颜色字体
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $text
     * @param string $status
     *
     * @return string
     */
    public function colorize($text, $status): string
    {
        $end = '[0m';
        if (!CLI) {
            $end = '</span>';
            $text = str_replace(PHP_EOL, '<br>', $text);
        }
        switch ($status) {
            case self::SUCCESS:
            case 'Green':
                $this->out = '[32m'; //Green
                if (!CLI) {
                    $this->out = '<span style="color: #00ff00;">';
                }
                break;
            case self::ERROR:
            case self::FAILURE:
            case 'Red':
                $this->out = '[31m'; //Red
                if (!CLI) {
                    $this->out = '<span style="color: #ff0000;">';
                }
                break;
            case self::WARNING:
            case 'Yellow':
                $this->out = '[33m'; //Yellow
                if (!CLI) {
                    $this->out = '<span style="color: #ffff00;">';
                }
                break;
            case self::NOTE:
            case 'Blue':
                $this->out = '[34m'; //Blue
                if (!CLI) {
                    $this->out = '<span style="color: #0000ff;">';
                }
                break;
            default:
                $this->out = '[31m'; //默认错误信息
                if (!CLI) {
                    $this->out = '<span style="color: #ff0000;">';
                }
                break;
        }

        return chr(27) . "{$this->out}" . "{$text}" . chr(27) . $end;
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @param string $log_path
     * @param string $content
     * @param int $type
     *
     * @throws \Weline\Framework\App\Exception
     */
    protected function write(string $log_path, string $content, int $type)
    {
        if (!isset($this->file)) {
            $this->file = new File();
        }
        $this->file->open($log_path);
        $this->file->write("【{$type}】" . $content . PHP_EOL);
        $this->file->close();
    }
}

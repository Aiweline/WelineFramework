<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Output\Cli;

abstract class AbstractPrint implements PrintInterface
{
    public $out;

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
     * @param string       $message
     * @param string       $color
     * @param int          $pad_length
     *
     * @return mixed|void
     */
    public function error($data = 'CLI Error!', string $message = '', string $color = self::ERROR, int $pad_length = 25)
    {
        $this->doPrint($data, $message, self::ERROR);
    }

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
     * @param string       $message
     * @param string       $color
     * @param int          $pad_length
     *
     * @return mixed|void
     */
    public function setup($data = 'CLI Red!', string $message = '', string $color = self::ERROR, int $pad_length = 25)
    {
        $this->doPrint($data, $message, self::ERROR);
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
     * @param int    $pad_length
     *
     * @return mixed|void
     */
    public function success(string $data = 'CLI Success!', string $message = '', string $color = self::ERROR, int $pad_length = 25)
    {
        $this->doPrint($data, $message, self::SUCCESS);
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
     * @param int    $pad_length
     *
     * @return mixed|void
     */
    public function warning(string $data = 'CLI Warning!', string $message = '', string $color = self::WARNING, int $pad_length = 25)
    {
        $this->doPrint($data, $message, self::WARNING);
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
     * @param int    $pad_length
     *
     * @return mixed|void
     */
    public function note(string $data = 'CLI Note!', string $message = '', string $color = self::NOTE, int $pad_length = 25)
    {
        $this->doPrint($data, $message, self::NOTE);
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
     * @param string|array $data
     * @param string       $message
     * @param string       $color
     * @param int          $pad_length
     */
    private function doPrint($data, $message, $color, $pad_length = 0)
    {
        $message = $message ? $this->colorize($message, $color) : '';
        if (is_array($data)) {
            foreach ($data as $msg) {
                $this->printing($msg, $message, $color, $pad_length);
            }
        }
        $this->printing($data, $message, $color, $pad_length);
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
     * @param int    $pad_length
     */
    public function printing(string $data = 'CLI Printing!', string $message = '', string $color = self::NOTE, int $pad_length = 0)
    {
        $doc_tmp = ($message ? '【' . $message . '】：' : '') . $this->colorize(($pad_length ? str_pad($data, $pad_length) : $data), $color);
        $enter   = PHP_EOL;
        $doc     = <<<COMMAND_LIST
{$doc_tmp}{$enter}
COMMAND_LIST;
        echo $doc;
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
     * @param array  $data
     * @param string $flag
     * @param int    $pad_length
     */
    public function printList(array $data, $flag = '#', $pad_length = 45)
    {
        $doc_tmp = '';
        foreach ($data as $key => $datum) {
            if (is_int(strpos($key, $flag))) {
                $key = explode($flag, $key);
                $key = str_pad($key[0], $pad_length / 1.5) . 'module # ' . (str_replace('\\', '_', $key[1]));
            }
            $doc_tmp .= $this->colorize($key, self::WARNING) . PHP_EOL;
            if (is_string($datum)) {
                $doc_tmp .= $this->colorize($datum, self::NOTE) . PHP_EOL;
            }
            if (is_array($datum)) {
                foreach ($datum as $datum_key => $datum_value) {
                    if (!is_string($datum_value)) {
                        if (isset($datum_value['tip'])) {
                            $datum_value = $datum_value['tip'];
                        } else {
                            if (is_object($datum_value)) {
                                $datum_value = json_encode($datum_value);
                            }
                            if (is_array($datum_value)) {
                                $datum_value = json_encode($datum_value);
                            }
                        }
                    }
                    $doc_tmp .= '-' . str_pad($this->colorize($datum_key, self::SUCCESS), $pad_length) . $this->colorize($flag . ' ' . $datum_value, self::NOTE) . PHP_EOL;
                }
            }
        }
        $this->printing($doc_tmp);
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
    public function colorize($text, $status = 'Blue'): string
    {
        switch ($status) {
            case self::SUCCESS:
            case 'Green':
                $this->out = '[32m'; //Green

                break;
            case self::ERROR:
            case self::FAILURE:
            case 'Red':
                $this->out = '[31m'; //Red

                break;
            case self::WARNING:
            case 'Yellow':
                $this->out = '[33m'; //Yellow

                break;
            case self::NOTE:
            case 'Blue':
                $this->out = '[34m'; //Blue

                break;
            default:
                $this->out = '[31m'; //默认错误信息

                break;
        }

        return chr(27) . "{$this->out}" . "{$text}" . chr(27) . '[0m';
    }
}

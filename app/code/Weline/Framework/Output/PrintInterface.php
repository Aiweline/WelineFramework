<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Output;

interface PrintInterface
{
    public const WARNING = 'WARNING';

    public const SUCCESS = 'SUCCESS';

    public const NOTE = 'NOTE';

    public const FAILURE = 'FAILURE';

    public const ERROR = 'ERROR';

    /**
     * @DESC         |错误打印
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param mixed  $data
     * @param string $message
     * @param string $color
     * @param int    $pad_length
     *
     * @return mixed
     */
    public function error(string $data = 'Error!', string $message = '', string $color = self::ERROR, int $pad_length = 25);

    /**
     * @DESC         |成功打印
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
     * @return mixed
     */
    public function success(string $data = 'Success!', string $message = '', string $color = self::ERROR, int $pad_length = 25);

    /**
     * @DESC         |警告打印
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
     * @return mixed
     */
    public function warning(string $data = 'Warning!', string $message = '', string $color = self::ERROR, int $pad_length = 25);

    /**
     * @DESC         |提示打印
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
     * @return mixed
     */
    public function note(string $data = 'Note!', string $message = '', string $color = self::ERROR, int $pad_length = 25);

    /**
     * @DESC         |错误打印
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $message
     *
     * @return mixed
     */
    public function printing(string $message = 'Printing!');
}

<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console;

/**
 * @Author       秋枫雁飞
 * @Email        aiweline@qq.com
 * @Forum        https://bbs.aiweline.com
 * @DESC         此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 *
 * Interface CommandInterface
 * @package      Weline\Framework\Console
 */
interface CommandInterface
{
    public const version = '1.0.0';

    public const dir = 'Console';

    /**
     * @DESC         |命令行类接口运行方法
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param array $args
     * @param array $data
     *
     */
    public function execute(array $args = [], array $data = []);

    /**
     * @DESC         |命令注释
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @return string
     */
    public function tip(): string;
}

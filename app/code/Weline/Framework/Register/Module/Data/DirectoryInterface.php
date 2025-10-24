<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Register\Module\Data;

/**
 * @Author       秋枫雁飞
 * @Email        aiweline@qq.com
 * @Forum        https://bbs.aiweline.com
 * @DESC         此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 *
 * Interface DirectoryInterface
 * 存放Module目录数据接口
 * @package      Weline\Framework\Module\Data
 */
interface DirectoryInterface
{
    /**
     * 配置目录
     */
    public const etc = 'etc';

    /**
     * Api目录
     */
    public const api = 'Api';

    /**
     * 控制器目录
     */
    public const controller = 'Controller';

    /**
     * 帮助目录
     */
    public const helper = 'Helper';

    /**
     * 视图模板目录
     */
    public const view = 'view';
}

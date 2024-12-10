<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Register;

interface RegisterInterface
{
    public const NAMESPACE = 'Weline\\Framework\\Register\\';

    public const MODULE = 'module';

    public const I18N = 'i18n';

    public const ROUTER = 'router';

    public const THEME = 'theme';

    public const register_file = 'register.php';

    /**
     * @DESC         |注册模块
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string       $type
     * @param string       $module_name
     * @param array|string $param
     * @param string       $version
     * @param string       $description
     */
    public function register(string $type, string $module_name, array|string $param, string $version = '', string $description = ''): mixed;
}

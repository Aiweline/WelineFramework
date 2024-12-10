<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Controller\Data;

interface DataInterface
{
    public const dir = 'Controller';

    public const type_pc_FRONTEND = 'FrontendController';

    public const type_pc_BACKEND = 'BackendController';

    public const type_api_REST_FRONTEND = 'FrontendRestController';

    public const type_api_BACKEND = 'BackendRestController';
}

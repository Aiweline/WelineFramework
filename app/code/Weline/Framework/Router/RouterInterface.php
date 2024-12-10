<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router;

use Weline\Framework\DataObject\DataObject;

interface RouterInterface
{
//    public function setModule(string $module);
//
//    public function getModule(): string;
//
//    public function setController(\Weline\Framework\Controller\Core $controller): static;
//
//    public function getController(): \Weline\Framework\Controller\Core;
//
//    public function setControllerMethod(string $method): static;
//
//    public function getControllerMethod(): string;
//
//    public function loadCache(string $url): bool|string;
//
//    public function setPattern(string $pattern);
//
//    public function getPattern(): string;

    /**
     * @DESC          # 处理路由规则 只需要修改对应数据$path，将其规则数据回写到$rule变量即可
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/4/13 23:13
     * 参数区：
     *
     * @param string $path
     * @param array  $rule
     */
    public static function process(string &$path, array &$rule);
}

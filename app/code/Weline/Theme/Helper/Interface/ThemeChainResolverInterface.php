<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper\Interface;

use Weline\Theme\Model\WelineTheme;

/**
 * 主题继承链解析器接口
 * 
 * 职责：解析主题继承链
 * 遵循：单一职责原则 (SRP)
 */
interface ThemeChainResolverInterface
{
    /**
     * 获取主题继承链（从基础到当前：父主题在前，激活主题在后）
     * 
     * @param WelineTheme $theme 当前主题
     * @return WelineTheme[] 主题继承链数组
     */
    public function getThemeChain(WelineTheme $theme): array;
}

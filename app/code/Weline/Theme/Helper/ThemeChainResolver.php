<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\Interface\ThemeChainResolverInterface;
use Weline\Theme\Model\WelineTheme;

/**
 * 主题继承链解析器
 * 
 * 职责：解析主题继承链
 * 遵循：单一职责原则 (SRP)
 */
class ThemeChainResolver implements ThemeChainResolverInterface
{
    /**
     * 获取主题继承链（从基础到当前：父主题在前，激活主题在后）
     * 
     * @param WelineTheme $theme 当前主题
     * @return WelineTheme[] 主题继承链数组
     */
    public function getThemeChain(WelineTheme $theme): array
    {
        $chain = [];
        $visited = [];
        $currentTheme = $theme;

        // 递归收集父主题
        while ($currentTheme && $currentTheme->getId()) {
            $themeId = $currentTheme->getId();
            
            // 防止循环引用
            if (in_array($themeId, $visited)) {
                break;
            }
            $visited[] = $themeId;

            // 将父主题添加到链的前面（保证顺序：基础 → 父 → 子）
            array_unshift($chain, $currentTheme);

            // 获取父主题
            $parentId = $currentTheme->getParentId();
            if ($parentId) {
                try {
                    /** @var WelineTheme $parentTheme */
                    $parentTheme = ObjectManager::getInstance(WelineTheme::class);
                    $parentTheme->load($parentId);
                    if ($parentTheme->getId()) {
                        $currentTheme = $parentTheme;
                    } else {
                        break;
                    }
                } catch (\Exception $e) {
                    break;
                }
            } else {
                break;
            }
        }

        return $chain;
    }
}

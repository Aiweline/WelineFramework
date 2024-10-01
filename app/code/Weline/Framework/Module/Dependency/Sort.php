<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\Dependency;

use Weline\Framework\App\Env;

class Sort
{
    function sortModules(&$modules, $entity_id = 'id', $parent_key = 'parents')
    {
        $sortedModules  = array();
        $visitedModules = array();

        foreach ($modules as $module_data) {
            $this->sortModuleDFS($module_data[$entity_id], $module_data[$parent_key] ?? [], $modules, $sortedModules, $visitedModules, $entity_id, $parent_key);
        }

        return $sortedModules;
    }

    function sortModuleDFS($module, $parents, &$modules, &$sortedModules, &$visitedModules, &$entity_id, &$parent_key)
    {
        if (isset($visitedModules[$module])) {
            return;
        }
        $visitedModules[$module] = true;
        foreach ($parents as $dependency) {
            if (isset($modules[$dependency])) {
                $this->sortModuleDFS($dependency, $modules[$dependency][$parent_key], $modules, $sortedModules,
                                     $visitedModules, $entity_id, $parent_key);
            }
        }

        $sortedModules[$module] = $modules[$module];
    }

    /**
     * @DESC          # 依赖排序
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/4/22 20:10
     * 参数区：
     *
     * @param array  $dependencies
     * @param string $entity_id
     * @param string $parent_key
     *
     * @return array
     */
    public function dependenciesSort(array &$modules, string $entity_id = 'id', string $parent_key = 'dependencies'): array
    {
        $dependencies = $this->sortModules($modules, $entity_id, $parent_key);
        $this->saveDenpendenciesSort($dependencies);
        return $dependencies;
    }

    public function saveDenpendenciesSort(array $dependencies): bool
    {
        return Env::getInstance()->saveDependencies($dependencies);
    }
}

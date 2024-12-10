<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

class Scanner
{
    public const dir = 'Cache';

    public function getCaches(): array
    {
        $modules = Env::getInstance()->getActiveModules();
        $caches=[];
        foreach ($modules as $module) {
            if($module['name']=='Weline_Framework'){
                $caches['framework'][$module['name']] = $this->convertParser(glob($module['base_path'].'*'.DS.self::dir.DS.'*.php'),$module);
            }else{
                $caches['app'][$module['name']] =  $this->convertParser(glob($module['base_path'].self::dir.DS.'*.php'),$module);
            }
        }
        return $caches;
    }


    /**
     * @DESC          # 缓存文件转为缓存管理器
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/6/6 21:35
     * 参数区：
     *
     * @param array $cache_files
     * @param array $module
     *
     * @return array
     */
    protected function convertParser(array $cache_files,array $module): array
    {
        $caches = [];
        foreach ($cache_files as $cache_file) {
            # 如果有Interface名，则跳过
            if (strpos($cache_file, 'Interface')) {
                continue;
            }
            $moduleReplateCachePath = str_replace($module['base_path'], '\\', rtrim($cache_file,'.php'));
            $cache_class = $module['namespace_path'].str_replace(DS, '\\', $moduleReplateCachePath);
            if (class_exists($cache_class)) {
                try {
                    $obj_class = $cache_class;
                    if(!str_ends_with($obj_class, 'Factory')){
                        $obj_class .= 'Factory';
                    }
                    $obj = ObjectManager::getInstance($obj_class);
                } catch (\Exception $e) {
                    $obj = null;
                }

                if ($obj instanceof CacheInterface) {
                    $caches[] = [
                        'class' => $cache_class,
                        'file'  => $cache_file,
                    ];
                }
            }
        }
        return $caches;
    }
}

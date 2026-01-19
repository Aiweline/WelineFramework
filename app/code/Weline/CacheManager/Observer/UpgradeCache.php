<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CacheManager\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheFactory;
use Weline\Framework\Event\Event;
use Weline\Framework\Cache\Scanner;
use Weline\Framework\Manager\ObjectManager;

class UpgradeCache implements \Weline\Framework\Event\ObserverInterface
{
    private Scanner $scanner;
    private array $data;

    public function __construct(
        Scanner $scanner
    )
    {
        $this->scanner = $scanner;
        $this->data = $this->scanner->getCaches();
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        # 更新缓存到数据库
        $model = $this->getModel();
        # modules
        $modules = Env::getInstance()->getModuleList();
        # 更新系统缓存
        $framework_cache = $this->data['framework'];
        foreach ($framework_cache as $module => $caches) {
            foreach ($caches as $cache) {
                $cache['type'] = 0;
                $this->processCache($model, (array)$cache, $modules, $module);
            }
        }
        # 更新APP缓存
        $app_cache = $this->data['app'];
        foreach ($app_cache as $module => $caches) {
            foreach ($caches as $cache) {
                $cache['type'] = 1;
                $this->processCache($model, (array)$cache, $modules, $module);
            }
        }
    }

    /**
     * @DESC          # 处理缓存存储
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/6/19 23:24
     * 参数区：
     *
     * @param \Weline\CacheManager\Model\Cache $model
     * @param array $cache
     * @param array $modules
     * @param                                  $default_module_name
     *
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    public function processCache(\Weline\CacheManager\Model\Cache $model, array $cache, array $modules, $default_module_name = '')
    {
        // 尝试使用 Factory 版本（如果存在）
        $cacheClass = $cache['class'];
        if (!str_ends_with($cacheClass, 'Factory')) {
            $factoryClass = $cacheClass . 'Factory';
            if (class_exists($factoryClass)) {
                $cacheClass = $factoryClass;
            }
        }
        
        /**@var CacheFactory $cacheObj */
        $cacheObj = ObjectManager::makeWithoutFactory($cacheClass);
        
        // 检查是否有 getIdentity 方法，如果没有则使用类名作为标识
        $identity = method_exists($cacheObj, 'getIdentity') 
            ? $cacheObj->getIdentity() 
            : str_replace('\\', '_', $cache['class']);
        
        # 查找是否存在缓存记录
        $model = $model->clearData()->where($model::fields_IDENTITY, $identity)->find()->fetch();
        # 查找缓存文件所在module
        $module_name = $default_module_name;
        foreach ($modules as $module) {
            if (str_starts_with($cache['file'], $module['base_path'])) {
                $module_name = $module['name'];
                break;
            }
        }
        // 获取缓存对象的属性（兼容 Factory 和非 Factory 类）
        $status = method_exists($cacheObj, 'getStatus') ? $cacheObj->getStatus() : false;
        $isKeep = method_exists($cacheObj, 'isKeep') ? $cacheObj->isKeep() : false;
        $tip = method_exists($cacheObj, 'tip') ? $cacheObj->tip() : '';
        
        $model
            ->setData($model::fields_NAME, $cache['class'])
            ->setData($model::fields_IDENTITY, $identity)
            ->setData($model::fields_Module, $module_name)
            ->setData($model::fields_FILE, str_replace(BP, '', $cache['file']))
            ->setData($model::fields_TYPE, $cache['type'])
            ->setData($model::fields_Status, $status ? 1 : 0)
            ->setData($model::fields_Permanently, $isKeep ? 1 : 0)
            ->setData($model::fields_DESCRIPTION, $tip)
            ->save(true);
    }

    public function getModel(): \Weline\CacheManager\Model\Cache
    {
        return ObjectManager::getInstance('Weline\CacheManager\Model\Cache');
    }
}

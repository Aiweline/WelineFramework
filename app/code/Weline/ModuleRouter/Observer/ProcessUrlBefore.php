<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\ModuleRouter\Observer;

use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\Core;
use Weline\Framework\Router\RouterInterface;
use Weline\ModuleRouter\Cache\ModuleRouterCache;
use Weline\ModuleRouter\Config\ModuleRouterReader;

class ProcessUrlBefore implements \Weline\Framework\Event\ObserverInterface
{
    private CacheInterface $moduleRouterCache;

    public function __construct(
        ModuleRouterCache $moduleRouterCache,
        private Request   $request
    )
    {
        $this->moduleRouterCache = $moduleRouterCache->create();
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event)
    {
        /**@var DataObject $data */
        $data = $event->getData('data');
        $path = $data->getData('path');
        $rule = $data->getData('rule');
        $type = $data->getData('type');
        /**@var ModuleRouterReader $moduleRoutersReader */
        $moduleRoutersReader = ObjectManager::getInstance(ModuleRouterReader::class);
        $moduleRouters = $moduleRoutersReader->read();
        foreach ($moduleRouters as $module => $moduleRouter) {
            /**@var RouterInterface $moduleRouter */
            $moduleRouter = ObjectManager::getInstance($moduleRouter['class']);
            $moduleRouter::process($path, $rule);
        }
        $data->setData('path', $path);
        $data->setData('rule', $rule);
    }
}

<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Console\Cache;

use Weline\Framework\Cache\CacheFactory;
use Weline\Framework\Cache\CacheFactoryInterface;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Cache\Scanner;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Clear implements \Weline\Framework\Console\CommandInterface
{
    /**
     * @var Scanner
     */
    private Scanner $scanner;

    /**
     * @var Printing
     */
    private Printing $printing;

    public function __construct(
        Scanner  $scanner,
        Printing $printing
    )
    {
        $this->scanner  = $scanner;
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $is_force = in_array('-f', $args);
        $caches   = $this->scanner->getCaches();
        foreach ($caches as $form => $modules_caches) {
            switch ($form) {
                case 'app':
                    $this->printing->note(__('模块缓存清理中...'));
                    foreach ($modules_caches as $module => $module_caches) {
                        foreach ($module_caches as $app_cache) {
                            $this->printing->printing(__($app_cache['class'] . '...'));
                            /**@var CacheFactory $cacheObjectManager */
                            $cacheObjectManager = ObjectManager::getInstance($this->reductionFactoryClass($app_cache['class']));
                            if ($cacheObjectManager instanceof CacheFactoryInterface) {
                                if ($is_force || !$cacheObjectManager->isKeep()) {
                                    $cacheObjectManager->create()->clear();
                                }
                            } else {
                                /**@var CacheInterface $cacheObjectManager */
                                $cacheObjectManager->clear();
                            }
                        }
                    }

                    break;
                case 'framework':
                    $this->printing->note(__('框架缓存清理中...'));
                    foreach ($modules_caches as $module => $module_caches) {
                        foreach ($module_caches as $framework_cache) {
                            $this->printing->printing(__($framework_cache['class'] . '...'));
                            /**@var CacheFactory $cacheObjectManager */
                            $cacheObjectManager = ObjectManager::getInstance($this->reductionFactoryClass($framework_cache['class']));
                            if ($cacheObjectManager instanceof CacheFactoryInterface) {
                                if ($is_force || !$cacheObjectManager->isKeep()) {
                                    $cacheObjectManager->create()->clear();
                                }
                            } else {
                                /**@var CacheInterface $cacheObjectManager */
                                $cacheObjectManager->clear();
                            }
                        }
                    }

                    break;
                default:
                    $this->printing->error(__('没有任何类型的缓存需要清理！'));
            }
        }
        $this->printing->success(__('缓存已清理！'));
    }

    /**
     * @DESC          # 还原工厂类
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/4/14 22:54
     * 参数区：
     *
     * @param string $class
     *
     * @return string
     */
    public function reductionFactoryClass(string $class): string
    {
        if (!class_exists($class) && str_ends_with($class, 'Factory')) {
            if (str_ends_with($class, 'Factory')) {
                $class = rtrim($class, 'Factory');
            }
        }
        return $class;
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '缓存清理。';
    }
}

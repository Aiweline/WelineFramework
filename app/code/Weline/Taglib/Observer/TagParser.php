<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/1 09:53:03
 */

namespace Weline\Taglib\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\Taglib\Cache\TaglibCacheFactory;

class TagParser implements \Weline\Framework\Event\ObserverInterface
{
    /**
     * @var CacheInterface
     */
    private CacheInterface $cache;

    public function __construct()
    {
        $this->cache = ObjectManager::getInstance(TaglibCacheFactory::class);
    }

    /**
     * @inheritDoc
     */
    public function execute(Event $event)
    {
        $frameworkTags = $event->getData('data')->getData('tags');
        # 查找所有标签
        $cache_key = 'Weline_Taglib_module_tags';
        $modules_tags = $this->cache->get($cache_key);
        if (empty($modules_tags)) {
            $modules_tags = [];
            $modules = Env::getInstance()->getActiveModules();
            foreach ($modules as $module) {
                $tags = glob($module['base_path'] . 'Taglib' . DS . '*.php');
                foreach ($tags as $tag) {
                    $tagF = rtrim($tag, '.php');
                    $tagClass = str_replace(DS, '\\', str_replace($module['base_path'], $module['namespace_path'] . '\\', $tagF));
                    $modules_tags[$module['name']][] = $tagClass;
                }
            }
            $this->cache->set($cache_key, $modules_tags);
        }
        $module_tags = [];
        foreach ($modules_tags as $module_name => $module_tag) {
            foreach ($module_tag as $item) {
                /**@var \Weline\Taglib\TaglibInterface $tagObject */
                $tagObject = ObjectManager::getInstance($item);
                if (!($tagObject instanceof \Weline\Taglib\TaglibInterface)) {
                    throw new \Exception(__('标签类{ %1 }必须继承自：\Weline\Taglib\TaglibInterface 接口, 标签文件：%2', [$tagObject::class, $tag]));
                }
                $tag_data = [];
                if ($tagObject::tag()) {
                    $tag_data['tag'] = $tagObject::tag();
                }
                if ($tagObject::attr()) {
                    $tag_data['attr'] = $tagObject::attr();
                }
                if ($tagObject::tag_start()) {
                    $tag_data['tag-start'] = $tagObject::tag_start();
                }
                if ($tagObject::tag_end()) {
                    $tag_data['tag-end'] = $tagObject::tag_end();
                }
                if ($tagObject::callback()) {
                    $tag_data['callback'] = $tagObject::callback();
                }
                if ($tagObject::tag_self_close()) {
                    $tag_data['tag-self-close'] = $tagObject::tag_self_close();
                }
                if ($tagObject::tag_self_close_with_attrs()) {
                    $tag_data['tag-self-close-with-attrs'] = $tagObject::tag_self_close_with_attrs();
                }
                if ($tag_data) {
                    $tag_data['is_custom'] = true;
                    $tag_data['module_name'] = $module_name;
                    $tag_data['doc'] = $tagObject::document();
                    $module_tags[$tagObject::name()] = $tag_data;
                }
            }
        }
        if ($module_tags) {
            $event->getData('data')->setData('tags', array_merge($frameworkTags, $module_tags));
        }
    }
}

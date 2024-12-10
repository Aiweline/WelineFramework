<?php

namespace Weline\FileManager\Taglib;

use Weline\Backend\Model\BackendUserConfig;
use Weline\FileManager\Cache\FileManagerCacheFactory;
use Weline\FileManager\FileManagerInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;
use Weline\Taglib\TaglibInterface;

class FileManager implements TaglibInterface
{
    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return 'file-manager';
    }

    /**
     * @inheritDoc
     */
    public static function tag(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function attr(): array
    {
        return [
            'code' => false,
            'title' => true,
            'target' => true,
            'path' => true,
            'setAttr' => false,
            'value' => true,
            'vars' => false,
            'ext' => true,
            'multi' => false,
            'w' => false,
            'h' => false,
            'size' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public static function tag_start(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function tag_end(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            if (!empty($attributes['code'])) {
                $userConfigFileManager = $attributes['code'];
            } else {
                # 检查是否有配置默认的文件管理器
                $userConfigFileManager = ObjectManager::getInstance(BackendUserConfig::class)->getConfig('file_manager') ?: 'local';
            }
            $cacheKey = json_encode(func_get_args()) . $userConfigFileManager;
            /**@var CacheInterface $cache */
            $cache  = ObjectManager::getInstance(FileManagerCacheFactory::class);
            $result = $cache->get($cacheKey);
            if ($result and ($userConfigFileManager !== 'local')) {
                return $result;
            }
            /**@var Scan $fileScan $ */
            $fileScan     = ObjectManager::getInstance(Scan::class);
            $fileManagers = [];
            $modules      = Env::getInstance()->getActiveModules();
            foreach ($modules as $module) {
                $files = [];
                $fileScan->globFile(
                    $module['base_path'] . 'FileManager',
                    $files,
                    '.php',
                    $module['base_path'],
                    $module['namespace_path'] . '\\',
                    '.php',
                    true
                );
                foreach ($files as $file) {
                    $class = ObjectManager::getInstance($file);
                    if ($class instanceof FileManagerInterface) {
                        $fileManagers[$class::name()] = $class;
                    }
                }
            }
            if (count($fileManagers) > 1 and $userConfigFileManager === 'local') {
                /**@var \Weline\FileManager\FileManager $fileManager */
                $fileManager = array_pop($fileManagers);
                $userConfigFileManager = $fileManager::name();
            } else {
                if (!isset($fileManagers[$userConfigFileManager])) {
                    ObjectManager::getInstance(MessageManager::class)->addWarning(__('所指定的文件管理器不存在! 文件管理器名：%1', $userConfigFileManager));
                    # 使用第一个文件管理器作为默认的文件管理器
                    /**@var \Weline\FileManager\FileManager $fileManager */
                    $fileManager = array_pop($fileManagers);
                    ObjectManager::getInstance(MessageManager::class)->addWarning(__('使用：%1 文件管理器代替。', $fileManager::name()));
                } else {
                    /**@var \Weline\FileManager\FileManager $fileManager */
                    $fileManager = $fileManagers[$userConfigFileManager];
                }
            }
            if (!isset($attributes['target'])) {
                throw new \Exception(__('缺少目标ID。文档：%1', self::document()));
            }
            if (str_starts_with($attributes['target'], '.')) {
                throw new \Exception(__('缺少目标ID。请使用ID选择器，例如：target="#id"。文档：%1', self::document()));
            }
            $fileManager
                ->setTarget(trim($attributes['target'], '#'))
                ->setPath($attributes['path'] ?? '')
                ->setValue($attributes['value'] ?? '')
                ->setTitle($attributes['title'] ?? '')
                ->setMulti($attributes['multi'] ?? '')
                ->setWidth($attributes['w'] ?? 50)
                ->setHeight($attributes['h'] ?? 50)
                ->setExt($attributes['ext'] ?? '*')
                ->setSize($attributes['size'] ?? '102400')
                ->setVars($attributes['vars'] ?? '');
            $result = $fileManager->setData(
                [
                    'tag_key' => $tag_key,
                    'tag_data' => $tag_data,
                    'attributes' => $attributes,
                    'code' => $userConfigFileManager
                ]
            )->render();
            $cache->set($cacheKey, $result);
            return $result;
        };
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    public static function document(): string
    {
        $doc = htmlentities(
            "<file-manager 
                        code='local'
                        target='#demo'
                        title='文件管理器' 
                        var='store' 
                        path='store/logo' 
                        value='store.logo'
                        multi='0'
                        ext='jpg,png,gif,webp'
                        w='50'
                        h='50'                        
                        />"
        );
        return <<<HTML
使用方法：
{$doc}
参数解释：
code：可选,指定安装的编辑器代码。例如：local
target：目标容器id【选择文件后会根据id回填到属性value上】
ext：必选。默认jpg,png,gif,webp格式
title：可选。文件管理器标题
path：可选。默认打开的文件路径
vars：当前变量
value：默认当前的文件路径
multi：可选。默认单选
w：可选。默认预览宽50px
h：可选。默认预览高50px
HTML;
    }
}

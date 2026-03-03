<?php

namespace Weline\FileManager\Taglib;

use Weline\Backend\Model\BackendUserConfig;
use Weline\FileManager\FileManagerInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Cache\Contract\CachePoolInterface;
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
            'lockPath' => false,
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
            // 如果匹配到</file-manager>，则返回空
            if (str_contains($tag_data[0]??'', '</file-manager>')) {
                throw new \Exception(__('文件管理器标签不能包含</file-manager>标签。只能使用<file-manager/>标签。'));
            }
            if (!empty($attributes['code'])) {
                $userConfigFileManager = $attributes['code'];
            } else {
                # 检查是否有配置默认的文件管理器，默认使用 weline_media
                $userConfigFileManager = ObjectManager::getInstance(BackendUserConfig::class)->getConfig('file_manager') ?: 'weline_media';
            }
            $cacheKey = json_encode(func_get_args()) . $userConfigFileManager;
            /**@var CachePoolInterface $cache */
            $cache = w_cache('file_manager');
            $result = $cache->get($cacheKey);
            if ($result) {
                return $result;
            }
            /**@var Scan $fileScan $ */
            $fileScan = ObjectManager::getInstance(Scan::class);
            $fileManagers = [];
            $modules = Env::getInstance()->getActiveModules();

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
            if (!isset($fileManagers[$userConfigFileManager])) {
                # 指定的文件管理器不存在，尝试使用 weline_media，否则使用第一个可用的
                if (isset($fileManagers['weline_media'])) {
                    $fileManager = $fileManagers['weline_media'];
                } else {
                    if (!CLI) {
                        ObjectManager::getInstance(MessageManager::class)->addWarning(__('所指定的文件管理器不存在! 文件管理器名：%{1}', $userConfigFileManager));
                    }
                    $fileManager = array_pop($fileManagers);
                    if (!CLI && $fileManager) {
                        ObjectManager::getInstance(MessageManager::class)->addWarning(__('使用：%{1} 文件管理器代替。', $fileManager::name()));
                    }
                }
            } else {
                /**@var \Weline\FileManager\FileManager $fileManager */
                $fileManager = $fileManagers[$userConfigFileManager];
            }
            if (!isset($attributes['target'])) {
                throw new \Exception(__('缺少目标ID。文档：%{1}', self::document()));
            }
            if (str_starts_with($attributes['target'], '.')) {
                throw new \Exception(__('缺少目标ID。请使用ID选择器，例如：target="#id"。文档：%{1}', self::document()));
            }
            $fileManager
                ->setTarget(trim($attributes['target'], '#'))
                ->setPath($attributes['path'] ?? '')
                ->setLockPath((bool)($attributes['lockPath'] ?? false))
                ->setPreview((bool)($attributes['preview'] ?? true))
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
                    'tag_data' => $tag_data, // 兼容非自闭合标签内容
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

    /**
     * 指定父标签，用于依赖管理
     * @return string|null 父标签名称
     */
    public static function parent(): ?string
    {
        return null; // FileManager标签没有依赖
    }

    public static function document(): string
    {
        $doc = htmlentities(
            "<file-manager 
                        code='local'
                        target='#demo'
                        title='文件管理器' 
                        preview='1'
                        var='store' 
                        path='store/logo'
                        lockPath='1'
                        value='store.logo'
                        multi='0'
                        ext='jpg,png,gif,webp'
                        size='1048576'
                        w='50'
                        h='50'                        
                        />"
        );
        return <<<HTML
使用方法：
{$doc}
参数解释：
code：可选,指定安装的编辑器代码。例如：local、elfinder、weline_media
target：目标容器id【选择文件后会根据id回填到属性value上】
preview: 是否预览。默认：1
ext：可选。允许的文件后缀，默认 * 表示所有类型，例如：jpg,png,gif,webp
size：可选。允许的文件大小（字节），默认 102400（100KB），例如：1048576（1MB）
title：可选。文件管理器标题
path：可选。默认打开的文件路径，例如：store/logo
lockPath：可选。是否锁定路径（不能返回上级目录），默认：0
vars：当前变量
value：默认当前的文件路径
multi：可选。是否多选，默认单选
w：可选。默认预览宽50px
h：可选。默认预览高50px
HTML;
    }
}

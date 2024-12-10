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

class FileManagerConnector implements TaglibInterface
{
    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return 'file-manager-connector';
    }

    /**
     * @inheritDoc
     */
    public static function tag(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function attr(): array
    {
        return [
            'code' => false,
            'target' => false,
            'close' => false,
            'title' => false,
            'path' => true,
            'ext' => true,
            'value' => false,
            'vars' => false,
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
            if(empty($attributes)){
                $input   = $tag_data[1];
                $pattern = '/(\w+)\s*=\s*[\'"]?([^\'"]*)[\'"]?/';
                preg_match_all($pattern, $input, $matches);
                $outputArray = array();
                foreach ($matches[1] as $index => $match) {
                    $outputArray[$match] = $matches[2][$index];
                }
                $attributes = $outputArray;
            }
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
            $attributes['startPath'] = $attributes['path'] ?? '';
            if(isset($attributes['target'])){
                $attributes['target'] = trim($attributes['target'],'.#');
            }
            if(isset($attributes['close'])){
                $attributes['close'] = trim($attributes['close'],'.#');
            }
            $attributes['close'] = trim($attributes['close'] ?? '','.#');
            $attributes['ext'] = $attributes['ext'] ?? '';
            $attributes['value'] = $attributes['value'] ?? '';
            $attributes['vars'] = $attributes['vars'] ?? '';
            $attributes['w'] = $attributes['w'] ?? 50;
            $attributes['h'] = $attributes['h'] ?? 50;
            $attributes['size'] = $attributes['size'] ?? '';
            $attributes['title'] = $attributes['title'] ?? '';
            $attributes['multi'] = $attributes['multi'] ?? '0';
            $result = $fileManager->getConnector($attributes);
            $cache->set($cacheKey, $result);
            return $result;
        };
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close(): bool
    {
        return false;
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
            "<file-manager-cpnnector>code='local' target='#demo' close='#close' title='文件管理器' var='store' path='store/logo' value='store.logo' multi='0' ext='jpg,png,gif,webp' w='50' h='50'></file-manager-cpnnector>
            或者<br>
            @file-manager-cpnnector{code='local' target='#demo' close='#close' title='文件管理器' var='store' path='store/logo' value='store.logo' multi='0' ext='jpg,png,gif,webp' w='50' h='50'}
            或者<br>
            <file-manager-cpnnector target='#demo' close='#close' title='文件管理器' var='store' path='store/logo' value='store.logo' multi='0' ext='jpg,png,gif,webp' w='50' h='50'/>
            或者<br>
            @file-manager-cpnnector(code='local' target='#demo' close='#close' title='文件管理器' var='store' path='store/logo' value='store.logo' multi='0' ext='jpg,png,gif,webp' w='50' h='50')
            "
        );
        return <<<HTML
使用方法：
{$doc}
参数解释：
code: 可选, 指定编辑器代码。例如：local
target：可选【必须链接到URL上】。文件管理器回填目标ID。
close: 可选【必须链接到URL上】。文件管理器关闭按钮ID。
ext：必选。默认jpg,png,gif,webp格式
path：必选。默认打开的文件路径
title：可选。文件管理器标题
vars：可选。当前变量
value：可选。默认当前的文件路径
multi：可选。默认单选
w：可选。默认预览宽50px
h：可选。默认预览高50px
HTML;
    }
}

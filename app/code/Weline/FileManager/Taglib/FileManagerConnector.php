<?php

namespace Weline\FileManager\Taglib;

use Weline\Backend\Api\Config\BackendUserConfigStore;
use Weline\FileManager\FileManagerInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;
use Weline\Framework\Taglib\TaglibInterface;

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
            'lockPath' => false,
            'lockRoot' => false,
            'preview' => false,
            'ext' => true,
            'value' => false,
            'vars' => false,
            'multi' => false,
            'w' => false,
            'h' => false,
            'size' => false,
            'recommend_width' => false,
            'recommend_height' => false,
            'aspect_ratio' => false,
            'aspect_ratio_tolerance' => false,
            'min_width' => false,
            'min_height' => false,
            'max_width' => false,
            'max_height' => false,
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
            $booleanAttribute = static function (mixed $value, bool $default): bool {
                if ($value === null || $value === '') {
                    return $default;
                }
                $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                return $parsed ?? $default;
            };
            if (empty($attributes)) {
                $input = $tag_data[1];
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
                $userConfigFileManager = ObjectManager::getInstance(BackendUserConfigStore::class)->getConfig('file_manager') ?: 'weline_media';
            }
            if ($userConfigFileManager === 'local') {
                $userConfigFileManager = 'weline_media';
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
                # 指定的文件管理器不存在，优先使用已注册的 MediaManager
                if (isset($fileManagers['weline_media'])) {
                    $fileManager = $fileManagers['weline_media'];
                } else {
                    if (!CLI) {
                        ObjectManager::getInstance(MessageManager::class)->addWarning(__('所指定的文件管理器不存在! 文件管理器名：%{1}', $userConfigFileManager));
                    }
                    $fileManager = array_pop($fileManagers);
                    if (!CLI && $fileManager instanceof FileManagerInterface) {
                        ObjectManager::getInstance(MessageManager::class)->addWarning(__('使用：%{1} 文件管理器代替。', $fileManager::name()));
                    }
                }
            } else {
                /**@var \Weline\FileManager\FileManager $fileManager */
                $fileManager = $fileManagers[$userConfigFileManager];
            }
            if (!isset($fileManager) || !($fileManager instanceof FileManagerInterface)) {
                throw new \RuntimeException(__('未找到可用的文件管理器实现。'));
            }
            $attributes['startPath'] = $attributes['path'] ?? '';
            if (isset($attributes['target'])) {
                $attributes['target'] = trim($attributes['target'], '.#');
            }
            if (isset($attributes['close'])) {
                $attributes['close'] = trim($attributes['close'], '.#');
            }
            $attributes['close'] = trim($attributes['close'] ?? '', '.#');
            $attributes['ext'] = $attributes['ext'] ?? '';
            $attributes['lockPath'] = $booleanAttribute($attributes['lockPath'] ?? null, false);
            $attributes['lockRoot'] = trim(str_replace('\\', '/', (string)($attributes['lockRoot'] ?? '')), '/');
            $attributes['preview'] = $booleanAttribute($attributes['preview'] ?? null, false);
            $attributes['value'] = $attributes['value'] ?? '';
            $attributes['vars'] = $attributes['vars'] ?? '';
            $attributes['w'] = $attributes['w'] ?? 50;
            $attributes['h'] = $attributes['h'] ?? 50;
            $attributes['size'] = $attributes['size'] ?? '';
            $attributes['title'] = $attributes['title'] ?? '';
            $attributes['multi'] = $booleanAttribute($attributes['multi'] ?? null, false);
            $attributes['recommend_width'] = $attributes['recommend_width'] ?? '';
            $attributes['recommend_height'] = $attributes['recommend_height'] ?? '';
            $attributes['aspect_ratio'] = $attributes['aspect_ratio'] ?? '';
            $attributes['aspect_ratio_tolerance'] = $attributes['aspect_ratio_tolerance'] ?? '';
            $attributes['min_width'] = $attributes['min_width'] ?? '';
            $attributes['min_height'] = $attributes['min_height'] ?? '';
            $attributes['max_width'] = $attributes['max_width'] ?? '';
            $attributes['max_height'] = $attributes['max_height'] ?? '';
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

    /**
     * 指定父标签，用于依赖管理
     * @return string|null 父标签名称
     */
    public static function parent(): ?string
    {
        return null; // FileManagerConnector标签没有依赖
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
lockPath：可选。是否锁定路径（不能返回上级目录），默认：0
lockRoot：可选。锁定根相对路径（lockPath=1 时目标须位于该根下）；主题编辑器传 websites/{website}/{store}[/channel]
recommend_width：可选。建议图片宽度（选择器内展示提示）
recommend_height：可选。建议图片高度
aspect_ratio：可选。硬约束宽高比（如 16:9）；与 recommend_width+height 同时存在时以 aspect_ratio 为准，仅两边 recommend 时自动推导比例并硬拦
aspect_ratio_tolerance：可选。比例容差，默认 0.02
min_width/min_height/max_width/max_height：可选。分辨率限制（供选择后校验）
HTML;
    }
}

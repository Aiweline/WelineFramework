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
            'lockRoot' => false,
            'preview' => false,
            'setAttr' => false,
            'value' => true,
            'vars' => false,
            'ext' => true,
            'multi' => false,
            'w' => false,
            'h' => false,
            'size' => false,
            // MediaReferenceIdentity.v1 — explicit identity or Ambient self-build
            'identity' => false,
            'identity_root' => false,
            'identity_code' => false,
            'identity_scope' => false,
            'identity_kind' => false,
            'identity_field' => false,
            'identity_component' => false,
            'identity_locale' => false,
            'identity_instance' => false,
            'identity_path' => false,
            'ref_mode' => false,
            'owner_type' => false,
            'owner_id' => false,
            'owner_version' => false,
            'strong_ref' => false,
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
            // 如果匹配到</file-manager>，则返回空
            if (str_contains($tag_data[0]??'', '</file-manager>')) {
                throw new \Exception(__('文件管理器标签不能包含</file-manager>标签。只能使用<file-manager/>标签。'));
            }
            if (!empty($attributes['code'])) {
                $userConfigFileManager = $attributes['code'];
            } else {
                # 检查是否有配置默认的文件管理器，默认使用 weline_media
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
            if (!isset($fileManager) || !($fileManager instanceof FileManagerInterface)) {
                throw new \RuntimeException(__('未找到可用的文件管理器实现。'));
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
                ->setLockPath($booleanAttribute($attributes['lockPath'] ?? null, false))
                ->setLockRoot(trim(str_replace('\\', '/', (string)($attributes['lockRoot'] ?? '')), '/'))
                ->setPreview($booleanAttribute($attributes['preview'] ?? null, true))
                ->setValue($attributes['value'] ?? '')
                ->setTitle($attributes['title'] ?? '')
                ->setMulti($booleanAttribute($attributes['multi'] ?? null, false))
                ->setWidth($attributes['w'] ?? 50)
                ->setHeight($attributes['h'] ?? 50)
                ->setExt($attributes['ext'] ?? '*')
                ->setSize($attributes['size'] ?? '102400')
                ->setVars($attributes['vars'] ?? '');
            foreach ([
                'identity', 'identity_root', 'identity_code', 'identity_scope',
                'identity_kind', 'identity_field', 'identity_component', 'identity_locale',
                'identity_instance', 'identity_path', 'ref_mode', 'owner_type', 'owner_id',
                'owner_version', 'strong_ref',
            ] as $identityAttr) {
                if (array_key_exists($identityAttr, $attributes) && $attributes[$identityAttr] !== null && $attributes[$identityAttr] !== '') {
                    $fileManager->setData($identityAttr, $attributes[$identityAttr]);
                }
            }
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
            "<w:file-manager 
                        code='weline_media'
                        target='#demo'
                        title='选择图片' 
                        preview='1'
                        path='store/logo'
                        lockPath='1'
                        value=''
                        multi='0'
                        ext='jpg,png,gif,webp'
                        size='1048576'
                        w='50'
                        h='50'
                        identity_root='config'
                        identity_code='demo_logo'
                        identity_scope='default.default.default'
                        identity_kind='media'
                        identity_field='logo'
                        ref_mode='single'
                        strong_ref='1'
                        />"
        );
        return <<<HTML
&lt;w:file-manager /&gt; — <strong>媒体选图</strong>（回填目标 input），不是出图标签。
出图请用 &lt;w:file:image /&gt;。分工说明：app/code/Weline/FileManager/doc/file-manager-选图与file-image出图.md

使用方法：
{$doc}
参数解释：
code：可选，文件管理器实现（如 weline_media）
target：必填，目标元素 id（选择结果写入其 value）
preview：是否预览，默认 1
ext / size：允许后缀与字节大小
title：弹层标题
path / lockPath / lockRoot：默认目录与路径锁
value：当前值（路径模式为路径字符串）
multi / w / h：多选与预览宽高
identity_* / ref_mode / owner_* / strong_ref：MediaReferenceIdentity（强引用须有身份；禁止手拼 identity_path，用 w_scope）

typed file-image（主题部件 media_image）：请用 MediaManager Block WelineMedia，并设 value_mode=file-image 与 usage=1（见同上分工文档）。标签默认路径模式，勿与 &lt;w:file:image&gt; 混淆。
HTML;
    }
}

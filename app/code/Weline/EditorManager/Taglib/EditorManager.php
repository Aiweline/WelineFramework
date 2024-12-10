<?php

namespace Weline\EditorManager\Taglib;

use Weline\Backend\Model\BackendUserConfig;
use Weline\EditorManager\Cache\EditorManagerCacheFactory;
use Weline\EditorManager\EditorManager\Local;
use Weline\EditorManager\EditorManagerInterface;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;
use Weline\Taglib\TaglibInterface;

class EditorManager implements TaglibInterface
{
    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return 'editor-manager';
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
        return ['container-id' => true];
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
            $container_id = $attributes['container-id'] ?? '';
            if (empty($container_id)) {
                throw new Exception(__('editor-manager 标签属性 container-id 不能为空！'));
            }
            # 检查是否有配置默认的编辑器管理器
            /**@var BackendUserConfig $BackendUserConfig */
            $BackendUserConfig       = ObjectManager::getInstance(BackendUserConfig::class);
            $userConfigEditorManager = $BackendUserConfig->getConfig('editor-manager');
            if (empty($userConfigEditorManager)) {
                $userConfigEditorManager = $BackendUserConfig->getDefaultConfig('editor-manager');
            }
            if (empty($userConfigEditorManager)) {
                $userConfigEditorManager = 'local';
            }
            $cacheKey = json_encode(func_get_args()) . $userConfigEditorManager;
            /**@var CacheInterface $cache */
            $cache         = ObjectManager::getInstance(EditorManagerCacheFactory::class);
            $editorManager = $cache->get($cacheKey);
            if (!$editorManager) {
                /**@var Scan $fileScan $ */
                $fileScan       = ObjectManager::getInstance(Scan::class);
                $editorManagers = [];
                $modules        = Env::getInstance()->getActiveModules();
                foreach ($modules as $module) {
                    $files = [];
                    $fileScan->globFile(
                        $module['base_path'] . 'EditorManager',
                        $files,
                        '.php',
                        $module['base_path'],
                        $module['namespace_path'] . '\\',
                        '.php',
                        true
                    );
                    foreach ($files as $file) {
                        $class = ObjectManager::getInstance($file);
                        if ($class instanceof EditorManagerInterface) {
                            $editorManagers[$class::name()] = $class;
                        }
                    }
                }
                if (count($editorManagers) > 1 and $userConfigEditorManager === 'local') {
                    /**@var \Weline\EditorManager\EditorManager $editorManager */
                    $editorManager = array_pop($editorManagers);
                } else {
                    if (!isset($editorManagers[$userConfigEditorManager])) {
                        ObjectManager::getInstance(MessageManager::class)->addWarning(__('配置的编辑器管理器不存在! 编辑器管理器名：%1', $userConfigEditorManager));
                        # 使用第一个编辑器管理器作为默认的编辑器管理器
                        /**@var \Weline\EditorManager\EditorManager $editorManager */
                        $editorManager = array_shift($editorManagers);
                    } else {
                        /**@var \Weline\EditorManager\EditorManager $editorManager */
                        $editorManager = $editorManagers[$userConfigEditorManager];
                    }
                }
                $cache->set($cacheKey, $editorManager);
            }
            if (!$editorManager) {
                /**@var \Weline\EditorManager\EditorManager $editorManager */
                $editorManager = ObjectManager::getInstance(Local::class);
            }
            $editorManager->setTarget(trim($container_id, '.#'));
            $result = $editorManager->setData(
                ['tag_key' => $tag_key,
                    'tag_data' => $tag_data,
                    'attributes' => $attributes
                ]
            )->render();
            return $result ?: '';
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
            "<editor-manager container-id=\"demo-container\"/>"
        );
        return <<<HTML
使用方法：
{$doc}
参数解释：
container-id：目标容器id【编辑器会根据容器ID自动挂载编辑器到容器里面使用了editor属性的textarea元素】
如果要接入自己的编辑器。
1、新建模块或者在自己的模块中创建EditorManager目录。
2、在模块中创建一个名为自定义文件名的文件。（例如：CKEditor.php ）继承EditorManagerInterface
3、在文件中写入以下代码：
<?php
class CKEditor implements EditorManagerInterface
{
    public static function name(): string
    {
        return 'ckeditor';
    }
     public function render(): string
    {
        # 实现自己的编辑器，可以参考CKEditor的接入方式。
        # 你必须实现查找带有editor属性的textarea标签，自动将编辑器挂载。
    }
}
HTML;
    }
}

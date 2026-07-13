<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Hook;

use Weline\Framework\Compilation\CompiledExtensionRegistry;
use Weline\Framework\Hook\Config\HookReader;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Env;

class Hooker
{
    public function __construct(
        HookReader $hookReader
    )
    {
        unset($hookReader);
    }

    /**
     * 获取 Hook 内容
     * 
     * @param string $name Hook 名称
     * @return array
     * @throws \Exception
     */
    public function getHook(string $name)
    {
        if (!CompiledExtensionRegistry::hookExists($name)) {
            $message = (string)__('Hook 未定义或未注册：%{1}。请在模块 hook.php 中定义规约。', [$name]);
            if (defined('DEV') && DEV) {
                throw new \Exception($message);
            }
            w_log_error($message, [], 'hook');
            return [];
        }
        
        /** @var HookReader $hookReader */
        $hookReader = ObjectManager::make(HookReader::class);
        $hookReader->setPath($name);
        return $hookReader->getFileList();
    }
}

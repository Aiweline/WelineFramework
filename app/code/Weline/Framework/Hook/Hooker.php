<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Hook;

use Weline\Framework\Hook\Config\HookReader;
use Weline\Hook\HookManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Env;

class Hooker
{
    private HookReader $hookReader;
    private ?HookManager $hookManager = null;

    public function __construct(
        HookReader $hookReader
    )
    {
        $this->hookReader = $hookReader;
    }

    /**
     * 获取 Hook 管理器（延迟加载）
     * 
     * @return HookManager|null
     */
    private function getHookManager(): ?HookManager
    {
        if ($this->hookManager === null) {
            try {
                $this->hookManager = ObjectManager::getInstance(HookManager::class);
            } catch (\Throwable $e) {
                // 如果 Weline_Hook 模块未安装，返回 null（兼容旧版本）
                return null;
            }
        }
        return $this->hookManager;
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
        // 如果 HookManager 可用，进行验证
        $hookManager = $this->getHookManager();
        if ($hookManager !== null) {
            try {
                $hookManager->validateHook($name);
            } catch (\Exception $e) {
                // 在开发模式下抛出异常，生产模式下记录日志
                if (defined('DEV') && DEV) {
                    throw new \Exception(
                        __(
                            'Hook 未定义或未注册：%{1}。请在 Weline\Framework\Hook\HookInterface 中定义此 hook 常量，并在模块的 hook.php 文件中定义规约。',
                            [$name]
                        ),
                        0,
                        $e
                    );
                }
                w_log_error('Hook 验证失败: ' . $e->getMessage(), [], 'hook');
                // 生产模式下返回空数组，不中断执行
                return [];
            }
        }
        
        $this->hookReader->setPath($name);
        return $this->hookReader->getFileList();
    }
}

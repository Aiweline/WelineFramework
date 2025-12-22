<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Hook;

/**
 * Hook 管理器
 * 
 * 管理所有 hook 的验证和注册
 * 
 * @DESC    :    此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 * @since   1.0.0
 */
class HookManager
{
    private HookValidator $validator;
    private HookRegistry $registry;
    
    public function __construct(
        HookValidator $validator,
        HookRegistry $registry
    ) {
        $this->validator = $validator;
        $this->registry = $registry;
    }
    
    /**
     * 验证 hook 名称并检查是否已注册
     * 
     * @param string $hookName Hook 名称
     * @throws \Exception
     */
    public function validateHook(string $hookName): void
    {
        // 验证命名规范
        $this->validator->validateOrThrow($hookName);
        
        // 检查是否已注册（在 HookInterface 中定义）
        if (!$this->registry->isRegistered($hookName)) {
            throw new \Exception(
                __(
                    'Hook 未注册：%{1}。请在 Weline\Framework\Hook\HookInterface 中定义此 hook 常量。',
                    [$hookName]
                )
            );
        }
        
        // 在开发环境下，检查 Hook 是否有规约文件
        if (defined('DEV') && DEV) {
            if (!$this->registry->hasSpec($hookName)) {
                // 解析模块名
                $parts = explode('::', $hookName);
                $moduleName = $parts[0] ?? '';
                
                throw new \Exception(
                    __(
                        'Hook 未定义规约：%{1}。请在模块 %{2} 的 hook.php 文件中定义此 hook 的规约。',
                        [$hookName, $moduleName]
                    )
                );
            }
        }
    }
    
    /**
     * 获取验证器
     * 
     * @return HookValidator
     */
    public function getValidator(): HookValidator
    {
        return $this->validator;
    }
    
    /**
     * 获取注册表
     * 
     * @return HookRegistry
     */
    public function getRegistry(): HookRegistry
    {
        return $this->registry;
    }
}


<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Console\Command;

use Weline\Cdn\Service\CdnRuleCollector;
use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * CDN规则收集命令
 * 
 * 手动收集Api和Controller中的@Cdn注释规则
 * 
 * @package Weline_Cdn
 */
class CollectRules extends CommandAbstract implements CommandInterface
{
    private CdnRuleCollector $ruleCollector;
    private Printing $printing;

    public function __construct()
    {
        $this->ruleCollector = ObjectManager::getInstance(CdnRuleCollector::class);
        $this->printing = ObjectManager::getInstance(Printing::class);
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = []): string
    {
        $moduleName = $args['module'] ?? null;
        
        try {
            if ($moduleName) {
                // 收集指定模块
                $modules = Env::getInstance()->getModuleList();
                if (!isset($modules[$moduleName])) {
                    return __('模块不存在：%{1}', [$moduleName]);
                }
                
                $this->printing->note(__('正在收集模块 %{1} 的规则...', [$moduleName]));
                $collected = $this->ruleCollector->collectModule($moduleName, $modules[$moduleName]);
                $this->printing->success(__('模块 %{1} 规则收集完成，共收集 %{2} 条规则', [$moduleName, count($collected)]));
            } else {
                // 收集所有模块
                $this->printing->note(__('正在收集所有模块的规则...'));
                $collected = $this->ruleCollector->collectAll();
                $this->printing->success(__('规则收集完成，共收集 %{1} 条规则', [count($collected)]));
            }
            
            return __('规则收集完成');
        } catch (\Exception $e) {
            $this->printing->error(__('规则收集失败：%{1}', [$e->getMessage()]));
            return __('规则收集失败');
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('收集Api和Controller中的@Cdn注释规则');
    }

    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return [
            __('用法: php bin/w cdn:collect-rules [--module=模块名]'),
            __(''),
            __('选项:'),
            __('  --module=模块名    只收集指定模块的规则'),
            __(''),
            __('示例:'),
            __('  php bin/w cdn:collect-rules'),
            __('  php bin/w cdn:collect-rules --module=Weline_Product'),
        ];
    }
}

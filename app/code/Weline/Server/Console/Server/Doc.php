<?php
declare(strict_types=1);

/**
 * Weline Server - 文档命令
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Server\Service\OptimizationGuideService;

/**
 * Doc - 显示性能优化文档链接
 */
class Doc extends CommandAbstract
{
    /**
     * 优化指南服务
     */
    private OptimizationGuideService $guideService;
    
    /**
     * 构造函数
     */
    public function __construct(OptimizationGuideService $guideService)
    {
        $this->guideService = $guideService;
    }
    
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = []): string
    {
        $this->printer->setup(__('Weline Server 性能优化指南'));
        echo "\n";
        
        // 获取 Token 和 URL
        $token = OptimizationGuideService::generateToken();
        $phpInfo = $this->guideService->getPhpInfo();
        $summary = $this->guideService->getOptimizationSummary();
        
        // 显示环境信息
        $this->printer->note(__('📋 您的 PHP 环境'));
        echo "\n";
        $this->printKeyValue(__('PHP 版本'), $phpInfo['version']);
        $this->printKeyValue(__('PHP 路径'), $phpInfo['binary']);
        $this->printKeyValue(__('配置文件'), $phpInfo['ini_path']);
        $this->printKeyValue(__('扩展目录'), $phpInfo['ext_dir']);
        $this->printKeyValue(__('操作系统'), $phpInfo['os'] . ' (' . $phpInfo['arch'] . ')');
        echo "\n";
        
        // 显示优化状态
        $this->printer->note(__('⚡ 优化状态'));
        echo "\n";
        $this->printKeyValue(__('已优化'), "{$summary['installed']}/{$summary['total']}");
        $this->printKeyValue(__('当前加成'), $summary['current_boost']);
        $this->printKeyValue(__('潜在提升'), $summary['potential_boost']);
        echo "\n";
        
        // 显示待优化项
        if ($summary['missing'] > 0) {
            $this->printer->warning(__('⚠️ 待优化项：'));
            echo "\n";
            foreach ($summary['optimizations'] as $key => $opt) {
                if ($opt['priority'] === 'skip') {
                    continue;
                }
                if (!($opt['installed'] ?? false)) {
                    $this->printer->warning(\sprintf("  ✖ %-25s %s", $opt['name'], $opt['impact']));
                }
            }
            echo "\n";
        } else {
            $this->printer->success(__('✅ 所有优化项已配置完成！'));
            echo "\n";
        }
        
        // 获取后台 URL
        $envConfig = Env::getInstance()->getConfig();
        $baseUrl = $envConfig['base_url'] ?? 'http://localhost';
        $baseUrl = \rtrim($baseUrl, '/');
        
        // 显示文档链接
        $this->printer->setup(__('📖 在线优化指南'));
        echo "\n";
        $docUrl = "{$baseUrl}/weline_server/backend/optimization-guide";
        $this->printer->note(__('访问以下链接查看详细的安装步骤：'));
        echo "\n";
        $this->printer->success("  {$docUrl}");
        echo "\n\n";
        
        // 安全说明
        $this->printer->note(__('🔒 安全策略：仅允许后台本地 AJAX 请求'));
        $this->printer->note(__('💡 需要先登录后台才能访问'));
        echo "\n";
        
        // 快速优化提示
        if ($summary['missing'] > 0) {
            $this->showQuickOptimizationTips($summary);
        }
        
        return '';
    }
    
    /**
     * 打印键值对
     */
    protected function printKeyValue(string $key, string $value): void
    {
        echo \sprintf("  %-15s %s\n", $key . ':', $value);
    }
    
    /**
     * 显示快速优化提示
     */
    protected function showQuickOptimizationTips(array $summary): void
    {
        $this->printer->setup(__('🚀 快速优化'));
        echo "\n";
        
        foreach ($summary['optimizations'] as $key => $opt) {
            if ($opt['priority'] === 'skip' || ($opt['installed'] ?? false)) {
                continue;
            }
            
            // 只显示高优先级的快速提示
            if ($opt['priority'] !== 'high') {
                continue;
            }
            
            $this->printer->warning(__('📦 %{1}', [$opt['name']]));
            echo "\n";
            
            if (!empty($opt['install']['steps'])) {
                foreach ($opt['install']['steps'] as $step) {
                    if (!empty($step['command'])) {
                        $this->printer->note("  {$step['title']}");
                        $this->printer->success("    $ {$step['command']}");
                    } elseif (!empty($step['commands'])) {
                        $this->printer->note("  {$step['title']}");
                        foreach ($step['commands'] as $os => $cmd) {
                            $this->printer->note("    [{$os}]");
                            $this->printer->success("    $ {$cmd}");
                        }
                    } elseif (!empty($step['content'])) {
                        $this->printer->note("  {$step['title']}");
                        if (!empty($step['path'])) {
                            $this->printer->note(__('    文件：%{1}', [$step['path']]));
                        }
                        $this->printer->success("    " . \str_replace("\n", "\n    ", $step['content']));
                    }
                }
            }
            echo "\n";
        }
        
        $this->printer->note(__('完成后运行：php bin/w server:stop && php bin/w server:start'));
        echo "\n";
    }
    
    /**
     * @inheritDoc
     */
    public function getTip(): string
    {
        return __('显示性能优化文档链接');
    }
}

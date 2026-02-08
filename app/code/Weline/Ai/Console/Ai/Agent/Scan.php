<?php
declare(strict_types=1);

namespace Weline\Ai\Console\Ai\Agent;

use Weline\Ai\Service\AgentScanner;
use Weline\Framework\Console\CommandInterface;

/**
 * 智能体扫描 CLI 命令
 * 
 * 扫描 extends/module/Weline_Ai/Agent/ 下所有模块的智能体实现，
 * 注册到 ai_agent 数据库表。
 */
class Scan implements CommandInterface
{
    private AgentScanner $agentScanner;

    public function __construct(AgentScanner $agentScanner)
    {
        $this->agentScanner = $agentScanner;
    }

    public function tip(): string
    {
        return __('ai:agent:scan 扫描并注册智能体');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'ai:agent:scan',
            $this->tip(),
            [
                '-h, --help' => __('显示帮助信息'),
                '-v, --verbose' => __('显示详细信息'),
            ],
            [
                'php bin/m ai:agent:scan' => __('扫描并注册所有智能体'),
                'php bin/m ai:agent:scan -v' => __('显示详细的扫描过程'),
            ],
            [
                __('智能体通过 extends 规约注册，路径：extends/module/Weline_Ai/Agent/'),
                __('每个智能体必须实现 AgentInterface 接口。'),
            ]
        );
    }

    public function execute(array $args = [], array $data = [])
    {
        $verbose = isset($args['v']) || isset($args['verbose']);

        echo __('开始扫描智能体...') . "\n";

        try {
            $scannedAgents = $this->agentScanner->scanAllAgents();

            if (empty($scannedAgents)) {
                echo __('未发现任何智能体') . "\n";
            } else {
                echo sprintf("✓ " . __('成功扫描 %{count} 个智能体', ['count' => count($scannedAgents)]) . "\n\n");

                foreach ($scannedAgents as $agent) {
                    if ($verbose) {
                        echo sprintf(
                            "  • %s (%s) v%s\n",
                            $agent->getName(),
                            $agent->getCode(),
                            $agent->getVersion()
                        );
                        echo sprintf(
                            "    " . __('描述') . ": %s\n",
                            $agent->getDescription()
                        );
                        echo sprintf(
                            "    " . __('场景') . ": %s\n",
                            implode(', ', $agent->getScenarios())
                        );
                        echo sprintf(
                            "    " . __('工具数') . ": %d\n",
                            count($agent->getTools())
                        );
                        echo sprintf(
                            "    " . __('最大迭代') . ": %d\n",
                            $agent->getMaxIterations()
                        );
                        echo "\n";
                    } else {
                        echo sprintf(
                            "  • %s (%s) - %s [%d " . __('个工具') . "]\n",
                            $agent->getName(),
                            $agent->getCode(),
                            $agent->getDescription(),
                            count($agent->getTools())
                        );
                    }
                }
            }

            echo "\n" . __('智能体扫描完成') . "\n";

        } catch (\Exception $e) {
            echo "✗ " . __('扫描智能体失败') . "：" . $e->getMessage() . "\n";
            if ($verbose) {
                echo __('错误堆栈') . "：\n" . $e->getTraceAsString() . "\n";
            }
        }
    }
}

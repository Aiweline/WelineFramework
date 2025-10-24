<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/09
 */

namespace Weline\Ai\Console\Ai\Model;

use Weline\Ai\Service\DefaultModelManager;
use Weline\Framework\Console\CommandInterface;

/**
 * 默认模型管理CLI命令
 * 
 * 功能：
 * - 初始化默认模型配置
 * - 验证默认模型配置
 * - 显示默认模型信息
 * - 清除缓存
 */
class DefaultModel implements CommandInterface
{
    /**
     * @var DefaultModelManager
     */
    private DefaultModelManager $defaultModelManager;

    /**
     * 构造函数
     * 
     * @param DefaultModelManager $defaultModelManager
     */
    public function __construct(DefaultModelManager $defaultModelManager)
    {
        $this->defaultModelManager = $defaultModelManager;
    }

    /**
     * 命令注释
     * 
     * @return string
     */
    public function tip(): string
    {
        return 'ai:model:default 管理默认模型配置';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'ai:model:default',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
                'action=<action>' => '操作类型：list|init|validate|clear-cache',
            ],
            [
                'php bin/m ai:model:default' => '列出当前默认模型配置',
                'php bin/m ai:model:default action=list' => '列出当前默认模型配置',
                'php bin/m ai:model:default action=init' => '初始化默认模型配置',
                'php bin/m ai:model:default action=validate' => '验证默认模型配置',
                'php bin/m ai:model:default action=clear-cache' => '清除默认模型缓存',
            ],
            [
                '默认模型用于当用户未指定模型时自动选择。',
                '每种服务类型只能设置一个默认模型。',
                '支持的服务类型：text、image、audio、video、code、translation、embedding'
            ]
        );
    }

    /**
     * 执行命令
     * 
     * @param array $args
     * @param array $data
     * @return void
     */
    public function execute(array $args = [], array $data = [])
    {
        $action = $args['action'] ?? 'list';

        switch ($action) {
            case 'init':
                $this->initializeDefaults();
                break;
            case 'validate':
                $this->validateDefaults();
                break;
            case 'clear-cache':
                $this->clearCache();
                break;
            case 'list':
            default:
                $this->listDefaults();
                break;
        }
    }

    /**
     * 初始化默认配置
     * 
     * @return void
     */
    private function initializeDefaults(): void
    {
        echo "初始化默认模型配置...\n";

        try {
            $result = $this->defaultModelManager->initializeDefaults();
            
            if ($result) {
                echo "✓ 默认配置初始化成功！\n";
            } else {
                echo "ℹ 默认配置已存在，无需初始化\n";
            }

        } catch (\Exception $e) {
            echo "✗ 初始化失败：" . $e->getMessage() . "\n";
        }
    }

    /**
     * 验证默认配置
     * 
     * @return void
     */
    private function validateDefaults(): void
    {
        echo "验证默认模型配置...\n\n";

        try {
            $issues = $this->defaultModelManager->validateDefaultModels();
            
            if (empty($issues)) {
                echo "✓ 所有默认模型配置都是有效的！\n";
            } else {
                echo "✗ 发现以下配置问题：\n";
                foreach ($issues as $issue) {
                    echo "  • " . $issue . "\n";
                }
            }

        } catch (\Exception $e) {
            echo "✗ 验证失败：" . $e->getMessage() . "\n";
        }
    }

    /**
     * 清除缓存
     * 
     * @return void
     */
    private function clearCache(): void
    {
        echo "清除默认模型缓存...\n";

        try {
            $this->defaultModelManager->clearCache();
            echo "✓ 缓存清除成功！\n";

        } catch (\Exception $e) {
            echo "✗ 清除缓存失败：" . $e->getMessage() . "\n";
        }
    }

    /**
     * 列出默认配置
     * 
     * @return void
     */
    private function listDefaults(): void
    {
        echo "当前默认模型配置\n";
        echo str_repeat("=", 80) . "\n\n";

        try {
            $defaultModels = $this->defaultModelManager->getAllDefaultModels();
            
            if (empty($defaultModels)) {
                echo "未找到任何默认模型配置\n";
                echo "\n提示：运行 'php bin/m ai:model:default action=init' 初始化默认配置\n";
                return;
            }

            foreach ($defaultModels as $config) {
                $protectedText = $config['is_protected'] ? ' 🔒' : '';
                $activeText = $config['is_active'] ? '✓' : '✗';
                
                echo sprintf(
                    "  [%s] %s: %s (%s)\n",
                    $activeText,
                    $config['service_type_name'],
                    $config['model_name'],
                    $config['model_code']
                );
                echo sprintf(
                    "      优先级: %d | 状态: %s%s\n",
                    $config['priority'],
                    $config['is_active'] ? '激活' : '未激活',
                    $protectedText
                );
                echo "\n";
            }

            // 显示可用服务类型
            echo str_repeat("-", 80) . "\n\n";
            echo "可用服务类型：\n";
            $serviceTypes = $this->defaultModelManager->getAvailableServiceTypes();
            foreach ($serviceTypes as $code => $name) {
                echo sprintf("  • %s (%s)\n", $name, $code);
            }

        } catch (\Exception $e) {
            echo "✗ 获取配置失败：" . $e->getMessage() . "\n";
        }
    }
}


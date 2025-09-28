<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Console;

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
class DefaultModelCommand implements CommandInterface
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
        return 'ai:default-model:manage 管理默认模型配置 [action=list|init|validate|clear-cache]';
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
                echo "默认配置初始化成功！\n";
            } else {
                echo "默认配置已存在，无需初始化\n";
            }

        } catch (\Exception $e) {
            echo "初始化失败：" . $e->getMessage() . "\n";
        }
    }

    /**
     * 验证默认配置
     * 
     * @return void
     */
    private function validateDefaults(): void
    {
        echo "验证默认模型配置...\n";

        try {
            $issues = $this->defaultModelManager->validateDefaultModels();
            
            if (empty($issues)) {
                echo "所有默认模型配置都是有效的！\n";
            } else {
                echo "发现以下配置问题：\n";
                foreach ($issues as $issue) {
                    echo "  - " . $issue . "\n";
                }
            }

        } catch (\Exception $e) {
            echo "验证失败：" . $e->getMessage() . "\n";
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
            echo "缓存清除成功！\n";

        } catch (\Exception $e) {
            echo "清除缓存失败：" . $e->getMessage() . "\n";
        }
    }

    /**
     * 列出默认配置
     * 
     * @return void
     */
    private function listDefaults(): void
    {
        echo "当前默认模型配置：\n";

        try {
            $defaultModels = $this->defaultModelManager->getAllDefaultModels();
            
            if (empty($defaultModels)) {
                echo "未找到任何默认模型配置\n";
                return;
            }

            echo "\n";
            foreach ($defaultModels as $config) {
                $protectedText = $config['is_protected'] ? ' [受保护]' : '';
                echo sprintf(
                    "  %s: %s (%s) - 优先级: %d%s\n",
                    $config['service_type_name'],
                    $config['model_name'],
                    $config['model_code'],
                    $config['priority'],
                    $protectedText
                );
            }

            // 显示可用服务类型
            echo "\n";
            echo "可用服务类型：\n";
            $serviceTypes = $this->defaultModelManager->getAvailableServiceTypes();
            foreach ($serviceTypes as $code => $name) {
                echo sprintf("  %s: %s\n", $code, $name);
            }

        } catch (\Exception $e) {
            echo "获取配置失败：" . $e->getMessage() . "\n";
        }
    }
}

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

use Weline\Ai\Service\ModelCollector;
use Weline\Framework\Console\CommandInterface;

/**
 * AI模型收集CLI命令
 * 
 * 功能：
 * - 扫描并收集AI模型配置
 * - 更新模型信息到数据库
 * - 显示收集统计信息
 */
class ModelCollectCommand implements CommandInterface
{
    /**
     * @var ModelCollector
     */
    private ModelCollector $modelCollector;

    /**
     * 构造函数
     * 
     * @param ModelCollector $modelCollector
     */
    public function __construct(ModelCollector $modelCollector)
    {
        $this->modelCollector = $modelCollector;
    }

    /**
     * 命令注释
     * 
     * @return string
     */
    public function tip(): string
    {
        return 'ai:model:collect 扫描并收集AI模型配置文件';
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
        echo "开始收集AI模型配置...\n";

        try {
            $collectedModels = $this->modelCollector->collectAllModels();
            
            if (empty($collectedModels)) {
                echo "未找到任何模型配置文件\n";
                return;
            }

            echo sprintf("成功收集 %d 个模型：\n", count($collectedModels));
            
            foreach ($collectedModels as $model) {
                echo sprintf(
                    "  - %s (%s) - %s\n",
                    $model->getData('model_name'),
                    $model->getData('model_code'),
                    $model->getData('vendor')
                );
            }

            // 显示统计信息
            $stats = $this->modelCollector->getModelStats();
            echo "\n";
            echo "模型统计信息：\n";
            echo sprintf("  总数：%d\n", $stats['total']);
            echo sprintf("  激活：%d\n", $stats['active']);
            echo sprintf("  未激活：%d\n", $stats['inactive']);
            echo sprintf("  受保护：%d\n", $stats['protected']);
            echo sprintf("  可删除：%d\n", $stats['deletable']);

            echo "模型收集完成！\n";

        } catch (\Exception $e) {
            echo "收集模型失败：" . $e->getMessage() . "\n";
        }
    }
}

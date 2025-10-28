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
class Collect implements CommandInterface
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

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'ai:model:collect',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
                '-v, --verbose' => '显示详细信息',
            ],
            [
                'php bin/m ai:model:collect' => '扫描并收集所有AI模型配置文件',
                'php bin/m ai:model:collect -v' => '显示详细的收集过程',
            ],
            [
                '该命令会扫描 app/code/Weline/Ai/etc/models/ 目录下的所有 .json 配置文件',
                '并将模型信息同步到数据库中。如果模型已存在，则更新其配置信息。'
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
        $verbose = isset($args['v']) || isset($args['verbose']);

        echo "开始收集AI模型配置...\n";

        try {
            $collectedModels = $this->modelCollector->collectAllModels();
            
            if (empty($collectedModels)) {
                echo "未找到任何模型配置文件\n";
                return;
            }

            echo sprintf("成功收集 %d 个模型：\n", count($collectedModels));
            
            foreach ($collectedModels as $model) {
                $modelName = $model->getName();
                $modelCode = $model->getData('model_code');
                $vendor = $model->getSupplier();
                
                if ($verbose) {
                    echo sprintf(
                        "  ✓ %s (%s) - %s\n",
                        $modelName,
                        $modelCode,
                        $vendor
                    );
                    echo sprintf(
                        "    输入价格: $%s/1K tokens | 输出价格: $%s/1K tokens\n",
                        number_format($model->getData('token_price_input') ?? 0, 6),
                        number_format($model->getData('token_price_output') ?? 0, 6)
                    );
                } else {
                    echo sprintf(
                        "  - %s (%s) - %s\n",
                        $modelName,
                        $modelCode,
                        $vendor
                    );
                }
            }

            echo "\n模型收集完成！\n";

        } catch (\Exception $e) {
            echo "收集模型失败：" . $e->getMessage() . "\n";
            if ($verbose) {
                echo "错误堆栈：\n" . $e->getTraceAsString() . "\n";
            }
        }
    }
}


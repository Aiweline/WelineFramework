<?php
declare(strict_types=1);

namespace Weline\Ai\Console\Ai\Vendor;

use Weline\Ai\Service\VendorModelScanner;
use Weline\Framework\Console\CommandInterface;

/**
 * AI供应商模型扫描CLI命令
 * 
 * 功能：
 * - 扫描并注册AiVendorModel类
 * - 更新模型信息到数据库
 * - 显示模型统计信息
 */
class Scan implements CommandInterface
{
    /**
     * @var VendorModelScanner
     */
    private VendorModelScanner $vendorModelScanner;

    /**
     * 构造函数
     * 
     * @param VendorModelScanner $vendorModelScanner
     */
    public function __construct(VendorModelScanner $vendorModelScanner)
    {
        $this->vendorModelScanner = $vendorModelScanner;
    }

    /**
     * 命令注释
     * 
     * @return string
     */
    public function tip(): string
    {
        return 'ai:vendor:scan 扫描并注册AI供应商模型';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'ai:vendor:scan',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
                '-v, --verbose' => '显示详细信息',
            ],
            [
                'php bin/m ai:vendor:scan' => '扫描并注册所有AI供应商模型',
                'php bin/m ai:vendor:scan -v' => '显示详细的扫描过程',
            ],
            [
                'AI供应商模型用于封装不同AI服务商的API调用。',
                '扫描器会自动发现继承自AiVendorModel的类。',
                '每个供应商模型会自动注册到ai_model表中。'
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

        echo "开始扫描AI供应商模型...\n";

        try {
            // 扫描供应商模型
            $scannedModels = $this->vendorModelScanner->scanAllVendorModels();
            
            if (empty($scannedModels)) {
                echo "未找到任何供应商模型\n";
            } else {
                echo sprintf("✓ 成功扫描 %d 个供应商模型：\n\n", count($scannedModels));
                
                foreach ($scannedModels as $model) {
                    if ($verbose) {
                        echo sprintf(
                            "  • %s (%s/%s/%s)\n",
                            get_class($model),
                            $model->getVendor(),
                            $model->getProduct(),
                            $model->getModel()
                        );
                        echo sprintf(
                            "    API URL: %s\n",
                            $model->getApiUrl()
                        );
                        echo sprintf(
                            "    API Key: %s\n",
                            $model->getApiKey() ? '已设置' : '未设置'
                        );
                        echo "\n";
                    } else {
                        echo sprintf(
                            "  • %s - %s/%s/%s\n",
                            get_class($model),
                            $model->getVendor(),
                            $model->getProduct(),
                            $model->getModel()
                        );
                    }
                }
            }

            // 显示统计信息
            $stats = $this->vendorModelScanner->getVendorModelStats();
            echo "\n" . str_repeat("=", 60) . "\n\n";
            echo "供应商模型统计信息：\n";
            echo sprintf("  总数：%d\n", $stats['total']);
            echo sprintf("  激活：%d\n", $stats['active']);
            echo sprintf("  未激活：%d\n", $stats['inactive']);

            echo "\n供应商模型扫描完成！\n";

        } catch (\Exception $e) {
            echo "✗ 扫描供应商模型失败：" . $e->getMessage() . "\n";
            if ($verbose) {
                echo "错误堆栈：\n" . $e->getTraceAsString() . "\n";
            }
        }
    }
}

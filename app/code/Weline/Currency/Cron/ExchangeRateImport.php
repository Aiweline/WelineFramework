<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Currency\Cron;

use Weline\Currency\Api\ExchangeRateApi;
use Weline\Currency\Api\ExchangeRateApiInterface;
use Weline\Currency\Model\Config;
use Weline\Currency\Service\CurrencyImportService;
use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 汇率导入定时任务
 * 
 * 定期从第三方API导入汇率数据
 */
class ExchangeRateImport implements CronTaskInterface
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var CurrencyImportService
     */
    private CurrencyImportService $importService;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->config = ObjectManager::getInstance(Config::class);
        
        // 创建API实例
        $apiKey = $this->config->getImportApiKey();
        $api = ObjectManager::getInstance(ExchangeRateApi::class, ['apiKey' => $apiKey]);
        
        $this->importService = ObjectManager::getInstance(CurrencyImportService::class, ['api' => $api]);
    }

    /**
     * 任务名称
     * 
     * @return string
     */
    public function name(): string
    {
        return '货币汇率自动导入任务';
    }

    /**
     * 执行名称
     * 
     * @return string
     */
    public function execute_name(): string
    {
        return 'currency_exchange_rate_import';
    }

    /**
     * 任务描述
     * 
     * @return string
     */
    public function tip(): string
    {
        return '定期从第三方API导入货币汇率数据，更新系统中的汇率信息';
    }

    /**
     * Cron时间表达式
     * 
     * 从配置中读取，默认每天凌晨2点执行
     * 
     * @return string
     */
    public function cron_time(): string
    {
        return $this->config->getImportCronTime();
    }

    /**
     * 执行任务
     * 
     * @return string
     */
    public function execute(): string
    {
        try {
            // 检查是否启用自动导入
            if (!$this->config->isImportEnabled()) {
                return '自动导入功能已禁用，跳过执行';
            }

            // 获取基准货币
            $baseCurrency = $this->config->getBaseCurrency();
            
            if (empty($baseCurrency)) {
                return '基准货币未设置，无法执行导入';
            }

            // 执行导入
            $result = $this->importService->importAll($baseCurrency);

            // 更新最后导入时间
            $this->config->setLastImportTime(time());

            // 构建执行结果消息
            $message = sprintf(
                '汇率导入完成 - 总计: %d, 成功: %d, 失败: %d',
                $result['total_count'],
                $result['success_count'],
                $result['fail_count']
            );

            // 如果有错误，添加到消息中
            if (!empty($result['errors'])) {
                $errorMessages = [];
                foreach ($result['errors'] as $error) {
                    $errorMessages[] = sprintf('%s: %s', $error['currency'], $error['error']);
                }
                $message .= ' | 错误: ' . implode('; ', array_slice($errorMessages, 0, 5)); // 最多显示5个错误
                if (count($result['errors']) > 5) {
                    $message .= sprintf(' ... (还有 %d 个错误)', count($result['errors']) - 5);
                }
            }

            return $message;

        } catch (\Exception $e) {
            $errorMessage = sprintf('汇率导入任务执行失败: %s', $e->getMessage());
            
            // 记录错误日志
            error_log($errorMessage);
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            return $errorMessage;
        }
    }

    /**
     * 任务超时时间（分钟）
     * 
     * @param int $minute
     * @return int
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return 30; // 30分钟超时
    }
}


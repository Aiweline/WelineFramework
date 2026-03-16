<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Service;

use Weline\Cdn\Model\Domain;
use Weline\Cdn\Model\WarmupUrl;
use Weline\Framework\Manager\ObjectManager;

/**
 * 预热执行器
 * 
 * 执行预热URL的HTTP请求
 * 
 * @package Weline_Cdn
 */
class WarmupRunner
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 执行预热任务
     * 
     * @param int $limit 每次处理的URL数量限制
     * @return array ['processed' => int, 'success' => int, 'fail' => int]
     */
    public function run(int $limit = 50): array
    {
        $processed = 0;
        $success = 0;
        $fail = 0;

        /** @var WarmupUrl $warmupUrlModel */
        $warmupUrlModel = $this->objectManager->getInstance(WarmupUrl::class);

        // 获取待处理的URL（启用状态、未达到目标次数）
        $candidates = $warmupUrlModel->reset()
            ->where(WarmupUrl::schema_fields_ENABLED, 1)
            ->limit(max($limit * 3, $limit))
            ->select()
            ->fetch()
            ->getItems();
        $urls = [];
        foreach ($candidates as $candidate) {
            $processedCount = (int)$candidate->getData(WarmupUrl::schema_fields_PROCESSED_COUNT);
            $targetCount = (int)$candidate->getData(WarmupUrl::schema_fields_TARGET_COUNT);
            if ($processedCount < $targetCount) {
                $urls[] = $candidate;
                if (count($urls) >= $limit) {
                    break;
                }
            }
        }

        foreach ($urls as $warmupUrl) {
            $processed++;
            
            try {
                $this->warmupUrl($warmupUrl);
                $success++;
            } catch (\Exception $e) {
                $fail++;
                w_log_error("预热URL失败: {$warmupUrl->getData(WarmupUrl::schema_fields_URL)}, 错误: " . $e->getMessage());
            }
        }

        return [
            'processed' => $processed,
            'success' => $success,
            'fail' => $fail
        ];
    }

    /**
     * 预热单个URL
     * 
     * @param WarmupUrl $warmupUrl 预热URL模型
     * @return void
     */
    private function warmupUrl(WarmupUrl $warmupUrl): void
    {
        $url = $warmupUrl->getData(WarmupUrl::schema_fields_URL);
        $domainId = $warmupUrl->getData(WarmupUrl::schema_fields_DOMAIN_ID);

        // 检查域名间隔
        if ($domainId) {
            /** @var Domain $domainModel */
            $domainModel = $this->objectManager->getInstance(Domain::class)->reset()->load($domainId);
            
            if ($domainModel->getData(Domain::schema_fields_DOMAIN_ID)) {
                $interval = $domainModel->getData(Domain::schema_fields_WARMUP_INTERVAL_SECONDS) ?: 300;
                $lastWarmed = $warmupUrl->getData(WarmupUrl::schema_fields_LAST_WARMED_AT);
                
                if ($lastWarmed && (time() - $lastWarmed) < $interval) {
                    // 未到间隔时间，跳过
                    return;
                }
            }
        }

        // 发送HTTP请求
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'CF-Prewarm: t0k3n-' . md5($url . time())
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // 更新统计
        $processedCount = (int)$warmupUrl->getData(WarmupUrl::schema_fields_PROCESSED_COUNT) + 1;
        $warmupUrl->setData(WarmupUrl::schema_fields_PROCESSED_COUNT, $processedCount);
        $warmupUrl->setData(WarmupUrl::schema_fields_LAST_WARMED_AT, time());

        if ($error || ($httpCode < 200 || $httpCode >= 300)) {
            $failCount = (int)$warmupUrl->getData(WarmupUrl::schema_fields_FAIL_COUNT) + 1;
            $warmupUrl->setData(WarmupUrl::schema_fields_FAIL_COUNT, $failCount);
            $warmupUrl->setData(WarmupUrl::schema_fields_STATUS, WarmupUrl::STATUS_FAIL);
            
            // 重试逻辑
            $retries = (int)$warmupUrl->getData(WarmupUrl::schema_fields_RETRIES) + 1;
            $warmupUrl->setData(WarmupUrl::schema_fields_RETRIES, $retries);
        } else {
            $successCount = (int)$warmupUrl->getData(WarmupUrl::schema_fields_SUCCESS_COUNT) + 1;
            $warmupUrl->setData(WarmupUrl::schema_fields_SUCCESS_COUNT, $successCount);
            $warmupUrl->setData(WarmupUrl::schema_fields_STATUS, WarmupUrl::STATUS_SUCCESS);
        }

        $warmupUrl->save();
    }
}


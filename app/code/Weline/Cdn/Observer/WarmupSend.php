<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Observer;

use Weline\Cdn\Model\WarmupUrl;
use Weline\Cdn\Service\UrlSiteResolver;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * CDN预热URL投递观察者
 * 
 * 监听 Weline_Cdn::send_warmup 事件，将预热URL保存到数据库
 * 
 * @package Weline_Cdn
 */
class WarmupSend implements ObserverInterface
{
    private WarmupUrl $warmupUrlModel;
    private UrlSiteResolver $urlSiteResolver;

    public function __construct(
        WarmupUrl $warmupUrlModel,
        UrlSiteResolver $urlSiteResolver
    ) {
        $this->warmupUrlModel = $warmupUrlModel;
        $this->urlSiteResolver = $urlSiteResolver;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        
        // 验证必需参数
        if (empty($data['module'])) {
            $event->setData('result', [
                'success' => false,
                'message' => __('模块参数不能为空')
            ]);
            return;
        }

        if (empty($data['urls']) || !is_array($data['urls'])) {
            $event->setData('result', [
                'success' => false,
                'message' => __('URL列表不能为空')
            ]);
            return;
        }

        $module = $data['module'];
        $urls = $data['urls'];
        $provider = $data['provider'] ?? 'default';
        $dedupe = $data['dedupe'] ?? true;

        $insertedCount = 0;
        $updatedCount = 0;
        /** @var list<array<string,mixed>> $pendingInserts */
        $pendingInserts = [];

        // 批量预读本批已存在的 URL 行，替代逐条 find()：
        // 原实现每条 URL 一次 SELECT + 一次 save()，1715 条 URL 会打 3400+ 次数据库往返
        // （实测 setup:upgrade 的 CDN 预热入队单步 41 秒）。改后每批（200 条）只多 1 次 SELECT。
        $existingByUrl = [];
        if ($dedupe) {
            $urlStrings = [];
            foreach ($urls as $urlItem) {
                if (is_string($urlItem)) {
                    $urlStrings[] = $urlItem;
                } elseif (is_array($urlItem)) {
                    $urlStrings[] = (string)($urlItem['url'] ?? '');
                }
            }
            $urlStrings = array_values(array_unique(array_filter($urlStrings, static fn ($u) => $u !== '')));
            if ($urlStrings !== []) {
                try {
                    $existingRows = $this->warmupUrlModel->reset()
                        ->where(WarmupUrl::schema_fields_MODULE, $module)
                        ->where(WarmupUrl::schema_fields_URL, $urlStrings, 'IN')
                        ->select()
                        ->fetch()
                        ->toModels();
                    foreach ($existingRows as $row) {
                        if (!$row instanceof WarmupUrl) {
                            continue;
                        }
                        $existingByUrl[(string)$row->getData(WarmupUrl::schema_fields_URL)] = $row;
                    }
                } catch (\Throwable) {
                    // 预读失败不影响后续流程，退化为逐条 find()
                    $existingByUrl = [];
                }
            }
        }

        foreach ($urls as $urlItem) {
            // 处理URL格式（可以是字符串或数组）
            if (is_string($urlItem)) {
                $url = $urlItem;
                $siteId = null;
                $domainId = null;
            } else if (is_array($urlItem)) {
                $url = $urlItem['url'] ?? '';
                $siteId = $urlItem['site_id'] ?? null;
                $domainId = $urlItem['domain_id'] ?? null;
            } else {
                continue;
            }

            if (empty($url)) {
                continue;
            }

            // 如果未指定site_id或domain_id，尝试解析
            if (!$domainId) {
                $domain = $this->urlSiteResolver->resolveDomainByUrl($url);
                if ($domain) {
                    $domainId = $domain->getData('domain_id');
                    $siteId = $domain->getData('site_id');
                } else if ($siteId) {
                    $domain = $this->urlSiteResolver->resolveDomainBySiteId($siteId);
                    if ($domain) {
                        $domainId = $domain->getData('domain_id');
                    }
                }
            }

            // 如果启用去重，从本批预读映射取已存在行（不再逐条 find()）
            if ($dedupe) {
                $existing = $existingByUrl[$url] ?? null;

                if ($existing instanceof WarmupUrl && $existing->getData(WarmupUrl::schema_fields_WARMUP_URL_ID)) {
                    // 更新现有记录
                    $existing->setData(WarmupUrl::schema_fields_SITE_ID, $siteId);
                    $existing->setData(WarmupUrl::schema_fields_DOMAIN_ID, $domainId);
                    $existing->setData(WarmupUrl::schema_fields_PROVIDER, $provider);
                    // 发布驱动的重新收集要重跑预热（逐URL purge→warm）：
                    // 重置处理进度，否则已成功的行不会再被 WarmupRunner 选中。
                    $existing->setData(WarmupUrl::schema_fields_PROCESSED_COUNT, 0);
                    $existing->setData(WarmupUrl::schema_fields_TARGET_COUNT, 1);
                    $existing->setData(WarmupUrl::schema_fields_STATUS, WarmupUrl::STATUS_PENDING);
                    $existing->setData(WarmupUrl::schema_fields_UPDATED_AT, time());
                    $existing->save();
                    $updatedCount++;
                    continue;
                }
            }

            // 创建新记录：先收集，循环结束后批量 insert（逐条 save() 在 1700+ 条时很慢）
            $pendingInserts[] = [
                WarmupUrl::schema_fields_MODULE => $module,
                WarmupUrl::schema_fields_PROVIDER => $provider,
                WarmupUrl::schema_fields_URL => $url,
                WarmupUrl::schema_fields_SITE_ID => $siteId,
                WarmupUrl::schema_fields_DOMAIN_ID => $domainId,
                WarmupUrl::schema_fields_STATUS => WarmupUrl::STATUS_PENDING,
                WarmupUrl::schema_fields_TARGET_COUNT => 1,
                WarmupUrl::schema_fields_PROCESSED_COUNT => 0,
                WarmupUrl::schema_fields_SUCCESS_COUNT => 0,
                WarmupUrl::schema_fields_FAIL_COUNT => 0,
                WarmupUrl::schema_fields_RETRIES => 0,
                WarmupUrl::schema_fields_ENABLED => 1,
            ];
            $insertedCount++;
        }

        // 批量写入新行：每 200 条一次 INSERT，替代逐条 save() 的 1700+ 次往返
        if ($pendingInserts !== []) {
            foreach (array_chunk($pendingInserts, 200) as $chunk) {
                try {
                    $this->warmupUrlModel->reset()->insert($chunk);
                } catch (\Throwable $e) {
                    // 批量失败时退回逐条 save()，保证数据不丢
                    w_log_error('WarmupSend batch insert failed, fallback per-row: ' . $e->getMessage());
                    foreach ($chunk as $rowData) {
                        try {
                            $this->warmupUrlModel->reset()->setData($rowData)->save();
                        } catch (\Throwable $inner) {
                            w_log_error('WarmupSend insert row failed: ' . $inner->getMessage());
                        }
                    }
                }
            }
        }

        $event->setData('result', [
            'success' => true,
            'message' => __('处理完成'),
            'inserted_count' => $insertedCount,
            'updated_count' => $updatedCount
        ]);
    }
}


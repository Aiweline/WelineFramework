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

            // 如果启用去重，检查URL是否已存在
            if ($dedupe) {
                $existing = $this->warmupUrlModel->reset()
                    ->where(WarmupUrl::schema_fields_URL, $url)
                    ->where(WarmupUrl::schema_fields_MODULE, $module)
                    ->find()
                    ->fetch();

                if ($existing->getData(WarmupUrl::schema_fields_WARMUP_URL_ID)) {
                    // 更新现有记录
                    $existing->setData(WarmupUrl::schema_fields_SITE_ID, $siteId);
                    $existing->setData(WarmupUrl::schema_fields_DOMAIN_ID, $domainId);
                    $existing->setData(WarmupUrl::schema_fields_PROVIDER, $provider);
                    $existing->setData(WarmupUrl::schema_fields_UPDATED_AT, time());
                    $existing->save();
                    $updatedCount++;
                    continue;
                }
            }

            // 创建新记录
            $warmupUrl = $this->warmupUrlModel->reset();
            $warmupUrl->setData(WarmupUrl::schema_fields_MODULE, $module);
            $warmupUrl->setData(WarmupUrl::schema_fields_PROVIDER, $provider);
            $warmupUrl->setData(WarmupUrl::schema_fields_URL, $url);
            $warmupUrl->setData(WarmupUrl::schema_fields_SITE_ID, $siteId);
            $warmupUrl->setData(WarmupUrl::schema_fields_DOMAIN_ID, $domainId);
            $warmupUrl->setData(WarmupUrl::schema_fields_STATUS, WarmupUrl::STATUS_PENDING);
            $warmupUrl->setData(WarmupUrl::schema_fields_TARGET_COUNT, 1);
            $warmupUrl->setData(WarmupUrl::schema_fields_PROCESSED_COUNT, 0);
            $warmupUrl->setData(WarmupUrl::schema_fields_SUCCESS_COUNT, 0);
            $warmupUrl->setData(WarmupUrl::schema_fields_FAIL_COUNT, 0);
            $warmupUrl->setData(WarmupUrl::schema_fields_RETRIES, 0);
            $warmupUrl->setData(WarmupUrl::schema_fields_ENABLED, 1);
            $warmupUrl->save();
            $insertedCount++;
        }

        $event->setData('result', [
            'success' => true,
            'message' => __('处理完成'),
            'inserted_count' => $insertedCount,
            'updated_count' => $updatedCount
        ]);
    }
}


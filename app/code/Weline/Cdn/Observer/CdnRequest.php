<?php

declare(strict_types=1);

namespace Weline\Cdn\Observer;

use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Cdn\Service\CachePurger;
use Weline\Cdn\Service\RuleManager;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * CDN 统一请求处理器
 * 
 * 处理来自其他模块的 CDN 操作请求
 * 支持的操作类型：
 * - purge_all: 清理全站缓存
 * - purge_urls: 清理指定 URL 缓存
 * - push_rule: 推送 CDN 规则
 * - check_capability: 检测 CDN 能力
 */
class CdnRequest implements ObserverInterface
{
    private CachePurger $cachePurger;
    private AdapterResolver $adapterResolver;
    private RuleManager $ruleManager;
    private Domain $domainModel;

    public function __construct(
        CachePurger $cachePurger,
        AdapterResolver $adapterResolver,
        RuleManager $ruleManager,
        Domain $domainModel
    ) {
        $this->cachePurger = $cachePurger;
        $this->adapterResolver = $adapterResolver;
        $this->ruleManager = $ruleManager;
        $this->domainModel = $domainModel;
    }

    public function execute(Event &$event): void
    {
        $eventData = $event->getData();
        $action = $event->getData('action');
        $siteIdProvided = array_key_exists('site_id', $eventData)
            || array_key_exists('website_id', $eventData);
        $siteIdValue = array_key_exists('site_id', $eventData)
            ? $eventData['site_id']
            : ($eventData['website_id'] ?? null);
        $siteId = $this->normalizeSiteId(
            $siteIdProvided ? $siteIdValue : w_env_website_id(),
            $siteIdProvided
        );
        $domain = $event->getData('domain');
        $data = $event->getData('data') ?? [];

        // 初始化响应
        $response = [
            'success' => false,
            'message' => '',
            'data' => [],
        ];

        try {
            // 获取域名配置
            $domainConfig = $this->resolveDomain($siteId, is_string($domain) ? $domain : null);
            
            if (!$domainConfig && $action !== 'check_capability') {
                $response['message'] = __('未找到 CDN 域名配置');
                $event->setData('response', $response);
                return;
            }

            switch ($action) {
                case 'purge_all':
                    $response = $this->handlePurgeAll($domainConfig);
                    break;

                case 'purge_urls':
                    $response = $this->handlePurgeUrls($domainConfig, $data);
                    break;

                case 'push_rule':
                    $response = $this->handlePushRule($domainConfig, $data);
                    break;

                case 'check_capability':
                    $response = $this->handleCheckCapability($domainConfig);
                    break;

                default:
                    $response['message'] = __('未知的操作类型：%{1}', [$action]);
            }
        } catch (\Throwable $e) {
            $response['success'] = false;
            $response['message'] = $e->getMessage();
        }

        $event->setData('response', $response);
    }

    /**
     * 解析域名配置
     * 
     * @param int|null $siteId 网站ID；null 表示当前上下文也缺失
     * @param string|null $domain 域名
     * @return Domain|null
     */
    private function resolveDomain(?int $siteId, ?string $domain): ?Domain
    {
        // 没有站点上下文时不得以域名或“第一个启用域名”跨站解析。
        if ($siteId === null) {
            return null;
        }

        $domain = trim((string)$domain);
        if ($domain !== '') {
            $domainModel = clone $this->domainModel;
            $domainModel->reset()
                ->where(Domain::schema_fields_DOMAIN_NAME, $domain)
                ->where(Domain::schema_fields_ENABLED, 1)
                ->where(Domain::schema_fields_SITE_ID, $siteId);
            $domainModel->find()->fetch();
            
            if ($domainModel->getData(Domain::schema_fields_DOMAIN_ID)) {
                return $domainModel;
            }

            // 调用方显式指定了域名时，未命中就是未命中，不能退回同站其他域名。
            return null;
        }

        $domainModel = clone $this->domainModel;
        $domainModel->reset()
            ->where(Domain::schema_fields_SITE_ID, $siteId)
            ->where(Domain::schema_fields_ENABLED, 1)
            ->find()
            ->fetch();

        if ($domainModel->getData(Domain::schema_fields_DOMAIN_ID)) {
            return $domainModel;
        }

        return null;
    }

    private function normalizeSiteId(mixed $value, bool $explicit): ?int
    {
        if ($value === null || (!$explicit && ($value === '' || $value === false))) {
            return null;
        }
        if (is_int($value)) {
            $siteId = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
            $siteId = (int)$value;
        } else {
            throw new \InvalidArgumentException(
                $explicit ? __('site_id 必须是非负整数') : __('当前网站 ID 无效')
            );
        }
        if ($siteId < 0) {
            throw new \InvalidArgumentException(__('site_id 不能为负数'));
        }
        return $siteId;
    }

    /**
     * 处理全站缓存清理
     */
    private function handlePurgeAll(Domain $domain): array
    {
        $result = $this->cachePurger->purge(
            $domain->getData(Domain::schema_fields_DOMAIN_ID),
            'everything'
        );

        return [
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? __('缓存清理完成'),
            'data' => $result,
        ];
    }

    /**
     * 处理 URL 缓存清理
     */
    private function handlePurgeUrls(Domain $domain, array $data): array
    {
        $urls = $data['urls'] ?? [];
        if (empty($urls)) {
            return [
                'success' => false,
                'message' => __('URL 列表不能为空'),
                'data' => [],
            ];
        }

        $result = $this->cachePurger->purge(
            $domain->getData(Domain::schema_fields_DOMAIN_ID),
            'urls',
            ['urls' => $urls]
        );

        return [
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? __('URL 缓存清理完成'),
            'data' => $result,
        ];
    }

    /**
     * 处理规则推送
     */
    private function handlePushRule(Domain $domain, array $data): array
    {
        $rules = $data['rules'] ?? [];
        if (empty($rules)) {
            return [
                'success' => false,
                'message' => __('规则列表不能为空'),
                'data' => [],
            ];
        }

        try {
            // 使用 RuleManager 推送规则
            foreach ($rules as $rule) {
                $this->ruleManager->saveRule([
                    'domain_id' => $domain->getData(Domain::schema_fields_DOMAIN_ID),
                    'rule_type' => $rule['type'] ?? 'bypass',
                    'rule_name' => $rule['name'] ?? '',
                    'rule_expression' => $rule['expression'] ?? '',
                    'rule_action' => $rule['action'] ?? '',
                    'is_enabled' => true,
                ]);
            }

            return [
                'success' => true,
                'message' => __('规则推送成功'),
                'data' => ['rules_count' => count($rules)],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    /**
     * 检测 CDN 能力
     */
    private function handleCheckCapability(?Domain $domain): array
    {
        if (!$domain) {
            return [
                'success' => true,
                'message' => __('无 CDN 配置'),
                'data' => [],
                'supports_api_purge' => false,
                'cdn_enabled' => false,
            ];
        }

        $adapter = $this->adapterResolver->getAdapter($domain->getData(Domain::schema_fields_ADAPTER));
        
        // 检测适配器能力
        $supportsApiPurge = false;
        if ($adapter) {
            // 检查适配器是否实现了清理方法
            $supportsApiPurge = method_exists($adapter, 'purgeEverything');
        }

        return [
            'success' => true,
            'message' => __('CDN 能力检测完成'),
            'data' => [
                'adapter' => $domain->getData(Domain::schema_fields_ADAPTER),
                'domain' => $domain->getData(Domain::schema_fields_DOMAIN_NAME),
            ],
            'supports_api_purge' => $supportsApiPurge,
            'cdn_enabled' => true,
        ];
    }
}

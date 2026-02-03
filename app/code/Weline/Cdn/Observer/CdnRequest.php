<?php

declare(strict_types=1);

namespace Weline\Cdn\Observer;

use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Cdn\Service\CachePurger;
use Weline\Cdn\Service\RuleManager;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

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
        $action = $event->getData('action');
        $websiteId = $event->getData('website_id');
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
            $domainConfig = $this->resolveDomain($websiteId, $domain);
            
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
     * @param int|null $websiteId 网站ID
     * @param string|null $domain 域名
     * @return Domain|null
     */
    private function resolveDomain(?int $websiteId, ?string $domain): ?Domain
    {
        // 如果直接指定了域名
        if ($domain) {
            $domainModel = clone $this->domainModel;
            $domainModel->reset()
                ->where(Domain::fields_DOMAIN_NAME, $domain)
                ->where(Domain::fields_IS_ENABLED, 1)
                ->find()
                ->fetch();
            
            if ($domainModel->getData(Domain::fields_DOMAIN_ID)) {
                return $domainModel;
            }
        }

        // 如果指定了网站ID，获取网站关联的域名
        if ($websiteId) {
            // 这里需要通过网站ID获取关联的CDN域名
            // 假设有 website_id 字段关联
            $domainModel = clone $this->domainModel;
            $domainModel->reset()
                ->where('website_id', $websiteId)
                ->where(Domain::fields_IS_ENABLED, 1)
                ->find()
                ->fetch();
            
            if ($domainModel->getData(Domain::fields_DOMAIN_ID)) {
                return $domainModel;
            }
        }

        // 尝试获取默认域名
        $domainModel = clone $this->domainModel;
        $domainModel->reset()
            ->where(Domain::fields_IS_ENABLED, 1)
            ->order(Domain::fields_DOMAIN_ID, 'ASC')
            ->limit(1)
            ->find()
            ->fetch();
        
        return $domainModel->getData(Domain::fields_DOMAIN_ID) ? $domainModel : null;
    }

    /**
     * 处理全站缓存清理
     */
    private function handlePurgeAll(Domain $domain): array
    {
        $result = $this->cachePurger->purge(
            $domain->getData(Domain::fields_DOMAIN_ID),
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
            $domain->getData(Domain::fields_DOMAIN_ID),
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
                    'domain_id' => $domain->getData(Domain::fields_DOMAIN_ID),
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

        $adapter = $this->adapterResolver->getAdapter($domain->getData(Domain::fields_ADAPTER));
        
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
                'adapter' => $domain->getData(Domain::fields_ADAPTER),
                'domain' => $domain->getData(Domain::fields_DOMAIN_NAME),
            ],
            'supports_api_purge' => $supportsApiPurge,
            'cdn_enabled' => true,
        ];
    }
}

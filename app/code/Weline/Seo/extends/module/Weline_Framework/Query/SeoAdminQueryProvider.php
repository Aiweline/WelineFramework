<?php

declare(strict_types=1);

namespace Weline\Seo\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Seo\Service\Admin\SeoAdminAccountService;
use Weline\Seo\Service\Admin\SeoAdminEmbedService;
use Weline\Seo\Service\Admin\SeoAdminSitemapService;

final class SeoAdminQueryProvider implements QueryProviderInterface
{
    private const OPERATION_ACL = [
        'listSitemapUrls' => 'Weline_Seo::sitemap_management',
        'updateSitemapUrl' => 'Weline_Seo::sitemap_management',
        'deleteSitemapUrls' => 'Weline_Seo::sitemap_management',
        'syncSitemapUrls' => 'Weline_Seo::sitemap_management',
        'generateSitemaps' => 'Weline_Seo::sitemap_management',
        'submitSitemaps' => 'Weline_Seo::sitemap_management',
        'saveAccount' => 'Weline_Seo::seo_account',
        'syncAccountStats' => 'Weline_Seo::seo_account',
        'saveWebsiteBindings' => 'Weline_Seo::website_account',
        'saveWebsiteConfig' => 'Weline_Seo::website_account',
        'unbindWebsite' => 'Weline_Seo::website_account',
        'saveEmbedSubject' => 'Weline_Seo::seo_embed_save',
        'deleteEmbedSubject' => 'Weline_Seo::seo_embed_delete',
        'refreshEmbedSuggestion' => 'Weline_Seo::seo_embed_refresh',
    ];

    public function __construct(
        private readonly SeoAdminSitemapService $sitemaps,
        private readonly SeoAdminAccountService $accounts,
        private readonly SeoAdminEmbedService $embeds,
        private readonly SessionFactory $sessionFactory,
    ) {
    }

    public function getProviderName(): string
    {
        return 'seo_admin';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        $this->assertBackendSession();
        return match ($operation) {
            'listSitemapUrls' => $this->sitemaps->listSitemapUrls($params),
            'updateSitemapUrl' => $this->sitemaps->updateSitemapUrl($params),
            'deleteSitemapUrls' => $this->sitemaps->deleteSitemapUrls($params),
            'syncSitemapUrls' => $this->sitemaps->syncSitemapUrls($params),
            'generateSitemaps' => $this->sitemaps->generateSitemaps($params),
            'submitSitemaps' => $this->sitemaps->submitSitemaps($params),
            'saveAccount' => $this->accounts->saveAccount($params),
            'syncAccountStats' => $this->accounts->syncAccountStats($params),
            'saveWebsiteBindings' => $this->accounts->saveWebsiteBindings($params),
            'saveWebsiteConfig' => $this->accounts->saveWebsiteConfig($params),
            'unbindWebsite' => $this->accounts->unbindWebsite($params),
            'saveEmbedSubject' => $this->embeds->saveSubject($params),
            'deleteEmbedSubject' => $this->embeds->deleteSubject($params),
            'refreshEmbedSuggestion' => $this->embeds->refreshSuggestion($params),
            default => throw new \InvalidArgumentException((string)__('SEO 后台查询器不支持的操作：%{1}', $operation)),
        };
    }

    public function getDescriptor(): array
    {
        $operations = [
            $this->operation('listSitemapUrls', __('分页列出指定站点的 Sitemap URL。'), [
                'website_id' => ['type' => 'int', 'required' => true, 'min' => 0],
                'module' => ['type' => 'string', 'max_length' => 100],
                'locale' => ['type' => 'string', 'max_length' => 32],
                'status' => ['type' => 'string', 'max_length' => 8],
                'keyword' => ['type' => 'string', 'max_length' => 500],
                'page' => ['type' => 'int', 'min' => 1],
                'page_size' => ['type' => 'int', 'min' => 1, 'max' => 100],
            ], 'read', 1),
            $this->operation('updateSitemapUrl', __('更新单条 Sitemap URL 的状态、更新频率或优先级。'), [
                'url_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                'status' => ['type' => 'int', 'min' => 0, 'max' => 1],
                'changefreq' => ['type' => 'string', 'max_length' => 20],
                'priority' => ['type' => 'string', 'max_length' => 10],
            ]),
            $this->operation('deleteSitemapUrls', __('删除选定的 Sitemap URL；Provider 同步可能重建它们。'), [
                'url_ids' => ['type' => 'list', 'required' => true, 'max_items' => 200],
            ]),
            $this->operation('syncSitemapUrls', __('同步选定站点的 Sitemap Provider URL 快照。'), [
                'website_ids' => ['type' => 'list', 'max_items' => 500],
                'all_sites' => ['type' => 'bool'],
                'module' => ['type' => 'string', 'max_length' => 100],
            ]),
            $this->operation('generateSitemaps', __('为选定站点生成 canonical 与所需平台 Sitemap。'), [
                'website_ids' => ['type' => 'list', 'max_items' => 500],
                'all_sites' => ['type' => 'bool'],
            ]),
            $this->operation('submitSitemaps', __('向选定站点已绑定的平台提交 canonical Sitemap。'), [
                'website_ids' => ['type' => 'list', 'max_items' => 500],
                'all_sites' => ['type' => 'bool'],
            ]),
            $this->operation('saveAccount', __('保存脱敏的 SEO 平台账户配置。'), [
                'account_id' => ['type' => 'int', 'min' => 0],
                'name' => ['type' => 'string', 'required' => true, 'max_length' => 190],
                'platform' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                'scope' => ['type' => 'string', 'max_length' => 100],
                'description' => ['type' => 'string', 'max_length' => 2000],
                'is_active' => ['type' => 'int', 'min' => 0, 'max' => 1],
                'enable_cron_push_urls' => ['type' => 'bool'],
                'enable_cron_sitemap' => ['type' => 'bool'],
                'config' => ['type' => 'map', 'max_items' => 100],
                'config_json' => ['type' => 'string', 'max_length' => 50000],
            ]),
            $this->operation('syncAccountStats', __('同步一个 SEO 账户所绑定站点的统计数据。'), [
                'account_id' => ['type' => 'int', 'required' => true, 'min' => 1],
            ]),
            $this->operation('saveWebsiteBindings', __('保存账户到站点或站点到账户的绑定关系。'), [
                'account_id' => ['type' => 'int', 'min' => 1],
                'website_id' => ['type' => 'int', 'min' => 0],
                'website_ids' => ['type' => 'list', 'max_items' => 500],
                'account_ids' => ['type' => 'list', 'max_items' => 100],
                'configs' => ['type' => 'map', 'max_items' => 100],
            ]),
            $this->operation('saveWebsiteConfig', __('保存一个站点账户绑定的 Sitemap 配置。'), [
                'account_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                'website_id' => ['type' => 'int', 'required' => true, 'min' => 0],
                'config' => ['type' => 'map', 'max_items' => 20],
            ]),
            $this->operation('unbindWebsite', __('解除一个站点与 SEO 账户的绑定。'), [
                'account_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                'website_id' => ['type' => 'int', 'required' => true, 'min' => 0],
            ]),
            $this->operation('saveEmbedSubject', __('保存嵌入式 SEO 主体。'), [
                'subject_id' => ['type' => 'int', 'min' => 0],
                'title' => ['type' => 'string', 'required' => true, 'max_length' => 500],
                'url' => ['type' => 'string', 'max_length' => 2000],
                'description' => ['type' => 'string', 'max_length' => 5000],
                'scope' => ['type' => 'string', 'max_length' => 100],
                'module' => ['type' => 'string', 'max_length' => 150],
                'subject_type' => ['type' => 'string', 'max_length' => 64],
                'subject_entity_id' => ['type' => 'int', 'min' => 0],
                'status' => ['type' => 'int', 'min' => 0, 'max' => 1],
                'locale' => ['type' => 'string', 'max_length' => 32],
            ]),
            $this->operation('deleteEmbedSubject', __('删除嵌入式 SEO 主体。'), [
                'subject_id' => ['type' => 'int', 'required' => true, 'min' => 1],
            ]),
            $this->operation('refreshEmbedSuggestion', __('刷新嵌入式 SEO AI 建议。'), [
                'subject_id' => ['type' => 'int', 'required' => true, 'min' => 1],
            ]),
        ];
        return [
            'provider' => 'seo_admin',
            'name' => __('SEO 后台管理查询器'),
            'description' => __('通过 Weline.Api 管理 Sitemap、SEO 账户和站点绑定。'),
            'module' => 'Weline_Seo',
            'operations' => $operations,
        ];
    }

    /** @param array<string,array<string,mixed>> $params */
    private function operation(string $name, string $description, array $params, string $mode = 'write', int $cost = 3): array
    {
        $normalizedParams = [];
        foreach ($params as $paramName => $definition) {
            $normalizedParams[] = ['name' => (string)$paramName] + $definition;
        }
        return [
            'name' => $name,
            'description' => $description,
            'frontend' => true,
            'backend' => true,
            'external' => false,
            'auth' => 'backend',
            'mode' => $mode,
            'graph' => false,
            'cost' => $cost,
            'backend_acl' => ['kind' => 'source', 'source_id' => self::OPERATION_ACL[$name]],
            'params' => $normalizedParams,
            'returns' => ['type' => 'array'],
        ];
    }

    private function assertBackendSession(): void
    {
        $session = $this->sessionFactory->createBackendSession();
        $session->start();
        if (!$session->isLoggedIn() || (int)($session->getUserId() ?? 0) <= 0) {
            throw new \RuntimeException((string)__('请先登录后台'));
        }
    }
}

<?php

declare(strict_types=1);

namespace Weline\Social\Controller\Backend;

use Weline\Admin\Api\Controller\BaseController;
use Weline\Framework\Acl\Acl;
use Weline\Social\Service\SocialAccountService;
use Weline\Social\Service\SocialCreativeService;
use Weline\Social\Service\SocialPlatformIconService;
use Weline\Social\Service\SocialPlatformRegistry;
use Weline\Social\Service\SocialPublishService;
use Weline\Social\Service\SocialWebsiteAccountService;

#[Acl('Weline_Social::social', '融媒体管理', 'mdi mdi-share-variant-outline', '管理社媒平台账户、AI 创意和多平台发布', 'Weline_Backend::marketing_group')]
class Social extends BaseController
{
    public function __construct(
        private readonly SocialPlatformRegistry $registry,
        private readonly SocialAccountService $accountService,
        private readonly SocialCreativeService $creativeService,
        private readonly SocialPublishService $publishService,
        private readonly SocialPlatformIconService $iconService,
        private readonly SocialWebsiteAccountService $websiteAccountService
    ) {
    }

    #[Acl('Weline_Social::social_index', '查看融媒体管理', 'mdi mdi-view-dashboard-outline', '查看融媒体管理页面')]
    public function index(): string
    {
        $platforms = $this->iconService->enrichDefinitions($this->registry->listDefinitions());
        $accounts = $this->accountService->listAccounts();
        $websites = $this->websiteAccountService->listWebsites();
        $socialScopes = $this->websiteAccountService->listScopes();
        $websiteRelations = $this->websiteAccountService->listRelations();
        $scopeRelations = $this->websiteAccountService->listScopeRelations();
        $drafts = $this->creativeService->listRecentDrafts(8);
        $batches = $this->publishService->listRecentBatches(8);

        $families = [];
        foreach ($platforms as $platform) {
            $family = (string)($platform['family'] ?? 'social');
            $families[$family] = ($families[$family] ?? 0) + 1;
        }

        $this->assign('page_title', (string)__('融媒体管理'));
        $this->assign('platforms', $platforms);
        $this->assign('families', $families);
        $this->assign('accounts', $accounts);
        $this->assign('websites', $websites);
        $this->assign('social_scopes', $socialScopes);
        $this->assign('website_relations', $websiteRelations);
        $this->assign('scope_relations', $scopeRelations);
        $this->assign('drafts', $drafts);
        $this->assign('batches', $batches);
        $this->assign('warnings', $this->registry->getWarnings());
        $this->assign('query_provider', 'welineSocial');
        $this->assign('fake_smoke_url', '/weline_social/frontend/social/smoke?fake=1&relation=1&no_publish=1');

        return $this->fetchBase();
    }
}

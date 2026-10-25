<?php

declare(strict_types=1);

namespace Weline\Social\Controller\Backend;

use Weline\Admin\Api\Controller\BaseController;
use Weline\Framework\Acl\Acl;
use Weline\Social\Model\SocialCreativeDraft;
use Weline\Social\Model\SocialExtensionDevice;
use Weline\Social\Service\SocialAccountService;
use Weline\Social\Service\SocialCreativeService;
use Weline\Social\Service\SocialExtensionJobService;
use Weline\Social\Service\SocialPlatformCredentialGuide;
use Weline\Social\Service\SocialPlatformIconService;
use Weline\Social\Service\SocialPlatformRegistry;
use Weline\Social\Service\SocialPublishService;
use Weline\Social\Service\SocialReviewService;
use Weline\Social\Service\SocialWebsiteAccountService;

#[Acl('Weline_Social::social', '融媒体管理', 'share', '管理社媒平台账户、AI 创意和多平台发布', 'Weline_Backend::marketing_group')]
class Social extends BaseController
{
    public function __construct(
        private readonly SocialPlatformRegistry $registry,
        private readonly SocialAccountService $accountService,
        private readonly SocialCreativeService $creativeService,
        private readonly SocialPublishService $publishService,
        private readonly SocialPlatformIconService $iconService,
        private readonly SocialWebsiteAccountService $websiteAccountService,
        private readonly SocialPlatformCredentialGuide $credentialGuide,
        private readonly SocialExtensionJobService $extensionJobService,
        private readonly SocialReviewService $reviewService,
        private readonly SocialExtensionDevice $extensionDevice,
    ) {
    }

    #[Acl('Weline_Social::social_index', '查看融媒体管理', 'grid', '查看融媒体管理页面')]
    public function index(): string
    {
        try {
            (new \Weline\Social\Service\SocialExtensionPackageService())->ensurePackage();
        } catch (\Throwable) {
        }

        // OffCanvas / 网站编辑 iframe 内嵌时用 blank，避免重复套后台顶栏/侧栏。
        $socialEmbed = $this->request->isIframe()
            || (string)$this->request->getParam('isIframe') !== '';
        if ($socialEmbed) {
            $this->layoutType = 'default.blank';
        }

        $platforms = $this->iconService->enrichDefinitions($this->registry->listDefinitions());
        $accounts = $this->accountService->listAccounts();
        $websites = $this->websiteAccountService->listWebsites();
        $socialScopes = $this->websiteAccountService->listScopes();
        $initialScope = $this->websiteAccountService->resolveAdminScopeSelection(
            \is_array($this->request->getParams()) ? $this->request->getParams() : [],
            $socialScopes,
        );
        $websiteRelations = $this->websiteAccountService->listRelations();
        $scopeRelations = $this->websiteAccountService->listScopeRelations();
        $drafts = $this->creativeService->listRecentDrafts(8);
        $batches = $this->publishService->listRecentBatches(8);
        foreach ($batches as $batchIndex => $batchRow) {
            $batchId = (int)($batchRow['batch_id'] ?? 0);
            if ($batchId < 1) {
                continue;
            }
            try {
                $batchStatus = $this->publishService->getBatchStatus($batchId);
            } catch (\Throwable) {
                $batchStatus = [];
            }
            $batches[$batchIndex]['targets'] = \is_array($batchStatus['targets'] ?? null) ? $batchStatus['targets'] : [];
            $batches[$batchIndex]['content_kind'] = (string)($batchStatus['content_kind'] ?? ($batchRow['content_kind'] ?? ''));
        }
        $scopeWebsiteId = (int)($initialScope['website_id'] ?? $initialScope['scope_id'] ?? 0);
        try {
            $extensionPendingCount = \count($this->extensionJobService->listPending($scopeWebsiteId, 100));
        } catch (\Throwable) {
            $extensionPendingCount = 0;
        }
        try {
            $reviewQueue = $this->reviewService->listPending($scopeWebsiteId, 20);
        } catch (\Throwable) {
            $reviewQueue = [];
        }
        foreach ($reviewQueue as &$review) {
            if (!\is_array($review)) {
                continue;
            }
            try {
                $draft = $this->creativeService->getDraft((int)($review['draft_id'] ?? 0));
                if ($draft instanceof SocialCreativeDraft) {
                    $assets = \json_decode((string)$draft->getData(SocialCreativeDraft::schema_fields_ASSETS_JSON), true);
                    $review['assets'] = \is_array($assets) ? $assets : [];
                    $kinds = \array_map(
                        static fn(mixed $asset): string => \is_array($asset) ? \strtolower((string)($asset['kind'] ?? '')) : '',
                        $review['assets']
                    );
                    $review['content_kind'] = \in_array('video', $kinds, true)
                        ? 'video'
                        : (\in_array('image', $kinds, true) ? 'image' : 'text');
                }
            } catch (\Throwable) {
                // 审片列表不因素材补充失败而整体不可用。
            }
        }
        unset($review);
        $extensionDevices = $this->listExtensionDevices($scopeWebsiteId);

        $families = [];
        foreach ($platforms as $platform) {
            $family = (string)($platform['family'] ?? 'social');
            $families[$family] = ($families[$family] ?? 0) + 1;
        }

        $pageTitle = (string)__('融媒体管理');
        $this->assign('title', $pageTitle);
        $this->assign('page_title', $pageTitle);
        $this->assign('platforms', $platforms);
        $this->assign('families', $families);
        $this->assign('accounts', $accounts);
        $this->assign('websites', $websites);
        $this->assign('social_scopes', $socialScopes);
        $this->assign('initial_scope', $initialScope);

        $this->assign('website_relations', $websiteRelations);
        $this->assign('scope_relations', $scopeRelations);
        $this->assign('drafts', $drafts);
        $this->assign('batches', $batches);
        $this->assign('extension_pending_count', $extensionPendingCount);
        $this->assign('extension_devices', $extensionDevices);
        $this->assign('review_queue', $reviewQueue);
        $this->assign('warnings', $this->registry->getWarnings());
        $this->assign('query_provider', 'welineSocial');
        $this->assign('fake_smoke_url', '/weline_social/frontend/social/smoke?fake=1&relation=1&no_publish=1');
        $this->assign('platform_credential_guides', $this->credentialGuide->getAllGuides());
        $this->assign('platform_guide_keys', $this->credentialGuide->getGuideKeys());
        $this->assign('social_embed', $socialEmbed);
        $this->assign('scope_locked', $socialEmbed);

        return (string) $this->fetch();
    }

    /**
     * 设备列表只暴露页面需要的字段，绝不返回 token_hash。
     *
     * @return array<int, array<string, mixed>>
     */
    private function listExtensionDevices(int $websiteId): array
    {
        try {
            $rows = $this->extensionDevice->reset()
                ->where(SocialExtensionDevice::schema_fields_STATUS, SocialExtensionDevice::STATUS_ACTIVE)
                ->order(SocialExtensionDevice::schema_fields_LAST_SEEN_AT, 'DESC')
                ->limit(20)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        $devices = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $rowWebsiteId = (int)($row['website_id'] ?? 0);
            if ($websiteId > 0 && $rowWebsiteId > 0 && $rowWebsiteId !== $websiteId) {
                continue;
            }
            $devices[] = [
                'device_id' => (int)($row[SocialExtensionDevice::schema_fields_ID] ?? 0),
                'website_id' => $rowWebsiteId,
                'name' => (string)($row[SocialExtensionDevice::schema_fields_NAME] ?? ''),
                'version' => (string)($row[SocialExtensionDevice::schema_fields_VERSION] ?? ''),
                'status' => (string)($row[SocialExtensionDevice::schema_fields_STATUS] ?? ''),
                'last_seen_at' => (string)($row[SocialExtensionDevice::schema_fields_LAST_SEEN_AT] ?? ''),
            ];
        }

        return $devices;
    }
}

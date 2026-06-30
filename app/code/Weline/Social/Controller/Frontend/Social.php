<?php

declare(strict_types=1);

namespace Weline\Social\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Social\Service\SocialAccountService;
use Weline\Social\Service\SocialCreativeService;
use Weline\Social\Service\SocialPublishService;
use Weline\Social\Service\SocialWebsiteAccountService;

class Social extends FrontendController
{
    public function __construct(
        private readonly SocialAccountService $accountService,
        private readonly SocialCreativeService $creativeService,
        private readonly SocialPublishService $publishService,
        private readonly SocialWebsiteAccountService $websiteAccountService
    ) {
    }

    public function smoke(): string
    {
        $prompt = \trim((string)$this->request->getParam('prompt', ''));
        if ($prompt === '') {
            $prompt = (string)__('浏览器 fake 模式一键发布验证');
        }

        $accountResult = $this->accountService->saveCredentialAccount([
            'platform_code' => 'fake_browser',
            'account_name' => 'Browser Fake Account',
            'auth_mode' => 'fake',
            'profile_url' => 'https://example.com/weline-social-fake',
            'widget_enabled' => true,
            'publish_enabled' => true,
            'sort_order' => 10,
            'credentials' => ['fake_token' => 'browser-smoke-token'],
            'remote_account_name' => 'Browser Fake Account',
        ]);
        $account = \is_array($accountResult['account'] ?? null) ? $accountResult['account'] : [];
        $websites = $this->websiteAccountService->listWebsites();
        $website = \is_array($websites[0] ?? null) ? $websites[0] : [];
        $relationResult = ['success' => false, 'message' => (string)__('暂无站点，无法验证站点社媒账户关系。')];
        $resolveResult = ['success' => false, 'accounts' => []];
        if ((int)($website['website_id'] ?? 0) > 0 && (int)($account['account_id'] ?? 0) > 0) {
            $relationResult = $this->websiteAccountService->saveWebsiteAccountDefaults([
                'website_id' => (int)$website['website_id'],
                'account_ids' => [(int)$account['account_id']],
            ]);
            $resolveResult = $this->websiteAccountService->getWebsiteDefaultAccounts((int)$website['website_id'], true);
        }

        $noPublish = $this->toBool($this->request->getParam('no_publish', false));
        $creativeResult = ['success' => true, 'draft' => []];
        $batchResult = ['success' => true, 'batch' => []];
        if (!$noPublish) {
            $creativeResult = $this->creativeService->generateCreative([
                'fake_mode' => true,
                'use_ai' => false,
                'title' => (string)__('Fake 浏览器发布草稿'),
                'prompt' => $prompt,
                'platforms' => ['fake_browser'],
            ]);
            $draft = \is_array($creativeResult['draft'] ?? null) ? $creativeResult['draft'] : [];
            $batchResult = $this->publishService->createPublishBatch([
                'fake_mode' => true,
                'title' => (string)__('Fake 浏览器发布批次'),
                'draft_id' => (int)($draft['draft_id'] ?? 0),
                'website_id' => (int)($website['website_id'] ?? 0),
                'content_kind' => 'news',
            ]);
        }

        $this->assign('page_title', (string)__('Weline Social Fake Smoke'));
        $this->assign('account_result', $accountResult);
        $this->assign('website', $website);
        $this->assign('relation_result', $relationResult);
        $this->assign('resolve_result', $resolveResult);
        $this->assign('no_publish', $noPublish);
        $this->assign('creative_result', $creativeResult);
        $this->assign('batch_result', $batchResult);

        return $this->fetch('Weline_Social::templates/Frontend/Social/smoke.phtml');
    }

    private function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        $normalized = \strtolower(\trim((string)$value));
        return \in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}

<?php

declare(strict_types=1);

namespace Weline\Seo\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendPageController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Service\SeoWebsiteDirectory;

#[Acl('Weline_Seo::seo_optimization', 'AI优化控制台', 'sparkles', 'AI优化控制台', 'Weline_Backend::seo_group')]
final class Optimization extends BackendPageController
{
    #[Acl('Weline_Seo::seo_optimization_index', '查看AI优化控制台', 'sparkles', '查看AI优化控制台')]
    public function index(): string
    {
        $this->assign('title', __('AI 优化控制台'));

        try {
            /** @var SeoWebsiteDirectory $websiteDirectory */
            $websiteDirectory = ObjectManager::getInstance(SeoWebsiteDirectory::class);
            $websites = $websiteDirectory->listWebsites();
        } catch (\Throwable) {
            $websites = [];
        }

        $selectedWebsiteId = null;
        $rawWebsiteId = $this->request->getParam('website_id');
        if ($rawWebsiteId !== null && $rawWebsiteId !== '' && \preg_match('/^(?:0|[1-9]\d*)$/', (string)$rawWebsiteId) === 1) {
            $candidate = (int)$rawWebsiteId;
            foreach ($websites as $website) {
                if (!\is_array($website)) {
                    continue;
                }
                $websiteId = (int)($website['website_id'] ?? $website['id'] ?? -1);
                if ($websiteId === $candidate) {
                    $selectedWebsiteId = $candidate;
                    break;
                }
            }
        }
        if ($selectedWebsiteId === null && $websites !== []) {
            $first = $websites[0];
            if (\is_array($first)) {
                $selectedWebsiteId = (int)($first['website_id'] ?? $first['id'] ?? -1);
                if ($selectedWebsiteId < 0) {
                    $selectedWebsiteId = null;
                }
            }
        }

        $this->assign('websites', $websites);
        $this->assign('selected_website_id', $selectedWebsiteId);

        return $this->fetch('Weline_Seo::templates/Backend/Optimization/index.phtml');
    }
}

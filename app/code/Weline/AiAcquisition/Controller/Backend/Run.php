<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Controller\Backend;

use Weline\AiAcquisition\Model\LeadRun;
use Weline\AiAcquisition\Model\PromptProfile;
use Weline\AiAcquisition\Service\Catalog\ContinentCatalog;
use Weline\AiAcquisition\Service\Model\ModelCatalogService;
use Weline\AiAcquisition\Service\Run\RunService;
use Weline\AiAcquisition\Service\Site\WebsiteProfileService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_AiAcquisition::run_list', 'AI 获客智能体', 'search', 'AI 获客智能体运行与浏览器桥', 'Weline_Backend::ai_ops_group')]
class Run extends BackendController
{
    #[Acl('Weline_AiAcquisition::run_index', '查看获客智能体', 'list', '查看获客智能体运行记录')]
    public function index(): string
    {
        $models = [];
        $runs = [];
        $profiles = [];
        $websites = [];
        $websiteProfile = [];
        $requestedWebsiteId = $this->request->getGet('website_id', null);
        $selectedWebsiteId = $requestedWebsiteId === null ? null : (int)$requestedWebsiteId;
        $defaultModel = '';

        try {
            /** @var ModelCatalogService $catalog */
            $catalog = ObjectManager::getInstance(ModelCatalogService::class);
            $models = $catalog->listTextModels();
            $default = $catalog->resolve(null);
            $defaultModel = (string)($default['model_code'] ?? '');
            if ($defaultModel === '' && $models !== []) {
                $defaultModel = (string)($models[0]['model_code'] ?? '');
            }
        } catch (\Throwable) {
            $models = [];
        }

        try {
            /** @var RunService $runService */
            $runService = ObjectManager::getInstance(RunService::class);
            $runs = $runService->listing([], 1, 20)['items'] ?? [];
        } catch (\Throwable) {
            $runs = [];
        }

        try {
            /** @var PromptProfile $prompt */
            $prompt = ObjectManager::getInstance(PromptProfile::class);
            $rows = $prompt->reset()->clearQuery()->order(PromptProfile::schema_fields_IS_DEFAULT, 'DESC')->select()->fetchArray();
            $profiles = is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            $profiles = [];
        }

        try {
            /** @var WebsiteProfileService $profileService */
            $profileService = ObjectManager::getInstance(WebsiteProfileService::class);
            $websites = $profileService->listWebsites();
            if ($selectedWebsiteId === null) {
                $selectedWebsiteId = (int)($websites[0]['website_id'] ?? -1);
                foreach ($websites as $website) {
                    if ((int)($website['website_id'] ?? -1) === 0) {
                        $selectedWebsiteId = 0;
                        break;
                    }
                }
            }
            if ($selectedWebsiteId !== null && $selectedWebsiteId >= 0) {
                $websiteProfile = $profileService->preview($selectedWebsiteId);
            }
        } catch (\Throwable) {
            $websites = [];
            $websiteProfile = [];
        }

        $selectedSiteLabel = '';
        foreach ($websites as $website) {
            if ((int)($website['website_id'] ?? -1) === (int)($selectedWebsiteId ?? -1)) {
                $selectedSiteLabel = (string)($website['name'] ?? '');
                break;
            }
        }

        $this->assign('websites', $websites);
        $this->assign('websiteProfile', $websiteProfile);
        $this->assign('selectedWebsiteId', $selectedWebsiteId ?? -1);
        $this->assign('selectedSiteLabel', $selectedSiteLabel);
        $this->assign('models', $models);
        $this->assign('runs', $runs);
        $this->assign('profiles', $profiles);
        $this->assign('defaultModel', $defaultModel);
        $this->assign('statuses', LeadRun::STATUSES);
        $this->assign('continents', ContinentCatalog::all());

        return $this->fetch();
    }
}

<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Controller\Backend;

use Weline\AiAcquisition\Model\Continent;
use Weline\AiAcquisition\Model\Lead as LeadModel;
use Weline\AiAcquisition\Model\PromptProfile;
use Weline\AiAcquisition\Service\Catalog\ContinentCatalog;
use Weline\AiAcquisition\Service\Site\WebsiteProfileService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_AiAcquisition::lead_list', 'AI 获客线索', 'users', 'AI 获客客户线索库', 'Weline_Backend::ai_ops_group')]
class Lead extends BackendController
{
    #[Acl('Weline_AiAcquisition::lead_index', '查看客户线索', 'list', '查看 AI 获客客户线索')]
    public function index(): string
    {
        $continents = [];
        try {
            /** @var Continent $continent */
            $continent = ObjectManager::getInstance(Continent::class);
            $rows = $continent->reset()->clearQuery()->order(Continent::schema_fields_SORT, 'ASC')->select()->fetchArray();
            $continents = is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            $continents = array_map(
                static fn(array $row): array => [
                    Continent::schema_fields_CODE => $row['code'],
                    Continent::schema_fields_NAME_ZH => $row['name_zh'],
                ],
                ContinentCatalog::all(),
            );
        }

        $profiles = [];
        try {
            /** @var PromptProfile $prompt */
            $prompt = ObjectManager::getInstance(PromptProfile::class);
            $rows = $prompt->reset()->clearQuery()->order(PromptProfile::schema_fields_IS_DEFAULT, 'DESC')->select()->fetchArray();
            $profiles = is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            $profiles = [];
        }

        $websites = [];
        try {
            /** @var WebsiteProfileService $profileService */
            $profileService = ObjectManager::getInstance(WebsiteProfileService::class);
            $websites = $profileService->listWebsites();
        } catch (\Throwable) {
            $websites = [];
        }
        $selectedWebsiteId = $this->request->getGet('website_id', null);
        $selectedWebsiteId = $selectedWebsiteId === null ? null : (int)$selectedWebsiteId;

        $this->assign('continents', $continents);
        $this->assign('websites', $websites);
        $this->assign('selectedWebsiteId', $selectedWebsiteId);
        $this->assign('statuses', LeadModel::STATUSES);
        $this->assign('profiles', $profiles);
        $this->assign('sourcePlatforms', [
            'web' => '网页搜索',
            'linkedin' => 'LinkedIn',
            'facebook' => 'Facebook',
            'reddit' => 'Reddit',
            'forum' => '论坛/社区',
            'directory' => '目录/名录',
            'manual' => '手动录入',
        ]);

        return $this->fetch();
    }
}

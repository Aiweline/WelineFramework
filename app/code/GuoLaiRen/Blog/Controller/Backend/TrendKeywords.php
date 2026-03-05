<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 趋势词分析（Top10 可视化）
 */

namespace GuoLaiRen\Blog\Controller\Backend;

use GuoLaiRen\Blog\Model\TrendProfile;
use GuoLaiRen\Blog\Service\GoogleTrendsService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;

#[Acl('GuoLaiRen_Blog::trend_keywords', '趋势词分析', 'mdi mdi-chart-bar', '趋势词 Top10 分析', 'GuoLaiRen_Blog::blog_menu')]
class TrendKeywords extends BackendController
{
    #[Acl('GuoLaiRen_Blog::trend_keywords_index', '查看趋势词', 'mdi mdi-view-list', '查看趋势词分析', 'GuoLaiRen_Blog::trend_keywords')]
    public function index(): string
    {
        $profileId = (int)$this->request->getGet('profile_id', 0);
        $profiles = $this->getProfileOptions();
        $this->assign('profiles', $profiles);
        $this->assign('selected_profile_id', $profileId);
        $this->assign('top10', []);
        $this->assign('error', '');

        if ($profileId > 0) {
            /** @var TrendProfile $profileModel */
            $profileModel = ObjectManager::getInstance(TrendProfile::class);
            $profileModel->clear()->load($profileId);
            if (!$profileModel->getId()) {
                $this->assign('error', __('画像不存在'));
            } else {
                $keywords = $profileModel->getKeywordsArray();
                if (empty($keywords)) {
                    $this->assign('error', __('该画像暂无关键词'));
                } else {
                    try {
                        /** @var GoogleTrendsService $service */
                        $service = ObjectManager::getInstance(GoogleTrendsService::class);
                        $trends = $service->fetchTrends($keywords, [
                            'region' => \GuoLaiRen\Blog\Model\TrendsConfig::get(\GuoLaiRen\Blog\Model\TrendsConfig::KEY_REGION, 'US'),
                            'date' => 'today 12-m',
                        ]);
                        $top10 = $service->topByValue($trends, 10);
                        $this->assign('top10', $top10);
                        $this->assign('profile_name', $profileModel->getData(TrendProfile::schema_fields_NAME));
                    } catch (\Throwable $e) {
                        $this->assign('error', $e->getMessage());
                    }
                }
            }
        }

        $this->assign('page_title', __('趋势词分析'));
        $this->assign('breadcrumb_parent', __('博客管理'));
        $this->assign('breadcrumb_current', __('趋势词分析'));

        return $this->fetch('index');
    }

    private function getProfileOptions(): array
    {
        /** @var TrendProfile $profile */
        $profile = ObjectManager::getInstance(TrendProfile::class);
        $items = $profile->clear()
            ->where(TrendProfile::schema_fields_IS_ACTIVE, 1)
            ->order(TrendProfile::schema_fields_SORT, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        $out = [['profile_id' => 0, 'name' => __('请选择画像')]];
        foreach ($items as $p) {
            $out[] = [
                'profile_id' => (int)$p->getData(TrendProfile::schema_fields_ID),
                'name' => $p->getData(TrendProfile::schema_fields_NAME),
            ];
        }
        return $out;
    }
}

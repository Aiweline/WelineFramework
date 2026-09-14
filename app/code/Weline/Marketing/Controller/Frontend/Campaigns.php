<?php

declare(strict_types=1);

namespace Weline\Marketing\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\DateTime\Timezone;
use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Campaign\Campaign;

/** Storefront marketing campaigns hub: /marketing/campaign */
final class Campaigns extends FrontendController
{
    public function index(): string
    {
        $title = (string)__('活动');
        /** @var Campaign $campaign */
        $campaign = ObjectManager::getInstance(Campaign::class);
        $now = Timezone::utcNowSql();
        $items = $campaign->clear()
            ->where(Campaign::schema_fields_STATUS, Campaign::STATUS_ACTIVE)
            ->where(Campaign::schema_fields_START_DATE, $now, '<=')
            ->where(Campaign::schema_fields_END_DATE, $now, '>=')
            ->order(Campaign::schema_fields_START_DATE, 'DESC')
            ->select()
            ->fetchArray();

        $this->layoutType = 'default';
        $this->request->setGet('page_type', 'marketing_campaign');
        $this->request->setGet('theme_public_route', 'marketing/campaign');
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('campaign_page_heading', $title);
        $this->assign(
            'campaign_page_subtitle',
            (string)__('浏览当前进行中的营销活动与优惠说明。')
        );
        $this->assign('campaign_items', is_array($items) ? $items : []);
        $this->assign('showSidebar', false);

        return (string)$this->fetch('Weline_Marketing::templates/frontend/campaign/index.phtml');
    }
}

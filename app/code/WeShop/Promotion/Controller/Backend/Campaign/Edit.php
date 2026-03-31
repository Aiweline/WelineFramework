<?php

declare(strict_types=1);

namespace WeShop\Promotion\Controller\Backend\Campaign;

use Weline\Framework\App\Controller\BackendController;
use WeShop\Promotion\Repository\CampaignRepository;

/**
 * Campaign backend controller - Edit
 */
class Edit extends BackendController
{
    /**
     * Display campaign edit form
     */
    public function index(): string
    {
        $campaignId = (int) ($this->request->getParam('campaign_id') ?? 0);
        $isIframe = (bool) ($this->request->getParam('isIframe', false));

        $repository = new CampaignRepository();

        if ($campaignId > 0) {
            $campaign = $repository->findCampaignById($campaignId);
            if (!$campaign) {
                if ($isIframe) {
                    return $this->fetchJson([
                        'success' => false,
                        'message' => __('促销活动不存在'),
                    ]);
                }
                $this->redirect('/component/offcanvas/error', [
                    'msg' => __('促销活动不存在'),
                    'time' => 3,
                ]);
            }
            $this->assign('campaign', $campaign);
            $this->assign('title', __('编辑促销活动'));
        } else {
            $this->assign('campaign', null);
            $this->assign('title', __('添加促销活动'));
        }

        $this->assign('action', $this->_url->getBackendUrl('*/backend/campaign/save'));

        // 显式指定真实模板目录：view/backend/templates/Campaign/Edit/index.phtml
        return (string) $this->fetch('WeShop_Promotion::backend/templates/Campaign/Edit/index.phtml');
    }
}

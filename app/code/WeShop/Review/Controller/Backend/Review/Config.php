<?php

declare(strict_types=1);

namespace WeShop\Review\Controller\Backend\Review;

use WeShop\Review\Service\ReviewConfigService;
use WeShop\Review\Service\ReviewRatingOptionService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\MessageManager;

#[Acl('WeShop_Review::review_rating_options', 'Review rating options', 'mdi mdi-tune-variant', 'Manage review rating options', 'WeShop_Review::review_management')]
class Config extends BaseController
{
    public function __construct(
        private readonly ReviewRatingOptionService $ratingOptionService,
        private readonly ?ReviewConfigService $reviewConfigService = null
    ) {
    }

    #[Acl('WeShop_Review::review_rating_options_index', 'View review rating options', 'mdi mdi-tune', 'View review rating option settings')]
    public function index(): string
    {
        $reviewMode = $this->getReviewConfigService()->getReviewMode();

        $this->assign([
            'title' => (string) __('评价项配置'),
            'options' => $this->ratingOptionService->getAllOptions(),
            'reviewMode' => $reviewMode,
            'reviewModeLabel' => $this->getReviewConfigService()->getReviewModeLabel($reviewMode),
            'reviewModeOptions' => $this->getReviewConfigService()->getReviewModeOptions(),
            'reviewIndexUrl' => $this->_url->getBackendUrl('*/backend/review'),
            'reviewConfigUrl' => $this->_url->getBackendUrl('*/backend/review/config'),
            'reviewConfigSaveUrl' => $this->_url->getBackendUrl('*/backend/review/config/save'),
        ]);

        return (string) $this->fetchBase('WeShop_Review::templates/Backend/Review/Config/index.phtml');
    }

    #[Acl('WeShop_Review::review_rating_options_save', 'Save review rating options', 'mdi mdi-content-save', 'Save review rating option settings')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            MessageManager::warning((string) __('请求方式错误'));
            $this->redirect($this->_url->getBackendUrl('*/backend/review/config'));
            return '';
        }

        try {
            $options = $this->request->getPost('options') ?? [];
            $newOption = $this->request->getPost('new_option') ?? [];
            $reviewMode = (string) ($this->request->getPost('review_mode') ?? '');
            $this->getReviewConfigService()->saveReviewMode($reviewMode);
            $this->ratingOptionService->saveOptions(
                is_array($options) ? $options : [],
                is_array($newOption) ? $newOption : []
            );
            MessageManager::success((string) __('评价项配置已保存'));
        } catch (\Throwable $throwable) {
            MessageManager::error((string) __('评价项配置保存失败：%{1}', [$throwable->getMessage()]));
        }

        $this->redirect($this->_url->getBackendUrl('*/backend/review/config'));
        return '';
    }

    private function getReviewConfigService(): ReviewConfigService
    {
        return $this->reviewConfigService ?? new ReviewConfigService();
    }
}

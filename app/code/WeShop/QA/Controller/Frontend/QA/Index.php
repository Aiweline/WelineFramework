<?php

declare(strict_types=1);

namespace WeShop\QA\Controller\Frontend\QA;

use WeShop\Frontend\Controller\BaseController;
use WeShop\QA\Service\QAQuestionPageDataService;

class Index extends BaseController
{
    /**
     * Layout type for QA pages
     */
    protected ?string $layoutType = 'qa';

    public function __construct(
        private readonly QAQuestionPageDataService $qaQuestionPageDataService
    ) {
    }

    public function index(): string
    {
        $productId = (int) ($this->request->getParam('product_id') ?? 0);

        if ($productId <= 0) {
            $this->getMessageManager()->addError(__('Product ID cannot be empty.'));
            $this->redirect('catalog/category');
            return '';
        }

        foreach ($this->qaQuestionPageDataService->build($productId) as $key => $value) {
            $this->assign($key, $value);
        }

        $this->assign('title', __('Product Q&A'));

        return $this->fetch();
    }
}

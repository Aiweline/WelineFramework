<?php

declare(strict_types=1);

namespace Weline\Checkout\Controller;

use Weline\Framework\App\Controller\FrontendController;

/**
 * Lightweight express confirm page after provider return (before capture).
 */
class ExpressReview extends FrontendController
{
    public function index(): string
    {
        $this->request->setGet('theme_page_title', (string) __('确认并付款'));
        $this->assign('page_title', __('确认并付款'));
        $this->assign('title', __('确认并付款'));
        $this->assign(
            'transaction_no',
            trim((string) ($this->request->getGet('transaction_no') ?? $this->request->getParam('transaction_no') ?? ''))
        );
        $this->layoutType = 'checkout';

        return $this->fetch('Weline_Checkout::frontend/checkout/express-review.phtml');
    }
}

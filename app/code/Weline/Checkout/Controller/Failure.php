<?php

declare(strict_types=1);

namespace Weline\Checkout\Controller;

use Weline\Framework\App\Controller\FrontendController;

/**
 * Storefront checkout failure page at /checkout/failure.
 */
class Failure extends FrontendController
{
    public function index(): string
    {
        $this->request->getResponse()
            ->setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate')
            ->setHeader('Pragma', 'no-cache');

        $title = (string)__('订单尚未完成');
        $errorMessage = trim((string)$this->request->getParam('message', ''));
        $errorCode = trim((string)$this->request->getParam('error_code', ''));
        if ($errorMessage === '') {
            $errorMessage = (string)__(
                '本次结算没有生成有效订单，也不会在此页面重复扣款。您可以返回购物车核对商品，或前往帮助中心排查支付与配送问题。'
            );
        }

        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('error_message', $errorMessage);
        $this->assign('error_code', $errorCode);
        $this->assign('retry_url', trim((string)$this->request->getParam('retry_url', '')));
        $this->assign('checkout_failure_preview', $this->isThemeEditorCanvasRequest());

        return $this->fetch('Weline_Checkout::frontend/checkout/failure.phtml');
    }

    private function isThemeEditorCanvasRequest(): bool
    {
        $editorMode = strtolower(trim((string)$this->request->getParam('editor_mode', '')));
        if ($editorMode === '1' || $editorMode === 'true' || $editorMode === 'yes') {
            return true;
        }

        return strtolower(trim((string)$this->request->getParam('shell', ''))) === 'theme-editor';
    }
}

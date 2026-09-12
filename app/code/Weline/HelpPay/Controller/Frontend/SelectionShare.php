<?php

declare(strict_types=1);

namespace Weline\HelpPay\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\HelpPay\Service\HelpPayOrchestrator;
use Weline\Payment\Api\PaymentLinkServiceInterface;
use Weline\Payment\Service\PaymentLinkService;
use Weline\Theme\Model\ThemeLayout;

final class SelectionShare extends FrontendController
{
    public function index(): string
    {
        $this->layoutType = ThemeLayout::PAGE_TYPE_DEFAULT;

        $token = trim((string) $this->request->getParam('token', ''));
        if ($token === '') {
            $token = trim((string) ($this->request->getData('token') ?? ''));
        }
        if ($token === '') {
            $token = trim((string) ($this->request->getRule('token') ?? ''));
        }
        $orch = $this->orchestrator();
        $share = $orch->resolveSelectionShare($token);
        if ($share === null) {
            $this->assign('page_title', (string) __('分享链接无效'));
            $this->assign('error', 'selection_share_invalid');

            return (string) $this->fetch('Weline_HelpPay::templates/frontend/share/invalid.phtml');
        }

        $this->assign('page_title', (string) __('朋友帮你选好了'));
        $this->assign('share', $share);
        $this->assign('help_url', '/faq/selection-share');
        $this->assign('checkout_url', '/checkout');

        return (string) $this->fetch('Weline_HelpPay::templates/frontend/share/apply.phtml');
    }

    private function orchestrator(): HelpPayOrchestrator
    {
        try {
            $links = ObjectManager::getInstance(PaymentLinkServiceInterface::class);
            if (!$links instanceof PaymentLinkServiceInterface) {
                $links = new PaymentLinkService();
            }
        } catch (\Throwable) {
            $links = new PaymentLinkService();
        }

        return new HelpPayOrchestrator($links);
    }
}

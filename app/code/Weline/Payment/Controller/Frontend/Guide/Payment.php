<?php

declare(strict_types=1);

namespace Weline\Payment\Controller\Frontend\Guide;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Payment\Service\PaymentCustomerGuideRegistry;

/** Payment customer guide hub and provider-owned guide/policy pages. */
final class Payment extends FrontendController
{
    public function __construct(
        private readonly PaymentCustomerGuideRegistry $guideRegistry
    ) {
    }

    public function index(): string
    {
        $title = (string) __('支付方式指南');
        $entries = $this->guideRegistry->listPublishedEntries();

        $this->layoutType = 'payment_guide';
        $this->request->setGet('page_type', 'payment_guide');
        $this->request->setGet('theme_public_route', 'guide/payment');
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('payment_guide_page_heading', $title);
        $this->assign(
            'payment_guide_page_subtitle',
            (string) __('查看各支付供应商的客户支付指南与支付政策。')
        );
        $this->assign('payment_guide_entries', $entries);
        $this->assign('payment_guide_entry', []);
        $this->assign('payment_guide_page_type', 'hub');
        $this->assign('showSidebar', true);

        return (string) $this->fetch('Weline_Payment::templates/Frontend/guide/payment/index.phtml');
    }

    public function view(): string
    {
        return $this->renderProviderPage('guide');
    }

    public function policy(): string
    {
        return $this->renderProviderPage('policy');
    }

    public function agreement(): string
    {
        return $this->renderProviderPage('agreement');
    }

    private function renderProviderPage(string $pageType): string
    {
        $methodCode = strtolower(trim((string) $this->request->getParam('method_code', '')));
        $entry = $this->guideRegistry->getEntry($methodCode);
        if ($entry === null) {
            $this->noRouter();

            return '';
        }

        $titleKey = match ($pageType) {
            'policy' => 'policy_title',
            'agreement' => 'agreement_title',
            default => 'guide_title',
        };
        $layoutKey = match ($pageType) {
            'policy' => 'policy_layout_type',
            'agreement' => 'agreement_layout_type',
            default => 'guide_layout_type',
        };
        $templateKey = match ($pageType) {
            'policy' => 'policy_template',
            'agreement' => 'agreement_template',
            default => 'guide_template',
        };
        $title = (string) ($entry[$titleKey] ?? '');
        $layoutType = (string) ($entry[$layoutKey] ?? 'payment_guide');
        $template = (string) ($entry[$templateKey] ?? '');
        $publicRoute = match ($pageType) {
            'policy' => 'guide/payment/' . $methodCode . '/policy',
            'agreement' => 'guide/payment/' . $methodCode . '/agreement',
            default => 'guide/payment/' . $methodCode,
        };

        $this->layoutType = $layoutType;
        $this->request->setGet('page_type', $layoutType);
        $this->request->setGet('theme_public_route', $publicRoute);
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('payment_guide_entry', $entry);
        $this->assign('payment_guide_entries', $this->guideRegistry->listPublishedEntries());
        $this->assign('payment_guide_page_type', $pageType);
        $this->assign('showSidebar', true);

        return (string) $this->fetch($template);
    }
}

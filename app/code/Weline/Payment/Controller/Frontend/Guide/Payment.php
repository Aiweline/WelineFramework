<?php

declare(strict_types=1);

namespace Weline\Payment\Controller\Frontend\Guide;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Payment\Service\PaymentCustomerGuideRegistry;

/**
 * 支付方式指南（店面）。
 *
 * @Extra type=fpc enabled=true ttl=1800 namespaces=website/default/theme public_path_patterns=/guide/payment,/guide/payment/**
 */
final class Payment extends FrontendController
{
    public function __construct(
        private readonly PaymentCustomerGuideRegistry $guideRegistry
    ) {
    }

    public function index(): string
    {
        $title = (string) __('支付方式指南');
        // 不启用即隐藏：可用性由 Provider 层门闩判定，指南层只消费结果。
        $entries = $this->guideRegistry->listStorefrontPublishedEntries();

        $this->layoutType = 'payment_guide';
        $this->request->setGet('page_type', 'payment_guide');
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

        $this->layoutType = $layoutType;
        $this->request->setGet('page_type', $layoutType);
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('payment_guide_entry', $entry);
        // 侧边导航同样「不启用即隐藏」，但当前页始终保留：
        // 供应商登记的协议链接是法律链接，即使该方式已下线也要可达。
        $this->assign(
            'payment_guide_entries',
            $this->guideRegistry->listStorefrontPublishedEntries(
                alwaysIncludeMethodCode: $methodCode,
            )
        );
        $this->assign('payment_guide_page_type', $pageType);
        $this->assign('showSidebar', true);

        return (string) $this->fetch($template);
    }
}

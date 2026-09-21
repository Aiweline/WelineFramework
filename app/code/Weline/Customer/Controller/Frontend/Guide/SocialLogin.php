<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Frontend\Guide;

use Weline\Customer\Service\SocialLogin\SocialLoginGuideRegistry;
use Weline\Framework\App\Controller\FrontendController;

/**
 * 社媒登录指南（店面；非账户会话页）。
 *
 * @Extra type=fpc enabled=true ttl=1800 namespaces=website/default/theme public_path_patterns=/guide/social-login,/guide/social-login/**
 */
final class SocialLogin extends FrontendController
{
    public function __construct(
        private readonly SocialLoginGuideRegistry $guideRegistry
    ) {
    }

    public function index(): string
    {
        $title = (string) __('社媒登录指南');
        $entries = $this->guideRegistry->listEntries();

        // payment_guide layout injects controller HTML into {{content}}; help layout does not.
        $this->layoutType = 'payment_guide';
        $this->request->setGet('page_type', 'payment_guide');
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('social_login_guide_page_heading', $title);
        $this->assign(
            'social_login_guide_page_subtitle',
            (string) __('查看各社媒登录提供方的使用指南与登录政策。')
        );
        $this->assign('social_login_guide_entries', $entries);
        $this->assign('social_login_guide_entry', []);
        $this->assign('social_login_guide_page_type', 'hub');
        $this->assign('showSidebar', true);

        return (string) $this->fetch('Weline_Customer::templates/Frontend/guide/social-login/index.phtml');
    }

    public function view(): string
    {
        return $this->renderProviderPage('guide');
    }

    public function policy(): string
    {
        return $this->renderProviderPage('policy');
    }

    private function renderProviderPage(string $pageType): string
    {
        $code = strtolower(trim((string) $this->request->getParam('provider_code', '')));
        $entry = $this->guideRegistry->getEntry($code);
        if ($entry === null) {
            $this->noRouter();

            return '';
        }

        $title = (string) ($entry[$pageType === 'policy' ? 'policy_title' : 'guide_title'] ?? '');
        $layoutType = (string) ($entry[$pageType === 'policy' ? 'policy_layout_type' : 'guide_layout_type'] ?? 'payment_guide');
        if ($layoutType === '' || $layoutType === 'help' || $layoutType === 'policy') {
            $layoutType = 'payment_guide';
        }
        $template = (string) ($entry[$pageType === 'policy' ? 'policy_template' : 'guide_template'] ?? '');

        $this->layoutType = $layoutType;
        $this->request->setGet('page_type', $layoutType);
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('social_login_guide_entry', $entry);
        $this->assign('social_login_guide_entries', $this->guideRegistry->listEntries());
        $this->assign('social_login_guide_page_type', $pageType);
        $this->assign('showSidebar', true);

        return (string) $this->fetch($template);
    }
}

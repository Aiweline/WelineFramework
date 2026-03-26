<?php

declare(strict_types=1);

namespace WeShop\Frontend\Controller;

use WeShop\Frontend\Service\StorefrontShellDataService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;

class BaseController extends FrontendController
{
    protected ?string $layoutType = null;

    protected ?int $layoutVariant = null;

    protected ?array $currentTheme = null;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $storefrontShellData = null;

    public function __init()
    {
        parent::__init();

        $this->initLayout();
        $this->initCommonData();
    }

    protected function initLayout(): void
    {
        $requestVariant = $this->getRequest()->getParam('layout');
        if ($requestVariant !== null && is_numeric($requestVariant)) {
            $this->layoutVariant = (int) $requestVariant;
        }

        if ($this->layoutVariant === null && $this->layoutType !== null) {
            $this->layoutVariant = $this->getLayoutVariantFromConfig();
        }

        if ($this->layoutVariant === null) {
            $this->layoutVariant = 1;
        }

        $this->assign('layout_variant', $this->layoutVariant);
        $this->assign('meta', [
            'showHeader' => true,
            'showFooter' => true,
            'layoutType' => $this->layoutType,
            'layoutVariant' => $this->layoutVariant,
        ]);
    }

    protected function getLayoutVariantFromConfig(): int
    {
        try {
            $theme = $this->getCurrentTheme();
            if (!$theme || empty($theme['id'])) {
                return 1;
            }
        } catch (\Throwable $e) {
            return 1;
        }

        return 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getCurrentTheme(): ?array
    {
        if ($this->currentTheme === null) {
            try {
                $this->currentTheme = w_query('theme', 'getActiveTheme', [], 'frontend');
            } catch (\Throwable $e) {
                $this->currentTheme = null;
            }
        }

        return $this->currentTheme;
    }

    protected function initCommonData(): void
    {
        foreach ($this->getStorefrontShellData() as $key => $value) {
            $this->assign($key, $value);
        }

        $this->assign('locale', State::getLangLocal());
    }

    protected function getStoreName(): string
    {
        return (string) ($this->getStorefrontShellData()['store_name'] ?? __('WeShop'));
    }

    protected function getStoreCurrency(): string
    {
        $currency = strtoupper(trim((string) ($this->getStorefrontShellData()['store_currency'] ?? '')));
        return $currency !== '' ? $currency : 'USD';
    }

    protected function getLayoutPath(?string $layoutType = null, ?int $variant = null): string
    {
        $layoutType = $layoutType ?? $this->layoutType;
        $variant = $variant ?? $this->layoutVariant ?? 1;

        if ($layoutType === null) {
            return '';
        }

        $layoutPathMap = [
            'homepage' => "homepage/e_commerce_home_page_{$variant}",
            'product_list' => "product_list/product_listing_page_{$variant}",
            'product' => "product/product_detail_page_{$variant}",
            'cart' => "cart/shopping_cart_page_{$variant}",
            'checkout' => "checkout/checkout_page_{$variant}",
            'checkout_success' => "checkout_success/order_confirmation_page_{$variant}",
            'account_auth' => [
                'login' => "account_auth/login_page_{$variant}",
                'register' => "account_auth/sign_up_page_{$variant}",
                'forgot_password' => "account_auth/forgot_password_page_{$variant}",
            ],
            'account' => "account/account_page_{$variant}",
            'cms' => "cms/cms_page_{$variant}",
            'customer_service' => "customer_service/customer_service_page_{$variant}",
            'promotion' => "promotion/promotion_page_{$variant}",
            'qa' => "qa/qa_page_{$variant}",
            'review' => "review/review_page_{$variant}",
            'rma' => "rma/rma_page_{$variant}",
        ];

        $layoutPath = $layoutPathMap[$layoutType] ?? null;

        if (is_array($layoutPath)) {
            $controllerClass = get_class($this);
            $authType = 'login';

            if (strpos($controllerClass, 'Login') !== false) {
                $authType = 'login';
            } elseif (strpos($controllerClass, 'Register') !== false || strpos($controllerClass, 'Create') !== false) {
                $authType = 'register';
            } elseif (strpos($controllerClass, 'ForgotPassword') !== false) {
                $authType = 'forgot_password';
            }

            $layoutPath = $layoutPath[$authType] ?? $layoutPath['login'];
        }

        if ($layoutPath === null) {
            return '';
        }

        return "WeShop_Theme::theme/frontend/layouts/{$layoutPath}.phtml";
    }

    protected function renderLayout(
        string $layoutType,
        string $contentTemplate,
        ?string $title = null,
        array $additionalData = []
    ): string {
        if (empty($layoutType) && $this->layoutType !== null) {
            if (empty($contentTemplate)) {
                $controllerClass = get_class($this);
                $moduleName = str_replace('\\', '_', explode('\\', $controllerClass)[0] . '\\' . explode('\\', $controllerClass)[1]);
                $controllerPath = str_replace(
                    'Controller\\Frontend\\',
                    '',
                    substr($controllerClass, strpos($controllerClass, 'Controller\\Frontend\\') + strlen('Controller\\Frontend\\'))
                );
                $controllerPath = str_replace('\\', '/', $controllerPath);
                $contentTemplate = "{$moduleName}::templates/frontend/{$controllerPath}/index.phtml";
            }

            return $this->fetch($contentTemplate);
        }

        return parent::renderLayout($layoutType, $contentTemplate, $title, $additionalData);
    }

    protected function formatPrice(float $price, string $currency = ''): string
    {
        $resolvedCurrency = trim($currency) !== '' ? strtoupper($currency) : $this->getStoreCurrency();
        return number_format($price, 2) . ' ' . $resolvedCurrency;
    }

    protected function getStorefrontLoginRoute(): string
    {
        return 'weshop/customer/account/login';
    }

    protected function getStorefrontAccountRoute(): string
    {
        return 'weshop/customer/account/index';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getStorefrontShellData(): array
    {
        if ($this->storefrontShellData === null) {
            try {
                $data = $this->getStorefrontShellDataService()->build();
            } catch (\Throwable $e) {
                $data = [];
            }

            $this->storefrontShellData = array_merge([
                'store_name' => (string) __('WeShop'),
                'store_currency' => 'USD',
                'cart_count' => 0,
                'cart_total' => 0.0,
            ], is_array($data) ? $data : []);
        }

        return $this->storefrontShellData;
    }

    protected function getStorefrontShellDataService(): StorefrontShellDataService
    {
        return ObjectManager::getInstance(StorefrontShellDataService::class);
    }
}

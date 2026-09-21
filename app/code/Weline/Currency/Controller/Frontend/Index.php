<?php

declare(strict_types=1);

namespace Weline\Currency\Controller\Frontend;

use Weline\Currency\Data\CurrencyData;
use Weline\Currency\Model\Config as CurrencyConfig;
use Weline\Framework\App\Controller\FrontendController;

/**
 * 店面货币与汇率说明。
 *
 * @Extra type=fpc enabled=true ttl=1800 namespaces=website/default/theme,global/storefront/price public_path_patterns=/currency
 */
final class Index extends FrontendController
{
    public function __construct(
        private readonly CurrencyConfig $currencyConfig,
    ) {
    }

    public function index(): string
    {
        $title = \Weline\Theme\Helper\WidgetI18n::label('货币与汇率');
        $currencies = CurrencyData::getCurrencies();
        $baseCode = strtoupper(trim($this->currencyConfig->getBaseCurrency()));
        $rateMode = $this->currencyConfig->getRateMode();

        $this->layoutType = 'default';
        $this->request->setGet('page_type', 'currency_guide');
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);
        if (class_exists(\Weline\Seo\Service\Head\SeoPageProfileBag::class)) {
            \Weline\Seo\Service\Head\SeoPageProfileBag::publish(['title' => $title]);
        }
        $this->assign('currency_page_heading', $title);
        $this->assign(
            'currency_page_subtitle',
            \Weline\Theme\Helper\WidgetI18n::label('查看本站支持的结算货币、基准币与汇率政策说明。')
        );
        $this->assign('currency_list', $currencies);
        $this->assign('currency_base_code', $baseCode);
        $this->assign('currency_rate_mode', $rateMode);
        $this->assign(
            'currency_rate_mode_label',
            $rateMode === CurrencyConfig::RATE_MODE_AUTO
                ? (string)__('自动汇率')
                : (string)__('手动汇率')
        );
        $this->assign('showSidebar', false);

        return (string)$this->fetch('Weline_Currency::templates/Frontend/currency/index.phtml');
    }
}

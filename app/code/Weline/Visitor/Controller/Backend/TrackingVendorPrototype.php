<?php

declare(strict_types=1);

namespace Weline\Visitor\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

/**
 * PROTOTYPE ONLY — 第三方事件供应商管理 UI 抛出页（?variant=1|2|3）。
 * 内存假数据；不写 DB；不改生产 pixel 转发。定稿后 Phase2 再落壳。
 *
 * Question: Three structural variants of the pixel event-vendor admin
 * (list-first / split / detail-tabs), switchable via ?variant=.
 */
#[Acl('Weline_Visitor::tracking_vendor_prototype', '事件供应商原型', 'plug', '像素事件供应商管理 UI 原型（非生产）', 'Weline_Backend::data_tools_group')]
class TrackingVendorPrototype extends BackendController
{
    #[Acl('Weline_Visitor::tracking_vendor_prototype_index', '查看事件供应商原型', 'plug', '查看事件供应商管理 UI 原型')]
    public function index(): string
    {
        $variant = (string)($this->request->getGet('variant') ?? '1');
        if (!\in_array($variant, ['1', '2', '3'], true)) {
            $variant = '1';
        }

        $vendors = $this->buildPrototypeVendors();
        $selectedCode = (string)($this->request->getGet('vendor') ?? ($vendors[0]['code'] ?? 'ga4'));
        $selected = $vendors[0];
        foreach ($vendors as $row) {
            if (($row['code'] ?? '') === $selectedCode) {
                $selected = $row;
                break;
            }
        }

        $this->assign('page_title', (string)__('事件供应商（原型）'));
        $this->assign('is_prototype', true);
        $this->assign('variant', $variant);
        $this->assign('vendors_json', \json_encode($vendors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assign('vendors', $vendors);
        $this->assign('selected_vendor', $selected);
        $this->assign('selected_code', (string)($selected['code'] ?? 'ga4'));
        $this->assign('dev_no_report', true);
        $this->assign('weline_events', $this->buildMappableEvents());

        return $this->fetch();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPrototypeVendors(): array
    {
        $defaultMapGa4 = [
            ['weline_event' => 'cta_click', 'third_party_event' => 'cta_click'],
            ['weline_event' => 'view_item', 'third_party_event' => 'view_item'],
            ['weline_event' => 'add_to_cart', 'third_party_event' => 'add_to_cart'],
            ['weline_event' => 'begin_checkout', 'third_party_event' => 'begin_checkout'],
            ['weline_event' => 'checkout_success', 'third_party_event' => 'purchase'],
            ['weline_event' => 'lead_submit', 'third_party_event' => 'generate_lead'],
        ];
        $defaultMapGtm = $defaultMapGa4;

        return [
            [
                'code' => 'ga4',
                'name' => 'Google Analytics 4',
                'source' => 'module',
                'provider_module' => 'Weline_Visitor',
                'provider_class' => 'Weline\\Visitor\\Extends\\Weline_Visitor\\PixelEventVendor\\Ga4Vendor',
                'enabled' => true,
                'mode' => 'sandbox',
                'default_mode' => 'sandbox',
                'use_default_map' => true,
                'credentials' => [
                    'measurement_id' => 'G-XXXXXXXXXX',
                ],
                'event_map' => $defaultMapGa4,
                'default_event_map' => $defaultMapGa4,
                'sandbox_js' => "// sandbox bridge: receive envelope, map, gtag('event', ...)\nwindow.addEventListener('message', function (e) {\n  if (!e.data || e.data.channel !== 'weline-pixel-sandbox/v1') return;\n  // map + dry_run log only in DEV\n});",
                'inject_js' => '',
                'credential_summary' => 'G-XXXXXXXXXX',
            ],
            [
                'code' => 'gtm',
                'name' => 'Google Tag Manager',
                'source' => 'module',
                'provider_module' => 'Weline_Visitor',
                'provider_class' => 'Weline\\Visitor\\Extends\\Weline_Visitor\\PixelEventVendor\\GtmVendor',
                'enabled' => true,
                'mode' => 'sandbox',
                'default_mode' => 'sandbox',
                'use_default_map' => true,
                'credentials' => [
                    'container_id' => 'GTM-XXXXXXX',
                ],
                'event_map' => $defaultMapGtm,
                'default_event_map' => $defaultMapGtm,
                'sandbox_js' => "// sandbox: push mapped event to isolated dataLayer\nwindow.dataLayer = window.dataLayer || [];",
                'inject_js' => '',
                'credential_summary' => 'GTM-XXXXXXX',
            ],
            [
                'code' => 'custom_demo',
                'name' => '自定义 Demo Pixel',
                'source' => 'custom',
                'provider_module' => '',
                'provider_class' => '',
                'enabled' => false,
                'mode' => 'sandbox',
                'default_mode' => 'sandbox',
                'use_default_map' => true,
                'credentials' => [
                    'endpoint' => 'https://example.com/collect',
                ],
                'event_map' => [
                    ['weline_event' => 'cta_click', 'third_party_event' => 'click'],
                    ['weline_event' => 'lead_submit', 'third_party_event' => 'lead'],
                ],
                'default_event_map' => [
                    ['weline_event' => 'cta_click', 'third_party_event' => 'click'],
                    ['weline_event' => 'lead_submit', 'third_party_event' => 'lead'],
                ],
                'sandbox_js' => "// custom vendor: fetch(endpoint, { method:'POST', body: JSON.stringify(mapped) })\n",
                'inject_js' => '',
                'credential_summary' => 'example.com/collect',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function buildMappableEvents(): array
    {
        return [
            'cta_click',
            'hero_cta_click',
            'view_item',
            'add_to_cart',
            'begin_checkout',
            'checkout_success',
            'payment_success',
            'checkout_failure',
            'search_submit',
            'search_suggestion_click',
            'route_click',
            'lead_submit',
            'login',
            'register',
        ];
    }
}

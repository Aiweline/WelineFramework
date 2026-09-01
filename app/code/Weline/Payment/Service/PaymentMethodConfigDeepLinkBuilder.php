<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Http\UrlInterface;

/**
 * 支付方式 → 统一配置中心深链（必须带 guide_key + guide_locate 才能自动定位）。
 */
final class PaymentMethodConfigDeepLinkBuilder
{
    public function __construct(
        private readonly UrlInterface $url,
    ) {
    }

    /**
     * @param array{module?:string,guide?:bool} $options
     */
    public function build(string $methodCode, string $targetScope, array $options = []): string
    {
        $methodCode = strtolower(trim($methodCode));
        $storageScope = strtolower(trim($targetScope));
        if ($storageScope === '' || $storageScope === 'global') {
            $storageScope = 'default.default.default';
        }

        $module = trim((string)($options['module'] ?? 'Weline_Payment'));
        if ($module === '') {
            $module = 'Weline_Payment';
        }

        $search = $this->searchQuery($methodCode);
        $params = [
            'area' => 'backend',
            'scope' => $storageScope,
            'target_scope' => $storageScope,
            'module' => $module,
            // 配置中心控制器读 search；保留 q 作兼容别名。
            'search' => $search,
            'q' => $search,
        ];

        $withGuide = ($options['guide'] ?? true) === true;
        if ($withGuide && $methodCode !== '') {
            $guide = $this->guideTarget($methodCode);
            $params['guide_key'] = $guide['key'];
            $params['guide_locate'] = $guide['key'];
            $params['guide_title'] = $guide['title'];
            if ($guide['summary'] !== '') {
                $params['guide_summary'] = $guide['summary'];
            }
        }

        return (string)$this->url->getBackendUrl('weline_systemconfig/backend/config', $params, false);
    }

    private function searchQuery(string $methodCode): string
    {
        return match ($methodCode) {
            'paypal', 'fake_card' => $methodCode,
            default => 'payment/method/' . $methodCode,
        };
    }

    /**
     * @return array{key:string,title:string,summary:string}
     */
    private function guideTarget(string $methodCode): array
    {
        return match ($methodCode) {
            'paypal' => [
                'key' => 'adapter:paypal.sandbox.authorize',
                'title' => $this->t('PayPal 沙箱一键授权'),
                'summary' => $this->t('可在 Global 或 Website（含默认站点）下点击「沙箱一键授权」跳转 PayPal Sandbox 登录。'),
            ],
            'fake_card' => [
                'key' => 'payment/method/fake_card/enabled',
                'title' => $this->t('Fake Card 启用开关'),
                'summary' => $this->t('在此启用或关闭本地测试支付方式，并可继续配置图标与货币。'),
            ],
            default => [
                'key' => 'payment/method/' . $methodCode . '/enabled',
                'title' => $this->t('支付方式配置'),
                'summary' => $this->t('已定位到该支付方式配置项，请按需修改并保存。'),
            ],
        };
    }

    private function t(string $text): string
    {
        return \function_exists('__') ? (string)__($text) : $text;
    }
}

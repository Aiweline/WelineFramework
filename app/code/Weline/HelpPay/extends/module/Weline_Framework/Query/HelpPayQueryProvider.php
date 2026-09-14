<?php

declare(strict_types=1);

namespace Weline\HelpPay\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\HelpPay\Service\HelpPayOrchestrator;
use Weline\HelpPay\Service\ShareQrService;
use Weline\Payment\Api\PaymentLinkServiceInterface;
use Weline\Payment\Service\PaymentLinkService;

/**
 * Storefront Query: createHelpPay / createSelectionShare / createQuickPay / qrPng / revoke.
 */
final class HelpPayQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'helpPay';
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'helpPay',
            'name' => '帮我付 / 纯分享 / 快捷购买',
            'description' => '创建帮我付与分享短链、二维码 PNG、撤销链接',
            'module' => 'Weline_HelpPay',
            'operations' => [
                [
                    'name' => 'createHelpPay',
                    'frontend' => true,
                    'external' => true,
                    'mode' => 'write',
                    'description' => '创建帮我付短链（需地址确认与规则勾选）',
                    'params' => [
                        ['name' => 'amount_minor', 'type' => 'int', 'required' => true, 'description' => '金额 minor'],
                        ['name' => 'shipping_address', 'type' => 'array', 'required' => true, 'description' => '收货快照'],
                        ['name' => 'address_confirmed', 'type' => 'bool', 'required' => true, 'description' => '地址已确认'],
                        ['name' => 'rules_accepted', 'type' => 'bool', 'required' => true, 'description' => '已阅读规则'],
                        ['name' => 'currency_code', 'type' => 'string', 'required' => false, 'description' => '币种'],
                        ['name' => 'cart_type', 'type' => 'string', 'required' => false, 'description' => 'toc|tob；一期仅 toc'],
                        ['name' => 'line_summary', 'type' => 'array', 'required' => false, 'description' => '行摘要'],
                        ['name' => 'public_origin', 'type' => 'string', 'required' => false, 'description' => '公网 origin'],
                        ['name' => 'ttl_seconds', 'type' => 'int', 'required' => false, 'description' => 'TTL 秒'],
                    ],
                ],
                [
                    'name' => 'createSelectionShare',
                    'frontend' => true,
                    'external' => true,
                    'mode' => 'write',
                    'description' => '创建纯分享短链',
                    'params' => [
                        ['name' => 'selection_snapshot', 'type' => 'array', 'required' => true, 'description' => '规格快照'],
                        ['name' => 'cart_type', 'type' => 'string', 'required' => false, 'description' => 'toc|tob；一期仅 toc'],
                        ['name' => 'public_origin', 'type' => 'string', 'required' => false, 'description' => '公网 origin'],
                        ['name' => 'ttl_seconds', 'type' => 'int', 'required' => false, 'description' => 'TTL 秒'],
                    ],
                ],
                [
                    'name' => 'createQuickPay',
                    'frontend' => true,
                    'external' => true,
                    'mode' => 'write',
                    'description' => '创建本人快捷购买短链',
                    'params' => [
                        ['name' => 'amount_minor', 'type' => 'int', 'required' => true, 'description' => '应付总额 minor（可含运费）'],
                        ['name' => 'shipping_address', 'type' => 'array', 'required' => true, 'description' => '收货地址'],
                        ['name' => 'currency_code', 'type' => 'string', 'required' => false, 'description' => '币种'],
                        ['name' => 'cart_type', 'type' => 'string', 'required' => false, 'description' => 'toc|tob；一期仅 toc'],
                        ['name' => 'service_code', 'type' => 'string', 'required' => false, 'description' => '所选配送 service_code'],
                        ['name' => 'service_label', 'type' => 'string', 'required' => false, 'description' => '配送可读名'],
                        ['name' => 'goods_amount_minor', 'type' => 'int', 'required' => false, 'description' => '商品 minor'],
                        ['name' => 'shipping_amount_minor', 'type' => 'int', 'required' => false, 'description' => '运费 minor'],
                        ['name' => 'product_id', 'type' => 'int', 'required' => false, 'description' => '商品 ID（含运费时必填，服务端按真实重量复核）'],
                        ['name' => 'qty', 'type' => 'int', 'required' => false, 'description' => '数量'],
                        ['name' => 'line_summary', 'type' => 'array', 'required' => false, 'description' => '行摘要'],
                        ['name' => 'public_origin', 'type' => 'string', 'required' => false, 'description' => '公网 origin'],
                        ['name' => 'ttl_seconds', 'type' => 'int', 'required' => false, 'description' => 'TTL 秒'],
                    ],
                ],
                [
                    'name' => 'listQuickShippingOptions',
                    'frontend' => true,
                    'external' => true,
                    'mode' => 'read',
                    'description' => '快捷购买物流报价（真实重量，对齐结账缺重门禁）',
                    'params' => [
                        ['name' => 'shipping_address', 'type' => 'array', 'required' => true, 'description' => '收货地址'],
                        ['name' => 'address', 'type' => 'array', 'required' => false, 'description' => '地址别名'],
                        ['name' => 'product_id', 'type' => 'int', 'required' => true, 'description' => '商品 ID'],
                        ['name' => 'qty', 'type' => 'int', 'required' => false, 'description' => '数量'],
                        ['name' => 'goods_amount_minor', 'type' => 'int', 'required' => false, 'description' => '商品 minor'],
                        ['name' => 'currency_code', 'type' => 'string', 'required' => false, 'description' => '币种'],
                        ['name' => 'cart_type', 'type' => 'string', 'required' => false, 'description' => 'toc|tob'],
                    ],
                ],
                [
                    'name' => 'qrPng',
                    'frontend' => true,
                    'external' => true,
                    'mode' => 'read',
                    'description' => '按 URL 生成二维码 data URI',
                    'params' => [
                        ['name' => 'url', 'type' => 'string', 'required' => true, 'description' => '绝对 http(s) URL'],
                    ],
                ],
                [
                    'name' => 'revoke',
                    'frontend' => true,
                    'external' => true,
                    'mode' => 'write',
                    'description' => '撤销短链',
                    'params' => [
                        ['name' => 'token', 'type' => 'string', 'required' => true, 'description' => 'token'],
                        ['name' => 'kind', 'type' => 'string', 'required' => false, 'description' => 'kind'],
                    ],
                ],
                [
                    'name' => 'resolveHelpPay',
                    'frontend' => true,
                    'external' => true,
                    'mode' => 'read',
                    'description' => '代付人解析（无 shipping）',
                    'params' => [
                        ['name' => 'token', 'type' => 'string', 'required' => true, 'description' => 'token'],
                    ],
                ],
                [
                    'name' => 'startPayerPayment',
                    'frontend' => true,
                    'external' => true,
                    'mode' => 'write',
                    'description' => '代付人确认付款：创建支付交易并返回跳转 URL 或即时成功',
                    'params' => [
                        ['name' => 'token', 'type' => 'string', 'required' => true, 'description' => '帮我付 token'],
                        ['name' => 'payment_method', 'type' => 'string', 'required' => true, 'description' => '支付方式 code'],
                        ['name' => 'billing_address', 'type' => 'array', 'required' => false, 'description' => '卡支付账单地址'],
                        ['name' => 'idempotency_key', 'type' => 'string', 'required' => false, 'description' => '幂等键'],
                    ],
                ],
                [
                    'name' => 'startQuickPayment',
                    'frontend' => true,
                    'external' => true,
                    'mode' => 'write',
                    'description' => '本人快捷购买确认付款：创建 PayPal 等交易并返回跳转 URL',
                    'params' => [
                        ['name' => 'token', 'type' => 'string', 'required' => true, 'description' => 'quick_pay token'],
                        ['name' => 'payment_method', 'type' => 'string', 'required' => false, 'description' => '默认 paypal'],
                        ['name' => 'billing_address', 'type' => 'array', 'required' => false, 'description' => '卡支付账单地址'],
                        ['name' => 'idempotency_key', 'type' => 'string', 'required' => false, 'description' => '幂等键'],
                    ],
                ],
            ],
        ];
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'createHelpPay' => $this->orch()->createHelpPay($this->withOrigin($params)),
            'createSelectionShare' => $this->orch()->createSelectionShare($this->withOrigin($params)),
            'createQuickPay' => $this->orch()->createQuickPay($this->withOrigin($params)),
            'listQuickShippingOptions' => $this->orch()->listQuickShippingOptions($params),
            'qrPng' => $this->qrPng($params),
            'revoke' => $this->revoke($params),
            'resolveHelpPay' => $this->orch()->resolveHelpPayForPayer((string) ($params['token'] ?? '')),
            'startPayerPayment' => $this->orch()->startPayerPayment($params),
            'startQuickPayment' => $this->orch()->startQuickPayment($params),
            default => throw new \InvalidArgumentException('helppay_operation_unsupported'),
        };
    }

    /**
     * @param array<string,mixed> $params
     * @return array{data_uri:string}
     */
    private function qrPng(array $params): array
    {
        $url = (string) ($params['url'] ?? '');
        $qr = new ShareQrService();

        return ['data_uri' => $qr->pngDataUri($url)];
    }

    /**
     * @param array<string,mixed> $params
     * @return array{ok:bool}
     */
    private function revoke(array $params): array
    {
        $token = (string) ($params['token'] ?? '');
        $kind = (string) ($params['kind'] ?? PaymentLinkServiceInterface::KIND_HELP_PAY);
        $actor = isset($params['customer_id']) ? (int) $params['customer_id'] : null;
        $ok = $this->links()->revoke($token, $kind, $actor);

        return ['ok' => $ok];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function withOrigin(array $params): array
    {
        if (empty($params['public_origin'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
            if ($host !== '') {
                $params['public_origin'] = $scheme . '://' . $host;
            }
        }

        return $params;
    }

    private function orch(): HelpPayOrchestrator
    {
        return new HelpPayOrchestrator($this->links());
    }

    private function links(): PaymentLinkServiceInterface
    {
        try {
            $resolved = ObjectManager::getInstance(PaymentLinkServiceInterface::class);
            if ($resolved instanceof PaymentLinkServiceInterface) {
                return $resolved;
            }
        } catch (\Throwable) {
        }

        return new PaymentLinkService();
    }
}

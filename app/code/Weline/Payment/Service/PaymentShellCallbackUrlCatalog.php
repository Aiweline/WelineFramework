<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Http\Url;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * Shell-unified callback URL catalog — the only source for return/cancel/notify/connect URLs.
 *
 * 浏览器回调地址路径携带 method_code（支付码）区分渠道；query 携带 target_scope 与 transaction_no。
 */
final class PaymentShellCallbackUrlCatalog
{
    public const QUERY_METHOD_CODE = 'method_code';
    public const QUERY_TRANSACTION_NO = 'transaction_no';
    public const QUERY_ENDPOINT_CODE = 'endpoint_code';

    public function __construct(
        private readonly Url $url,
        private readonly PayPalSandboxPublicOriginService $publicOrigin,
        private readonly PaymentBrowserCallbackTokenService $callbackToken,
    ) {
    }

    /**
     * Developer 登记 / OAuth 用 Return URL：路径含 method_code，query 含 target_scope。
     */
    public function browserReturnRegister(string $storageScope, string $methodCode): string
    {
        $storageScope = $this->normalizeStorageScope($storageScope);
        $methodCode = PaymentBrowserCallbackRoutes::normalizeMethodCode($methodCode);
        $base = $this->buildFrontendPathUrl(PaymentBrowserCallbackRoutes::returnRoute($methodCode));

        return PaymentBrowserCallbackRoutes::withTargetScope($base, $storageScope);
    }

    /**
     * 运行时浏览器 Return：路径 method_code + target_scope + transaction_no（+ 可选 shell_token）。
     */
    public function browserReturn(string $storageScope, string $methodCode, string $transactionNo): string
    {
        $storageScope = $this->normalizeStorageScope($storageScope);
        $methodCode = PaymentBrowserCallbackRoutes::normalizeMethodCode($methodCode);
        $transactionNo = trim($transactionNo);
        if ($transactionNo === '') {
            throw new \InvalidArgumentException('payment_shell_callback_payment_ref_required');
        }

        $base = $this->browserReturnRegister($storageScope, $methodCode);
        $shellToken = $this->callbackToken->encode($methodCode, $transactionNo, $storageScope);

        return $this->appendQuery($base, [
            PaymentBrowserCallbackTokenService::QUERY_SHELL_TOKEN => $shellToken,
            self::QUERY_TRANSACTION_NO => $transactionNo,
        ]);
    }

    /**
     * 浏览器 cancel：与 return 同路径，追加 outcome=cancel（+ transaction_no / shell_token）。
     */
    public function browserCancel(string $storageScope, string $methodCode, ?string $transactionNo = null): string
    {
        $storageScope = $this->normalizeStorageScope($storageScope);
        $methodCode = PaymentBrowserCallbackRoutes::normalizeMethodCode($methodCode);

        $base = $this->browserReturnRegister($storageScope, $methodCode);
        $query = [
            PaymentBrowserCallbackRoutes::QUERY_OUTCOME => PaymentBrowserCallbackRoutes::OUTCOME_CANCEL,
        ];
        $transactionNo = $transactionNo !== null ? trim($transactionNo) : '';
        if ($transactionNo !== '') {
            $query[self::QUERY_TRANSACTION_NO] = $transactionNo;
            $query[PaymentBrowserCallbackTokenService::QUERY_SHELL_TOKEN] = $this->callbackToken->encode(
                $methodCode,
                $transactionNo,
                $storageScope,
            );
        }

        return $this->appendQuery($base, $query);
    }

    public function browserFailure(string $storageScope, string $methodCode, ?string $transactionNo = null): string
    {
        $url = $this->browserCancel($storageScope, $methodCode, $transactionNo);

        return $this->appendQuery($url, ['failure' => '1']);
    }

    public function webhookNotify(string $endpointCode): string
    {
        $endpointCode = trim($endpointCode);
        if ($endpointCode === '') {
            throw new \InvalidArgumentException('payment_webhook_endpoint_required');
        }

        $base = $this->buildFrontendPathUrl(PaymentBrowserCallbackRoutes::NOTIFY);

        return $this->appendQuery($base, [self::QUERY_ENDPOINT_CODE => $endpointCode]);
    }

    public function connectAuthorize(string $methodCode, string $environment = 'sandbox'): string
    {
        $methodCode = PaymentBrowserCallbackRoutes::normalizeMethodCode($methodCode);

        return $this->url->getUrl('payment/backend/connect/authorize', [
            'method_code' => $methodCode,
            'environment' => strtolower(trim($environment)) ?: 'sandbox',
        ]);
    }

    public function transactionStatusPath(): string
    {
        return 'payment/frontend/checkout/return';
    }

    /**
     * @return list<string>
     */
    public function suggestedRedirectUris(?string $storageScope = null, ?string $methodCode = null): array
    {
        $storageScope = $this->normalizeStorageScope($storageScope ?? SystemConfig::SCOPE_GLOBAL);
        if ($methodCode === null || trim($methodCode) === '') {
            throw new \InvalidArgumentException('payment_method_code_required');
        }

        try {
            $uri = $this->browserReturnRegister($storageScope, $methodCode);
            if ($uri !== '' && !str_contains($uri, 'localhost')) {
                return [$uri];
            }
        } catch (\InvalidArgumentException) {
            return [];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{return_url:string,cancel_url:string,notify_url:string,failure_url:string}
     */
    public function buildBrowserCallbackUrls(string $methodCode, array $scope, ?string $transactionNo = null): array
    {
        $storageScope = $this->normalizeStorageScope((string) ($scope['scope'] ?? PaymentScopeConfigService::DEFAULT_SCOPE));
        $environment = strtolower(trim((string) ($scope['environment'] ?? 'sandbox')));
        $methodCode = PaymentBrowserCallbackRoutes::normalizeMethodCode($methodCode);
        $endpointCode = sprintf('%s.%s.default', $methodCode, $environment);
        $transactionNo = $transactionNo !== null ? trim($transactionNo) : '';
        if ($transactionNo === '') {
            throw new \InvalidArgumentException('payment_shell_callback_payment_ref_required');
        }

        return [
            'return_url' => $this->browserReturn($storageScope, $methodCode, $transactionNo),
            'cancel_url' => $this->browserCancel($storageScope, $methodCode, $transactionNo),
            'notify_url' => $this->webhookNotify($endpointCode),
            'failure_url' => $this->browserFailure($storageScope, $methodCode, $transactionNo),
        ];
    }

    private function normalizeStorageScope(string $storageScope): string
    {
        $storageScope = strtolower(trim($storageScope));
        if ($storageScope === '') {
            $storageScope = SystemConfig::SCOPE_GLOBAL;
        }
        if (!PaymentBrowserCallbackRoutes::isStorageScope($storageScope)) {
            throw new \InvalidArgumentException('payment_callback_target_scope_invalid');
        }

        return $storageScope;
    }

    private function buildFrontendPathUrl(string $routePath): string
    {
        $fixed = $this->publicOrigin->buildFrontendPathUrl($routePath);
        if ($fixed !== '') {
            return $fixed;
        }

        return $this->url->getUrl($routePath);
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function appendQuery(string $url, array $query): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('payment_callback_url_invalid');
        }

        $existing = [];
        if (isset($parts['query']) && \is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $existing);
            if (!\is_array($existing)) {
                $existing = [];
            }
        }

        $merged = array_merge($existing, $query);
        $rebuilt = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';
        $rebuilt .= '?' . http_build_query($merged, '', '&', PHP_QUERY_RFC3986);
        if (!empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }
}

<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

/**
 * Resolves browser return/cancel context from path/query method_code, shell_token, or gateway params.
 */
final class PaymentBrowserReturnContextResolver
{
    public function __construct(
        private readonly PaymentBrowserCallbackTokenService $tokenService,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array{
     *   method_code:string,
     *   transaction_no:string,
     *   target_scope:string,
     *   gateway_token:string,
     *   source:string
     * }|null
     */
    public function resolve(array $params): ?array
    {
        $methodCode = strtolower(trim((string) ($params[PaymentShellCallbackUrlCatalog::QUERY_METHOD_CODE] ?? '')));
        $targetScope = strtolower(trim((string) ($params[PaymentBrowserCallbackRoutes::QUERY_TARGET_SCOPE] ?? '')));

        $shellToken = trim((string) ($params[PaymentBrowserCallbackTokenService::QUERY_SHELL_TOKEN] ?? ''));
        if ($shellToken !== '') {
            $decoded = $this->tokenService->decode($shellToken);
            if ($decoded !== null) {
                return [
                    'method_code' => $decoded['method_code'],
                    'transaction_no' => $decoded['transaction_no'],
                    'target_scope' => $decoded['target_scope'],
                    'gateway_token' => $this->gatewayToken($params),
                    'source' => 'shell_token',
                ];
            }
        }

        $transactionNo = trim((string) (
            $params[PaymentShellCallbackUrlCatalog::QUERY_TRANSACTION_NO]
            ?? $params['transaction_no']
            ?? ''
        ));
        if ($methodCode !== '' && $transactionNo !== '') {
            return [
                'method_code' => $methodCode,
                'transaction_no' => $transactionNo,
                'target_scope' => PaymentBrowserCallbackRoutes::isStorageScope($targetScope)
                    ? $targetScope
                    : '',
                'gateway_token' => $this->gatewayToken($params),
                'source' => 'explicit_codes',
            ];
        }

        $gatewayToken = $this->gatewayToken($params);
        if ($methodCode !== '' && $gatewayToken !== '') {
            return [
                'method_code' => $methodCode,
                'transaction_no' => $gatewayToken,
                'target_scope' => PaymentBrowserCallbackRoutes::isStorageScope($targetScope)
                    ? $targetScope
                    : '',
                'gateway_token' => $gatewayToken,
                'source' => 'method_code_gateway_token',
            ];
        }

        if ($methodCode !== '') {
            return [
                'method_code' => $methodCode,
                'transaction_no' => $gatewayToken,
                'target_scope' => PaymentBrowserCallbackRoutes::isStorageScope($targetScope)
                    ? $targetScope
                    : '',
                'gateway_token' => $gatewayToken,
                'source' => 'method_code_only',
            ];
        }

        if ($gatewayToken !== '') {
            return [
                'method_code' => '',
                'transaction_no' => $gatewayToken,
                'target_scope' => PaymentBrowserCallbackRoutes::isStorageScope($targetScope)
                    ? $targetScope
                    : '',
                'gateway_token' => $gatewayToken,
                'source' => 'gateway_token_only',
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function gatewayToken(array $params): string
    {
        foreach (['token', 'order_id', 'session_id', 'out_trade_no', 'payment_intent', 'transaction_id'] as $key) {
            $value = trim((string) ($params[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}

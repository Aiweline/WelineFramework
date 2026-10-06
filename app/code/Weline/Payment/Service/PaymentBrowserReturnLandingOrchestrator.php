<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Payment\Model\PaymentTransaction;

/**
 * Decides L1/L2/status landing after browser return success, and cancel → Checkout L3.
 */
final class PaymentBrowserReturnLandingOrchestrator
{
    public const DECISION_TERMINAL_L1 = 'terminal_l1';
    public const DECISION_HANDOFF_L2 = 'handoff_l2';
    public const DECISION_STATUS_PAGE = 'status_page';
    public const DECISION_CHECKOUT_LANDING_CANCEL = 'checkout_landing_cancel';
    public const DECISION_EXPRESS_REVIEW = 'express_review';

    public function __construct(
        private readonly PaymentCheckoutSessionPersistenceService $sessionPersistence,
        private readonly PaymentShellCallbackUrlCatalog $urlCatalog,
        private readonly PaymentStorefrontLandingUrlService $storefrontUrls,
    ) {
    }

    /**
     * @return array{
     *   decision:string,
     *   redirect_path:string,
     *   redirect_params:array<string,mixed>,
     *   absolute:bool
     * }
     */
    public function decide(?PaymentTransaction $transaction): array
    {
        if ($transaction === null || !$transaction->getId()) {
            return $this->statusPage([]);
        }

        $requestData = $transaction->getRequestData();
        if (!is_array($requestData)) {
            $requestData = [];
        }
        if (
            $transaction->isProcessing()
            && ExpressCheckoutOrchestrator::isExpressAwaitingConfirm($requestData)
        ) {
            return $this->decideExpressReview($transaction);
        }

        if (!$transaction->isSuccess()) {
            return $this->statusPage([
                'transaction_no' => (string) $transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO),
            ]);
        }

        $session = $this->sessionPersistence->loadByTransaction($transaction);
        if ($session === null) {
            return $this->bindAbsoluteIfPossible(
                $this->terminalL1($transaction),
                $transaction,
                null,
            );
        }

        $landing = $this->sessionPersistence->landingSnapshot($session);
        if ($landing['browser_landing_url'] === '') {
            return $this->bindAbsoluteIfPossible(
                $this->terminalL1($transaction),
                $transaction,
                $landing,
            );
        }

        return $this->bindAbsoluteIfPossible([
            'decision' => self::DECISION_HANDOFF_L2,
            'redirect_path' => 'payment/handoff',
            'redirect_params' => [
                'checkout_session_code' => $landing['checkout_session_code'],
            ],
            'absolute' => false,
        ], $transaction, $landing);
    }

    /**
     * Express PayPal return after approve: review address/shipping before capture.
     *
     * @return array{
     *   decision:string,
     *   redirect_path:string,
     *   redirect_params:array<string,mixed>,
     *   absolute:bool
     * }
     */
    public function decideExpressReview(?PaymentTransaction $transaction): array
    {
        $transactionNo = '';
        if ($transaction !== null && $transaction->getId()) {
            $transactionNo = trim((string) $transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO));
        }

        $decision = [
            'decision' => self::DECISION_EXPRESS_REVIEW,
            'redirect_path' => 'checkout/express-review',
            'redirect_params' => array_filter([
                'transaction_no' => $transactionNo !== '' ? $transactionNo : null,
                'checkout_group_uuid' => $this->expressCheckoutGroupUuid($transaction),
            ], static fn ($v) => $v !== null && $v !== ''),
            'absolute' => false,
        ];

        return $this->bindAbsoluteIfPossible($decision, $transaction, null);
    }

    private function expressCheckoutGroupUuid(?PaymentTransaction $transaction): string
    {
        if ($transaction === null || !$transaction->getId()) {
            return '';
        }
        $request = $transaction->getRequestData();
        if (!is_array($request)) {
            return '';
        }

        return trim((string) ($request['checkout_group_uuid'] ?? ''));
    }

    /**
     * Cancel / failure browser return: prefer Checkout L3 landing (same as paid success target),
     * otherwise status page with outcome=cancel (never bare empty-transaction copy).
     *
     * @param PaymentBrowserCallbackRoutes::CANCEL_STATE_* $cancelState
     * @return array{
     *   decision:string,
     *   redirect_path:string,
     *   redirect_params:array<string,mixed>,
     *   absolute:bool
     * }
     */
    public function decideCancel(
        ?PaymentTransaction $transaction,
        string $cancelState = PaymentBrowserCallbackRoutes::CANCEL_STATE_DONE,
    ): array {
        $cancelState = $cancelState === PaymentBrowserCallbackRoutes::CANCEL_STATE_ALREADY
            ? PaymentBrowserCallbackRoutes::CANCEL_STATE_ALREADY
            : PaymentBrowserCallbackRoutes::CANCEL_STATE_DONE;
        $cancelParams = [
            PaymentBrowserCallbackRoutes::QUERY_OUTCOME => PaymentBrowserCallbackRoutes::OUTCOME_CANCEL,
            PaymentBrowserCallbackRoutes::QUERY_CANCEL_STATE => $cancelState,
        ];

        if ($transaction !== null && $transaction->getId()) {
            $transactionNo = trim((string) $transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO));
            if ($transactionNo !== '') {
                $cancelParams[PaymentBrowserCallbackRoutes::QUERY_TRANSACTION_NO] = $transactionNo;
            }

            $session = $this->sessionPersistence->loadByTransaction($transaction);
            if ($session !== null) {
                $landing = $this->sessionPersistence->landingSnapshot($session);
                if ($landing['browser_landing_url'] !== '') {
                    $url = $this->mergeLandingUrl(
                        $landing['browser_landing_url'],
                        array_replace(
                            \is_array($landing['browser_landing_params']) ? $landing['browser_landing_params'] : [],
                            $cancelParams,
                        ),
                    );
                    $base = (string) ($landing['storefront_base_url'] ?? '');
                    if ($base !== '') {
                        $url = $this->storefrontUrls->ensureLandingUnderBase($url, $base);
                    }

                    return [
                        'decision' => self::DECISION_CHECKOUT_LANDING_CANCEL,
                        'redirect_path' => $url,
                        'redirect_params' => [],
                        'absolute' => true,
                    ];
                }
            }

            $orderUuid = trim((string) $transaction->getData(PaymentTransaction::schema_fields_ORDER_ID));
            if ($orderUuid !== '') {
                return $this->bindAbsoluteIfPossible([
                    'decision' => self::DECISION_CHECKOUT_LANDING_CANCEL,
                    'redirect_path' => 'checkout/success',
                    'redirect_params' => array_replace($cancelParams, [
                        'order_uuid' => $orderUuid,
                    ]),
                    'absolute' => false,
                ], $transaction, $session !== null ? $this->sessionPersistence->landingSnapshot($session) : null);
            }

            return $this->statusPage($cancelParams);
        }

        return $this->statusPage($cancelParams);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{decision:string,redirect_path:string,redirect_params:array<string,mixed>,absolute:bool}
     */
    private function statusPage(array $params): array
    {
        return [
            'decision' => self::DECISION_STATUS_PAGE,
            'redirect_path' => $this->urlCatalog->transactionStatusPath(),
            'redirect_params' => $params,
            'absolute' => false,
        ];
    }

    /**
     * @return array{decision:string,redirect_path:string,redirect_params:array<string,mixed>,absolute:bool}
     */
    private function terminalL1(PaymentTransaction $transaction): array
    {
        return [
            'decision' => self::DECISION_TERMINAL_L1,
            'redirect_path' => 'payment/success',
            'redirect_params' => [
                'transaction_no' => (string) $transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO),
            ],
            'absolute' => false,
        ];
    }

    /**
     * When storefront base is known, emit absolute redirect under that mount.
     *
     * @param array{decision:string,redirect_path:string,redirect_params:array<string,mixed>,absolute:bool} $decision
     * @param array<string, mixed>|null $landing
     * @return array{decision:string,redirect_path:string,redirect_params:array<string,mixed>,absolute:bool}
     */
    private function bindAbsoluteIfPossible(
        array $decision,
        ?PaymentTransaction $transaction,
        ?array $landing,
    ): array {
        if (!empty($decision['absolute'])) {
            return $decision;
        }

        $path = (string) ($decision['redirect_path'] ?? '');
        if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $decision;
        }

        $websiteId = 0;
        $storefrontBase = '';
        if (\is_array($landing)) {
            $websiteId = max(0, (int) ($landing['website_id'] ?? 0));
            $storefrontBase = trim((string) ($landing['storefront_base_url'] ?? ''));
        }
        if ($transaction !== null && $transaction->getId()) {
            $request = $transaction->getRequestData();
            if (\is_array($request)) {
                if ($websiteId <= 0) {
                    $websiteId = max(0, (int) ($request['website_id'] ?? 0));
                }
                if ($storefrontBase === '') {
                    $storefrontBase = trim((string) ($request['storefront_base_url'] ?? ''));
                }
            }
        }

        $base = $this->storefrontUrls->resolveStorefrontBaseUrl($websiteId, $storefrontBase);
        if ($base === '') {
            return $decision;
        }

        $params = \is_array($decision['redirect_params'] ?? null) ? $decision['redirect_params'] : [];
        $absolute = $this->storefrontUrls->buildAbsoluteRoute($path, $params, $base, '', $websiteId);

        return [
            'decision' => $decision['decision'],
            'redirect_path' => $absolute,
            'redirect_params' => [],
            'absolute' => true,
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function mergeLandingUrl(string $baseUrl, array $params): string
    {
        $parts = parse_url($baseUrl);
        if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $baseUrl;
        }

        $query = [];
        if (isset($parts['query']) && \is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
            if (!\is_array($query)) {
                $query = [];
            }
        }
        foreach ($params as $key => $value) {
            if (\is_scalar($value) || $value === null) {
                $query[(string) $key] = $value;
            }
        }

        $rebuilt = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';
        if ($query !== []) {
            $rebuilt .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        if (!empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }
}

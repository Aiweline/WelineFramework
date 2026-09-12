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
            return $this->terminalL1($transaction);
        }

        $landing = $this->sessionPersistence->landingSnapshot($session);
        if ($landing['browser_landing_url'] === '') {
            return $this->terminalL1($transaction);
        }

        return [
            'decision' => self::DECISION_HANDOFF_L2,
            'redirect_path' => 'payment/handoff',
            'redirect_params' => [
                'checkout_session_code' => $landing['checkout_session_code'],
            ],
            'absolute' => false,
        ];
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

        return [
            'decision' => self::DECISION_EXPRESS_REVIEW,
            'redirect_path' => 'checkout/express-review',
            'redirect_params' => $transactionNo !== ''
                ? ['transaction_no' => $transactionNo]
                : [],
            'absolute' => false,
        ];
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
                return [
                    'decision' => self::DECISION_CHECKOUT_LANDING_CANCEL,
                    'redirect_path' => 'checkout/success',
                    'redirect_params' => array_replace($cancelParams, [
                        'order_uuid' => $orderUuid,
                    ]),
                    'absolute' => false,
                ];
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

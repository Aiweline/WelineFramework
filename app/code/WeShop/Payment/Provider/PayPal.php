<?php

declare(strict_types=1);

namespace WeShop\Payment\Provider;

use WeShop\Order\Model\Order;
use WeShop\Payment\Interface\PaymentProviderInterface;
use Weline\Framework\App\Env;

class PayPal implements PaymentProviderInterface
{
    private bool $sandbox;

    public function __construct()
    {
        $this->sandbox = (bool) Env::getInstance()->getConfig('payment.paypal.sandbox', true);
    }

    public function processPayment(Order $order, array $paymentData = [], array $context = []): array
    {
        $orderReference = $this->getOrderReference($order);
        $total = (float) ($order->getData(Order::schema_fields_total) ?? 0);
        $currency = $context['currency'] ?? 'USD';

        try {
            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                return $this->fallbackRedirect($order, $orderReference);
            }

            $baseUrl = $this->sandbox
                ? 'https://api-m.sandbox.paypal.com'
                : 'https://api-m.paypal.com';

            $returnUrl = ($context['return_url'] ?? '')
                ?: Env::getInstance()->getConfig('payment.paypal.return_url', '');
            $cancelUrl = ($context['cancel_url'] ?? '')
                ?: Env::getInstance()->getConfig('payment.paypal.cancel_url', '');

            $requestData = [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'reference_id' => $orderReference,
                        'amount' => [
                            'currency_code' => $currency,
                            'value' => number_format($total, 2, '.', ''),
                        ],
                        'description' => sprintf('Order #%s', $orderReference),
                    ],
                ],
                'payment_source' => [
                    'paypal' => [
                        'experience_context' => [
                            'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
                            'landing_page' => 'LOGIN',
                            'user_action' => 'PAY_NOW',
                            'return_url' => $returnUrl,
                            'cancel_url' => $cancelUrl,
                        ],
                    ],
                ],
            ];

            $response = $this->httpPost(
                $baseUrl . '/v2/checkout/orders',
                json_encode($requestData),
                [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                    'PayPal-Request-Id: ' . $orderReference . '-' . time(),
                ]
            );

            $result = json_decode($response, true);

            if (!empty($result['id']) && !empty($result['links'])) {
                $approvalUrl = '';
                foreach ($result['links'] as $link) {
                    if (($link['rel'] ?? '') === 'payer-action') {
                        $approvalUrl = $link['href'] ?? '';
                        break;
                    }
                }
                if ($approvalUrl === '') {
                    foreach ($result['links'] as $link) {
                        if (($link['rel'] ?? '') === 'approve') {
                            $approvalUrl = $link['href'] ?? '';
                            break;
                        }
                    }
                }

                return [
                    'status' => 'pending',
                    'requires_action' => true,
                    'redirect_url' => $approvalUrl,
                    'paypal_order_id' => $result['id'],
                    'payment_params' => [
                        'intent' => 'CAPTURE',
                        'order_reference' => $orderReference,
                        'paypal_order_id' => $result['id'],
                    ],
                ];
            }

            w_log_error('PayPal order creation returned unexpected response', [
                'response' => $result,
            ], 'weshop_payment');

            return $this->fallbackRedirect($order, $orderReference);
        } catch (\Exception $e) {
            w_log_error('PayPal payment processing failed', [
                'error' => $e->getMessage(),
                'order' => $orderReference,
            ], 'weshop_payment');

            return $this->fallbackRedirect($order, $orderReference);
        }
    }

    public function handleCallback(array $callbackData, array $context = []): bool
    {
        $paypalOrderId = $callbackData['token'] ?? $callbackData['paypal_order_id'] ?? '';

        if ($paypalOrderId === '') {
            return false;
        }

        try {
            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                return false;
            }

            $baseUrl = $this->sandbox
                ? 'https://api-m.sandbox.paypal.com'
                : 'https://api-m.paypal.com';

            // Capture the payment
            $response = $this->httpPost(
                $baseUrl . '/v2/checkout/orders/' . urlencode($paypalOrderId) . '/capture',
                '',
                [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                ]
            );

            $result = json_decode($response, true);
            $status = $result['status'] ?? '';

            return $status === 'COMPLETED';
        } catch (\Exception $e) {
            w_log_error('PayPal callback handling failed', [
                'error' => $e->getMessage(),
                'paypal_order_id' => $paypalOrderId,
            ], 'weshop_payment');

            return false;
        }
    }

    public function queryPaymentStatus(string $orderReference, array $context = []): string
    {
        $paypalOrderId = $context['paypal_order_id'] ?? '';

        if ($paypalOrderId === '') {
            return 'unknown';
        }

        try {
            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                return 'unknown';
            }

            $baseUrl = $this->sandbox
                ? 'https://api-m.sandbox.paypal.com'
                : 'https://api-m.paypal.com';

            $response = $this->httpGet(
                $baseUrl . '/v2/checkout/orders/' . urlencode($paypalOrderId),
                ['Authorization: Bearer ' . $accessToken]
            );

            $result = json_decode($response, true);
            $status = $result['status'] ?? '';

            $statusMap = [
                'COMPLETED' => 'paid',
                'APPROVED' => 'pending',
                'CREATED' => 'pending',
                'VOIDED' => 'cancelled',
            ];

            return $statusMap[$status] ?? 'pending';
        } catch (\Exception $e) {
            w_log_error('PayPal payment status query failed', [
                'error' => $e->getMessage(),
                'paypal_order_id' => $paypalOrderId,
            ], 'weshop_payment');

            return 'unknown';
        }
    }

    private function getAccessToken(): ?string
    {
        $clientId = Env::getInstance()->getConfig('payment.paypal.client_id', '');
        $clientSecret = Env::getInstance()->getConfig('payment.paypal.client_secret', '');

        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $baseUrl = $this->sandbox
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';

        try {
            $response = $this->httpPost(
                $baseUrl . '/v1/oauth2/token',
                'grant_type=client_credentials',
                [
                    'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
                    'Content-Type: application/x-www-form-urlencoded',
                ]
            );

            $result = json_decode($response, true);
            return $result['access_token'] ?? null;
        } catch (\Exception $e) {
            w_log_error('PayPal OAuth token retrieval failed', [
                'error' => $e->getMessage(),
            ], 'weshop_payment');

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackRedirect(Order $order, string $orderReference): array
    {
        $baseUrl = $this->sandbox
            ? 'https://www.sandbox.paypal.com'
            : 'https://www.paypal.com';

        return [
            'status' => 'pending',
            'requires_action' => true,
            'redirect_url' => $baseUrl . '/checkoutnow?token=' . rawurlencode($orderReference),
            'payment_params' => [
                'intent' => 'CAPTURE',
                'order_reference' => $orderReference,
            ],
        ];
    }

    private function getOrderReference(Order $order): string
    {
        if (method_exists($order, 'getIncrementId')) {
            $ref = (string) $order->getIncrementId();
            if ($ref !== '') {
                return $ref;
            }
        }
        if (defined(Order::class . '::schema_fields_increment_id')) {
            $ref = (string) ($order->getData(Order::schema_fields_increment_id) ?? '');
            if ($ref !== '') {
                return $ref;
            }
        }
        return (string) $order->getId();
    }

    private function httpPost(string $url, string $data, array $headers = []): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false || $httpCode >= 400) {
            $error = curl_error($ch) ?: "HTTP {$httpCode}";
            curl_close($ch);
            throw new \RuntimeException("PayPal API request failed: {$error}");
        }

        curl_close($ch);
        return $response ?: '';
    }

    private function httpGet(string $url, array $headers = []): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false || $httpCode >= 400) {
            $error = curl_error($ch) ?: "HTTP {$httpCode}";
            curl_close($ch);
            throw new \RuntimeException("PayPal API request failed: {$error}");
        }

        curl_close($ch);
        return $response ?: '';
    }
}

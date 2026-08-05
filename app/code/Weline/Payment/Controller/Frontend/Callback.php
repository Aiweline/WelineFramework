<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Api\Webhook\WebhookReceiveResult;
use Weline\Payment\Service\PaymentCallbackReceiver;

/**
 * 支付回调通知（MOD-P2F-003）：endpoint → raw verify → pure parse →
 * immutable inbox → commit 后 2xx。这里不直接修改支付状态。
 */
class Callback extends FrontendController
{
    private PaymentCallbackReceiver $receiver;

    public function __construct(
        ObjectManager $objectManager
    ) {
        $this->receiver = $objectManager->getInstance(PaymentCallbackReceiver::class);
    }

    /**
     * 支付回调通知
     */
    public function notify()
    {
        $endpointCode = trim((string) $this->request->getParam('endpoint_code'));
        if ($endpointCode === '') {
            $endpointCode = trim((string) $this->request->getBodyParam('endpoint_code'));
        }
        $bodyParams = $this->request->getBodyParams(true);
        $callbackData = array_merge(
            (array) $this->request->getParams(),
            \is_array($bodyParams) ? $bodyParams : [],
        );

        try {
            if ($endpointCode === '') {
                throw new \RuntimeException('payment_webhook_endpoint_required');
            }

            $result = $this->receiveViaInbox(
                $endpointCode,
                $this->rawBody(),
                $callbackData,
            );
            $this->request->getResponse()->setHttpResponseCode($result->httpStatus);
            echo $result->body;
        } catch (\Throwable $e) {
            w_log_error('支付 webhook inbox 接收失败: ' . $e->getMessage());
            $this->request->getResponse()->setHttpResponseCode(500);
            echo 'retry';
        }
    }

    /**
     * @param array<string, mixed> $callbackData
     */
    private function receiveViaInbox(string $endpointCode, string $rawBody, array $callbackData): WebhookReceiveResult
    {
        $headers = $this->headers();
        $normalizedHeaders = array_change_key_case($headers, CASE_LOWER);
        $signature = (string) (
            $callbackData['signature']
            ?? $normalizedHeaders['x-signature']
            ?? ''
        );
        $timestamp = $callbackData['timestamp']
            ?? $normalizedHeaders['x-webhook-timestamp']
            ?? null;

        return $this->receiver->receive(
            endpointCode: $endpointCode,
            rawBody: $rawBody,
            headers: $headers,
            payload: $callbackData,
            signature: $signature !== '' ? $signature : null,
            providerTimestamp: $timestamp !== null ? (int) $timestamp : null,
        );
    }

    private function rawBody(): string
    {
        if (method_exists($this->request, 'getRawBody')) {
            return (string) $this->request->getRawBody();
        }
        if (method_exists($this->request, 'getParameterBag')) {
            return (string) $this->request->getParameterBag()->getRawBody();
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function headers(): array
    {
        if (method_exists($this->request, 'getHeaders')) {
            return (array) $this->request->getHeaders();
        }
        $headers = $this->request->getHeader('');

        return \is_array($headers) ? $headers : [];
    }

}

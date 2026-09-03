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
use Weline\Payment\Service\PaymentBrowserCallbackRoutes;
use Weline\Payment\Service\PaymentBrowserCancelDispatcher;
use Weline\Payment\Service\PaymentBrowserReturnDispatcher;
use Weline\Payment\Service\PaymentCallbackReceiver;
use Weline\Payment\Service\PaymentShellCallbackUrlCatalog;

/**
 * 支付统一回调入口：
 * - browser-return-entry：浏览器 OAuth / Provider 回跳（公网路径 callback/{method_code}）
 * - notify：服务端 Webhook Inbox（MOD-P2F-003）
 */
class Callback extends FrontendController
{
    private PaymentCallbackReceiver $receiver;
    private PaymentBrowserReturnDispatcher $browserReturn;
    private PaymentBrowserCancelDispatcher $browserCancel;

    public function __construct(
        ObjectManager $objectManager
    ) {
        $this->receiver = $objectManager->getInstance(PaymentCallbackReceiver::class);
        $this->browserReturn = $objectManager->getInstance(PaymentBrowserReturnDispatcher::class);
        $this->browserCancel = $objectManager->getInstance(PaymentBrowserCancelDispatcher::class);
    }

    /**
     * 统一浏览器回跳（OAuth / Provider / cancel via outcome=cancel）。
     *
     * @Cdn cache=false description="支付浏览器回跳入口禁止全页缓存"
     */
    public function browserReturnEntry(): string
    {
        $params = $this->browserCallbackParams();

        if (trim((string) ($params[PaymentBrowserCallbackRoutes::QUERY_METHOD_CODE] ?? '')) === '') {
            $this->noRouter();
        }

        if (PaymentBrowserCallbackRoutes::isCancelOutcome($params)) {
            return $this->dispatchCancel($params);
        }

        $dispatched = $this->browserReturn->dispatch($params);
        if (!empty($dispatched['no_router'])) {
            $this->request->getResponse()->noRouter(401, 'payment_callback_context_required');
        }
        if (!empty($dispatched['render'])) {
            // 统一 Return URL 探活页：独立文档，避免商城 default/checkout 壳把状态卡淹没。
            $this->layoutType = null;
            $title = (string) ($dispatched['title'] ?? __('支付浏览器回跳入口'));
            $this->assign('page_title', $title);
            $this->assign('browser_return_status', (string) ($dispatched['status'] ?? 'ready'));
            $this->assign('browser_return_title', $title);
            $this->assign('browser_return_message', (string) ($dispatched['message'] ?? ''));

            return $this->fetch((string) ($dispatched['template'] ?? 'browser-return'));
        }

        return $this->redirectDispatched($dispatched);
    }

    /**
     * @deprecated 公网已合并到 browserReturnEntry + outcome=cancel；保留内部兼容。
     *
     * @Cdn cache=false description="支付浏览器取消回跳入口禁止全页缓存"
     */
    public function browserCancelEntry(): string
    {
        return $this->dispatchCancel($this->browserCallbackParams());
    }

    /**
     * @param array<string, mixed> $params
     */
    private function dispatchCancel(array $params): string
    {
        if (trim((string) ($params[PaymentBrowserCallbackRoutes::QUERY_METHOD_CODE] ?? '')) === '') {
            $this->noRouter();
        }

        $dispatched = $this->browserCancel->dispatch($params);
        if (!empty($dispatched['no_router'])) {
            $this->request->getResponse()->noRouter(401, 'payment_callback_context_required');
        }

        return $this->redirectDispatched($dispatched);
    }

    /**
     * @param array<string, mixed> $dispatched
     */
    private function redirectDispatched(array $dispatched): string
    {
        $path = (string) ($dispatched['redirect_path'] ?? ObjectManager::getInstance(PaymentShellCallbackUrlCatalog::class)->transactionStatusPath());
        $redirectParams = \is_array($dispatched['redirect_params'] ?? null)
            ? $dispatched['redirect_params']
            : [];

        if (!empty($dispatched['absolute']) && (str_starts_with($path, 'http://') || str_starts_with($path, 'https://'))) {
            return $this->redirect($path);
        }

        return $this->redirect($this->getUrl($path, $redirectParams));
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
    private function browserCallbackParams(): array
    {
        $params = $this->request->getParams();
        if (!\is_array($params)) {
            $params = [];
        }

        $methodCode = trim((string) ($params[PaymentBrowserCallbackRoutes::QUERY_METHOD_CODE] ?? ''));
        if ($methodCode === '') {
            $fromRule = $this->request->getData(PaymentBrowserCallbackRoutes::QUERY_METHOD_CODE);
            $methodCode = trim((string) ($fromRule ?? ''));
        }
        if ($methodCode === '') {
            $methodCode = trim((string) (\Weline\Framework\Context::current()->get('input.query.method_code') ?? ''));
        }
        if ($methodCode === '') {
            $uri = (string) $this->request->getUrlPath();
            if ($uri === '') {
                $uri = (string) ($this->request->getServer('WELINE_ORIGIN_REQUEST_URI')
                    ?? $this->request->getServer('REQUEST_URI')
                    ?? '');
            }
            if ($uri !== ''
                && preg_match('#/callback/([a-z0-9][a-z0-9_.-]*)(?:/|\?|$)#i', $uri, $match) === 1
            ) {
                $candidate = strtolower((string) $match[1]);
                if ($candidate !== '' && !PaymentBrowserCallbackRoutes::isReservedCallbackSegment($candidate)) {
                    $methodCode = $candidate;
                }
            }
        }
        if ($methodCode !== '') {
            $params[PaymentBrowserCallbackRoutes::QUERY_METHOD_CODE] = $methodCode;
        }

        return $params;
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

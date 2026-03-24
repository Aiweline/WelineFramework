<?php

declare(strict_types=1);

namespace WeShop\Payment\Controller\Frontend\Payment;

use WeShop\Payment\Service\PaymentService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

class Callback extends FrontendController
{
    public function index(): string
    {
        try {
            $paymentMethod = (string) ($this->request->getParam('payment_method') ?? '');
            if ($paymentMethod === '') {
                throw new \InvalidArgumentException((string) __('Payment method is required.'));
            }

            return $this->fetchJson([
                'success' => $this->getPaymentService()->handleCallback($paymentMethod, $this->readCallbackData()),
                'message' => __('Payment callback handled.'),
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    public function post(): string
    {
        return $this->index();
    }

    protected function getPaymentService(): PaymentService
    {
        return ObjectManager::getInstance(PaymentService::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function readCallbackData(): array
    {
        $params = $this->request->getParams();
        $params = \is_array($params) ? $params : [];

        $bodyParams = $this->request->getBodyParams(true);
        if (\is_array($bodyParams) && $bodyParams !== []) {
            $params = array_merge($params, $bodyParams);
        }

        $rawBody = $this->request->getBodyParams();
        if (\is_string($rawBody) && trim($rawBody) !== '') {
            $params['raw_body'] = $rawBody;
        }

        $contentType = trim((string) $this->request->getContentType());
        if ($contentType !== '') {
            $params['content_type'] = $contentType;
        }

        return $params;
    }
}

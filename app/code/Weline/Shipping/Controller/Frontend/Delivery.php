<?php

declare(strict_types=1);

namespace Weline\Shipping\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Shipping\Service\AddressFormatter;
use Weline\Shipping\Service\DeliveryAddressService;

class Delivery extends FrontendController
{
    protected ?string $layoutType = 'account.dashboard';

    private DeliveryAddressService $service;
    private AddressFormatter $addressFormatter;
    private AuthenticatedSessionInterface $frontendSession;

    public function __construct(ObjectManager $objectManager)
    {
        $this->service = $objectManager->getInstance(DeliveryAddressService::class);
        $this->addressFormatter = $objectManager->getInstance(AddressFormatter::class);
        $this->frontendSession = SessionFactory::getInstance()->createFrontendSession();
    }

    public function postSave(): string
    {
        $customerId = $this->getCustomerId();
        if ($customerId <= 0) {
            return $this->json([
                'success' => false,
                'message' => __('请先登录'),
            ]);
        }

        $data = $this->request->getPost();
        $id = $data['delivery_address_id'] ?? null;

        try {
            if ($id) {
                $address = $this->service->update((int)$id, $data, $customerId);
                $message = __('更新成功');
            } else {
                $address = $this->service->create($customerId, $data);
                $message = __('创建成功');
            }

            return $this->json([
                'success' => true,
                'message' => $message,
                'data' => $this->addressFormatter->toPayload($address->getData()),
            ]);
        } catch (\Throwable $throwable) {
            return $this->json([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    public function postDelete(): string
    {
        $customerId = $this->getCustomerId();
        if ($customerId <= 0) {
            return $this->json([
                'success' => false,
                'message' => __('请先登录'),
            ]);
        }

        $id = (int)$this->request->getPost('id');
        if ($id <= 0) {
            return $this->json([
                'success' => false,
                'message' => __('参数错误'),
            ]);
        }

        try {
            $this->service->delete($id, $customerId);

            return $this->json([
                'success' => true,
                'message' => __('删除成功'),
            ]);
        } catch (\Throwable $throwable) {
            return $this->json([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    public function postSetDefault(): string
    {
        $customerId = $this->getCustomerId();
        if ($customerId <= 0) {
            return $this->json([
                'success' => false,
                'message' => __('请先登录'),
            ]);
        }

        $id = (int)$this->request->getPost('id');
        if ($id <= 0) {
            return $this->json([
                'success' => false,
                'message' => __('参数错误'),
            ]);
        }

        try {
            $this->service->setDefault($id, $customerId);

            return $this->json([
                'success' => true,
                'message' => __('设置成功'),
            ]);
        } catch (\Throwable $throwable) {
            return $this->json([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    private function json(array $data): string
    {
        header('Content-Type: application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function getCustomerId(): int
    {
        if ($this->frontendSession->isLoggedIn()) {
            return (int)$this->frontendSession->getUserId();
        }

        if ($this->isLoggedIn()) {
            return (int)$this->getLoginUserId();
        }

        return 0;
    }
}

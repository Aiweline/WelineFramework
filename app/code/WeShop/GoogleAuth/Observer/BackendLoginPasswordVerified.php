<?php

declare(strict_types=1);

namespace WeShop\GoogleAuth\Observer;

use WeShop\GoogleAuth\Service\BackendWebAuthService;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 在后台密码校验通过后接管 WebAuth / 2FA 登录流程。
 */
class BackendLoginPasswordVerified implements ObserverInterface
{
    public function __construct(
        private readonly BackendWebAuthService $backendWebAuthService
    ) {
    }

    public function execute(Event &$event): void
    {
        /** @var DataObject|null $data */
        $data = $event->getData('data');
        if (!$data instanceof DataObject || $data->getData('handled')) {
            return;
        }

        $user = $data->getData('user');
        if (!$user instanceof BackendUser || !$user->getId()) {
            return;
        }

        try {
            $result = $this->backendWebAuthService->beginLoginForBackendUser(
                $user,
                (string) ($data->getData('auth_method') ?: 'password'),
                (bool) $data->getData('remember'),
                (string) ($data->getData('redirect_url') ?? '')
            );
            $data->setData('result', $result);
            $data->setData('handled', true);
        } catch (\Throwable $throwable) {
            $data->setData('error', $throwable);
            $data->setData('handled', true);
        }
    }
}

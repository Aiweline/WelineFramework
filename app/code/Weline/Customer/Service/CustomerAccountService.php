<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

use Weline\Customer\Model\Customer;
use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;

/**
 * Core storefront customer registration/session helpers (no business-shop coupling).
 */
class CustomerAccountService
{
    public function __construct(
        private readonly Customer $customerModel,
        private readonly SessionFactory $sessionFactory,
        private readonly Request $request,
        private readonly ?EventsManager $eventsManager = null
    ) {
    }

    public function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function validatePasswordStrength(string $password): void
    {
        $lengthOkay = strlen($password) >= 8;
        $hasLetter = preg_match('/[A-Za-z]/', $password) === 1;
        $hasDigit = preg_match('/\d/', $password) === 1;

        if (!$lengthOkay || !$hasLetter || !$hasDigit) {
            throw new \InvalidArgumentException((string) __('密码必须至少8个字符，并且包含字母和数字.'));
        }
    }

    public function findByEmail(string $email): ?Customer
    {
        $email = $this->normalizeEmail($email);
        $customer = $this->customerModel->reset()
            ->where(Customer::schema_fields_email, $email)
            ->find()
            ->fetch();

        return $customer->getId() ? $customer : null;
    }

    /**
     * @return array{customer: Customer}
     */
    public function register(string $email, string $password, array $profileData = []): array
    {
        $email = $this->normalizeEmail($email);
        $this->validatePasswordStrength($password);

        if ($this->findByEmail($email)) {
            throw new \RuntimeException((string) __('该邮箱已存在，请使用其他邮箱注册.'));
        }

        $beforePayload = new DataObject([
            'email' => $email,
            'profile_data' => $profileData,
            'referral_code' => (string) ($profileData['referral_code'] ?? $profileData['ref'] ?? ''),
            'request' => $this->request,
        ]);
        $this->eventsManager()->dispatch('Weline_Frontend_Account_Register::register_before', $beforePayload);

        $customer = $this->customerModel->reset()->clearData();
        $customer->setEmail($email)->setUsername($email)->setPassword($password)->save();

        $afterPayload = new DataObject([
            'user' => $customer,
            'customer' => $customer,
            'customer_id' => (int) ($customer->getId() ?? 0),
            'email' => $email,
            'profile_data' => $profileData,
            'referral_code' => (string) ($profileData['referral_code'] ?? $profileData['ref'] ?? ''),
            'request' => $this->request,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->eventsManager()->dispatch('Weline_Frontend_Account_Register::register_after', $afterPayload);

        return ['customer' => $customer];
    }

    public function loginCustomer(Customer $customer): void
    {
        $session = $this->sessionFactory->createFrontendSession();
        $session->login($customer);

        $customer->setSessionId($session->getId())
            ->setLoginIp($this->request->clientIP())
            ->resetAttemptTimes()
            ->save();

        $this->syncSandboxCookie($customer->isSandboxAccount());

        /** @var EventsManager $eventManager */
        $eventManager = ObjectManager::getInstance(EventsManager::class);
        $eventData = new \Weline\Framework\DataObject\DataObject([
            'user' => $customer,
            'request' => $this->request,
            'session' => $session,
        ]);
        $eventManager->dispatch('Weline_Customer_Account_Login::login_after', $eventData);
    }

    private function syncSandboxCookie(bool $enabled): void
    {
        $lifetime = $enabled ? 0 : -1;
        Cookie::set('w_sandbox', $enabled ? '1' : '', $lifetime, ['path' => '/']);
        $adminPath = Env::getAreaRoutePrefix('backend') ?? '';
        if ($adminPath !== '') {
            Cookie::set('w_sandbox', $enabled ? '1' : '', $lifetime, ['path' => '/' . ltrim($adminPath, '/')]);
        }
    }

    private function eventsManager(): EventsManager
    {
        return $this->eventsManager ?? ObjectManager::getInstance(EventsManager::class);
    }
}

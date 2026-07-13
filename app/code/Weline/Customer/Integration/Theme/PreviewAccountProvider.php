<?php

declare(strict_types=1);

namespace Weline\Customer\Integration\Theme;

use Weline\Customer\Model\Customer;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Session\Auth\AuthenticableInterface;
use Weline\Theme\Api\PreviewAccountProviderInterface;

final class PreviewAccountProvider implements PreviewAccountProviderInterface
{
    public function __construct(
        private readonly Customer $customerModel,
        private readonly EventsManager $eventsManager,
    ) {
    }

    public function ensurePreviewAccount(
        string $username,
        string $email,
        string $plainPassword,
    ): ?AuthenticableInterface {
        $username = trim($username);
        $email = strtolower(trim($email));
        if ($username === '' || $email === '' || $plainPassword === '') {
            throw new \InvalidArgumentException('Preview account credentials must not be empty.');
        }

        try {
            $customer = $this->loadByUsername($username);
            $isNew = !$customer->getId();
            if ($isNew) {
                $customer->clearData()
                    ->setData(Customer::schema_fields_username, $username)
                    ->setEmail($email)
                    ->setPassword($plainPassword)
                    ->save();
            } else {
                $updated = false;
                if ($email !== strtolower(trim((string)$customer->getData(Customer::schema_fields_email)))) {
                    $customer->setEmail($email);
                    $updated = true;
                }
                if (!password_verify($plainPassword, $customer->getPassword())) {
                    $customer->setPassword($plainPassword);
                    $updated = true;
                }
                if ($updated) {
                    $customer->save();
                }
            }
        } catch (\PDOException $exception) {
            if ($this->isMissingCustomerStorage($exception)) {
                return null;
            }
            throw $exception;
        }

        if ($isNew) {
            $this->eventsManager->dispatch(
                'Weline_Frontend_Account_Register::register_after',
                new DataObject([
                    'user' => $customer,
                    'customer' => $customer,
                    'customer_id' => (int)$customer->getId(),
                    'email' => $email,
                    'source' => 'theme_preview',
                ]),
            );
        }

        return $customer;
    }

    public function findPreviewAccountId(string $username): int|string|null
    {
        $username = trim($username);
        if ($username === '') {
            return null;
        }

        try {
            $customer = $this->loadByUsername($username);
        } catch (\PDOException $exception) {
            if ($this->isMissingCustomerStorage($exception)) {
                return null;
            }
            throw $exception;
        }

        return $customer->getId() ? (int)$customer->getId() : null;
    }

    public function recordPreviewLogin(
        AuthenticableInterface $account,
        string $sessionId,
        string $remoteAddress,
    ): void {
        if (!$account instanceof Customer) {
            throw new \InvalidArgumentException('The preview account provider can only persist Customer accounts.');
        }

        $account->setSessionId($sessionId)
            ->setLoginIp($remoteAddress)
            ->save();
    }

    private function loadByUsername(string $username): Customer
    {
        /** @var Customer $customer */
        $customer = (clone $this->customerModel)->clearData()
            ->where(Customer::schema_fields_username, $username)
            ->find()
            ->fetch();

        return $customer;
    }

    private function isMissingCustomerStorage(\PDOException $exception): bool
    {
        $sqlState = strtoupper((string)$exception->getCode());
        if (in_array($sqlState, ['42S02', '42P01'], true)) {
            return true;
        }

        $message = strtolower($exception->getMessage());
        return str_contains($message, 'does not exist')
            || str_contains($message, 'undefined table')
            || str_contains($message, 'no such table')
            || str_contains($message, 'base table or view not found');
    }
}

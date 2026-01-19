<?php

declare(strict_types=1);

namespace WeShop\Customer\Session;

use WeShop\Customer\Model\Customer;

class CustomerSession
{
    private const SESSION_KEY = 'weshop_customer';
    
    public function setCustomer(Customer $customer): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'customer_id' => $customer->getId(),
            'email' => $customer->getData(Customer::fields_EMAIL) ?? '',
            'firstname' => $customer->getData(Customer::fields_FIRST_NAME) ?? '',
            'lastname' => $customer->getData(Customer::fields_LAST_NAME) ?? '',
        ];
    }
    
    public function getCustomer(): ?Customer
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            return null;
        }
        
        $data = $_SESSION[self::SESSION_KEY];
        /** @var Customer $customer */
        $customer = \Weline\Framework\Manager\ObjectManager::getInstance(\WeShop\Customer\Model\Customer::class);
        $customer->load($data['customer_id']);
        
        return $customer->getId() ? $customer : null;
    }
    
    public function isLoggedIn(): bool
    {
        return isset($_SESSION[self::SESSION_KEY]);
    }
    
    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}

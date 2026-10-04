<?php
declare(strict_types=1);

// Local acceptance account only. Existing products, offers, cart and orders are
// never seeded here; those must pass through the actual storefront workflow.
use Weline\Customer\Service\CustomerAccountService;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

try {
    $email = 'daocharms.3d.' . bin2hex(random_bytes(8)) . '@example.test';
    $password = bin2hex(random_bytes(12)) . 'Aa9';
    $accounts = ObjectManager::getInstance()->get(CustomerAccountService::class);
    $accounts->register($email, $password, ['firstname' => 'Demo', 'lastname' => 'Buyer']);
    $customer = $accounts->findByEmail($email);
    if ($customer === null || (int)$customer->getId() <= 0) {
        throw new RuntimeException('Native customer registration did not persist an account');
    }
    echo json_encode(['ok' => true, 'customer_id' => (int)$customer->getId(), 'email' => $email, 'password' => $password], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(1);
}

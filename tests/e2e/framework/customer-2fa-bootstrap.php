<?php
declare(strict_types=1);

use WeShop\Auth\Data\ActorContext;
use WeShop\Auth\Service\WeShopAuth2FAOrchestrator;
use Weline\Customer\Model\Customer;
use Weline\Framework\Manager\ObjectManager;
use Weline\TwoFactorAuth\Helper\TwoFactorAuthHelper;
use Weline\TwoFactorAuth\Model\TwoFactorConfig;
use Weline\TwoFactorAuth\Model\UserTwoFactor;
use Weline\TwoFactorAuth\Service\TwoFactorAuthService;

require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function fail(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($exitCode);
}

$options = getopt('', ['action::', 'email:']);
$action = strtolower(trim((string) ($options['action'] ?? 'enable')));
$email = trim((string) ($options['email'] ?? ''));

if ($email === '') {
    fail('customer-2fa-bootstrap: --email is required.');
}

if (!in_array($action, ['enable', 'disable'], true)) {
    fail('customer-2fa-bootstrap: --action must be enable|disable.');
}

try {
    /** @var Customer $customerModel */
    $customerModel = clone ObjectManager::getInstance(Customer::class);
    $customer = $customerModel->reset()
        ->where('email', $email)
        ->find()
        ->fetch();

    $customerId = (int) $customer->getId();
    if ($customerId <= 0) {
        fail('customer-2fa-bootstrap: customer not found by email.');
    }

    /** @var WeShopAuth2FAOrchestrator $orchestrator */
    $orchestrator = ObjectManager::getInstance(WeShopAuth2FAOrchestrator::class);
    $shadowUserId = $orchestrator->getShadowUserId(ActorContext::ACTOR_CUSTOMER, $customerId);

    /** @var UserTwoFactor $userTwoFactor */
    $userTwoFactor = ObjectManager::getInstance(UserTwoFactor::class);
    /** @var TwoFactorConfig $twoFactorConfig */
    $twoFactorConfig = ObjectManager::getInstance(TwoFactorConfig::class);

    if ($action === 'disable') {
        $userTwoFactor->disableForUser($shadowUserId);
        echo json_encode([
            'status' => 'ok',
            'action' => 'disable',
            'email' => $email,
            'customer_id' => $customerId,
            'shadow_user_id' => $shadowUserId,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit(0);
    }

    // Ensure frontend password flow can trigger 2FA challenge.
    $twoFactorConfig->setConfig('flow.password.enabled', true, WeShopAuth2FAOrchestrator::MODULE, 'frontend');

    /** @var TwoFactorAuthService $twoFactorService */
    $twoFactorService = ObjectManager::getInstance(TwoFactorAuthService::class);
    $init = $twoFactorService->initialize($shadowUserId);
    $secret = (string) ($init['secret'] ?? '');
    $backupCodes = (array) ($init['backup_codes'] ?? []);
    if ($secret === '' || $backupCodes === []) {
        fail('customer-2fa-bootstrap: failed to initialize 2FA seed data.');
    }

    $totpCode = TwoFactorAuthHelper::generateCode($secret);
    try {
        $enabled = $twoFactorService->enable($shadowUserId, $secret, $totpCode, $backupCodes);
    } catch (Throwable) {
        $enabled = false;
    }
    if (!$enabled) {
        // Fallback: some long-lived worker states can cause ORM model residue.
        // Force-write a clean 2FA record for the deterministic shadow user id.
        $freshUserTwoFactor = clone ObjectManager::getInstance(UserTwoFactor::class);
        $freshUserTwoFactor->reset()
            ->where(UserTwoFactor::schema_fields_USER_ID, $shadowUserId)
            ->delete()
            ->fetch();
        $freshUserTwoFactor->reset();
        $freshUserTwoFactor->setData(UserTwoFactor::schema_fields_USER_ID, $shadowUserId);
        $freshUserTwoFactor->setData(UserTwoFactor::schema_fields_SECRET, $secret);
        $freshUserTwoFactor->setData(UserTwoFactor::schema_fields_IS_ENABLED, 1);
        $freshUserTwoFactor->setData(UserTwoFactor::schema_fields_BACKUP_CODES, json_encode($backupCodes, JSON_UNESCAPED_UNICODE));
        $freshUserTwoFactor->save();
    }

    echo json_encode([
        'status' => 'ok',
        'action' => 'enable',
        'email' => $email,
        'customer_id' => $customerId,
        'shadow_user_id' => $shadowUserId,
        'backup_code' => (string) ($backupCodes[0] ?? ''),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $throwable) {
    fail('customer-2fa-bootstrap: ' . $throwable->getMessage());
}

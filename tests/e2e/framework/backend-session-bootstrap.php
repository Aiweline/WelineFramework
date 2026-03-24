<?php
declare(strict_types=1);

use Weline\Backend\Model\BackendUser;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AreaConfig;
use Weline\Framework\Session\Auth\AuthenticatedSession;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\Session;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\Strategy\FpmStrategy;
use Weline\Framework\Session\Strategy\WlsStrategy;

require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function fail(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($exitCode);
}

function getSessionConfig(): array
{
    return (array) Env::getInstance()->getConfig('session');
}

function buildStrategyConfig(array $sessionConfig): array
{
    return [
        'lifetime' => (int) ($sessionConfig['lifetime'] ?? $sessionConfig['session_ttl'] ?? 3600),
        'cookie_path' => $sessionConfig['cookie_path'] ?? '/',
        'cookie_domain' => $sessionConfig['cookie_domain'] ?? '',
        'cookie_secure' => $sessionConfig['cookie_secure'] ?? null,
        'cookie_httponly' => $sessionConfig['cookie_httponly'] ?? true,
        'cookie_samesite' => $sessionConfig['cookie_samesite'] ?? 'Lax',
        'cookie_lifetime' => (int) ($sessionConfig['cookie_lifetime'] ?? 86400 * 30),
    ];
}

function buildBackendSession(string $mode): AuthenticatedSessionInterface
{
    $sessionFactory = SessionFactory::getInstance();
    if ($mode === 'fpm') {
        return $sessionFactory->createBackendSession();
    }

    $sessionConfig = getSessionConfig();
    $ttl = (int) ($sessionConfig['lifetime'] ?? $sessionConfig['session_ttl'] ?? 3600);
    $storage = $sessionFactory->createStorage('wls');
    $strategy = new WlsStrategy($storage, buildStrategyConfig($sessionConfig));
    $rawSession = new Session($storage, $strategy, $ttl);

    return new AuthenticatedSession($rawSession, new AreaConfig('backend'));
}

function assertBackendUserCanLogin(BackendUser $user): void
{
    if (!$user->getId() || $user->getIsDeleted()) {
        fail('Backend E2E session bootstrap failed: admin user does not exist.');
    }

    if (!$user->getIsEnabled()) {
        fail('Backend E2E session bootstrap failed: admin user is disabled.');
    }

    $role = $user->getRole();
    $hasRole = $role && $role->getRoleId();
    $isSuperAdminById = (int) $user->getId() === 1;
    if (!$hasRole && !$isSuperAdminById) {
        fail('Backend E2E session bootstrap failed: admin user has no backend role.');
    }
}

$options = getopt('', ['mode::', 'username::', 'password::']);
$mode = strtolower(trim((string) ($options['mode'] ?? 'fpm')));
if (!in_array($mode, ['fpm', 'wls'], true)) {
    fail('Backend E2E session bootstrap failed: unsupported mode "' . $mode . '".');
}

$username = trim((string) ($options['username'] ?? (getenv('PLAYWRIGHT_ADMIN_USERNAME') ?: 'admin')));
$password = (string) ($options['password'] ?? (getenv('PLAYWRIGHT_ADMIN_PASSWORD') ?: 'admin'));
if ($username === '' || $password === '') {
    fail('Backend E2E session bootstrap failed: username/password are required.');
}

try {
    SessionFactory::getInstance()->resetRequestInstances();
    Session::resetRequestState();

    /** @var BackendUser $user */
    $user = clone ObjectManager::getInstance(BackendUser::class);
    $user->reset()->where('username', $username)->find()->fetch();
    assertBackendUserCanLogin($user);

    if (!password_verify($password, (string) $user->getPassword())) {
        fail('Backend E2E session bootstrap failed: invalid admin password.');
    }

    $session = buildBackendSession($mode);
    $session->start(null);
    $session->login($user);

    $role = $user->getRole();
    $isSuperAdminById = (int) $user->getId() === 1;
    $aclRoleId = $role && $role->getRoleId() ? (int) $role->getRoleId() : ($isSuperAdminById ? 1 : 0);

    $rawSession = $session->getSession();
    $rawSession->set('backend_acl_role_id', $aclRoleId);
    $rawSession->set('backend_acl_is_enabled', $user->getIsEnabled() ? 1 : 0);
    foreach ([
        'need_backend_verification_code',
        'backend_verification_code',
        'backend_disable_login',
        'backend_disable_login_username',
        'backend_login_referer',
        'referer',
    ] as $sessionKey) {
        $rawSession->delete($sessionKey);
    }

    $sessionId = $session->getId();
    if ($sessionId === '') {
        fail('Backend E2E session bootstrap failed: no session id was generated.');
    }

    $user->setSessionId($sessionId)
        ->setLoginIp('127.0.0.1')
        ->setData(BackendUser::schema_fields_attempt_times, 0)
        ->forceCheck()
        ->save();

    $rawSession->save();
    if ($rawSession instanceof Session) {
        $rawSession->getStrategy()->writeClose();
    }
    Session::flushRequestSessions();

    $sessionConfig = getSessionConfig();
    echo json_encode([
        'mode' => $mode,
        'session_name' => $mode === 'wls' ? WlsStrategy::SESSION_NAME : FpmStrategy::SESSION_NAME,
        'session_id' => $sessionId,
        'cookie_path' => $sessionConfig['cookie_path'] ?? '/',
        'cookie_lifetime' => (int) ($sessionConfig['cookie_lifetime'] ?? 86400 * 30),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $throwable) {
    fail('Backend E2E session bootstrap failed: ' . $throwable->getMessage());
}

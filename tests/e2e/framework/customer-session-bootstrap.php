<?php
declare(strict_types=1);

use Weline\Customer\Model\Customer;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AreaConfig;
use Weline\Framework\Session\Auth\AuthenticatedSession;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\Session;
use Weline\Framework\Session\SessionCookieNameResolver;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\Strategy\WlsStrategy;

require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function fail(string $m): never { fwrite(STDERR, $m . PHP_EOL); exit(1); }

$opts = getopt('', ['customer_id::', 'email::', 'mode::']);
$mode = strtolower(trim((string)($opts['mode'] ?? 'wls')));
$customerId = (int)($opts['customer_id'] ?? 0);
$email = trim((string)($opts['email'] ?? ''));

SessionFactory::getInstance()->resetRequestInstances();
Session::resetRequestState();

/** @var Customer $user */
$user = clone ObjectManager::getInstance(Customer::class);
if ($customerId > 0) {
    $user->load($customerId);
} elseif ($email !== '') {
    $user->reset()->where('email', $email)->find()->fetch();
} else {
    fail('need customer_id or email');
}
if (!(int)$user->getId()) {
    fail('customer not found');
}

$sessionConfig = (array)Env::getInstance()->getConfig('session');
$ttl = (int)($sessionConfig['lifetime'] ?? $sessionConfig['session_ttl'] ?? 3600);
$factory = SessionFactory::getInstance();
if ($mode === 'fpm') {
    $session = $factory->createFrontendSession();
} else {
    $storage = $factory->createStorage('wls');
    $strategy = new WlsStrategy($storage, [
        'lifetime' => $ttl,
        'cookie_path' => $sessionConfig['cookie_path'] ?? '/',
        'cookie_domain' => $sessionConfig['cookie_domain'] ?? '',
        'cookie_secure' => $sessionConfig['cookie_secure'] ?? null,
        'cookie_httponly' => $sessionConfig['cookie_httponly'] ?? true,
        'cookie_samesite' => $sessionConfig['cookie_samesite'] ?? 'Lax',
        'cookie_lifetime' => (int)($sessionConfig['cookie_lifetime'] ?? 86400 * 30),
    ]);
    $raw = new Session($storage, $strategy, $ttl);
    $session = new AuthenticatedSession($raw, new AreaConfig('frontend'));
}

$session->start(null);
$session->login($user);
$sessionId = $session->getId();
if ($sessionId === '') {
    fail('empty session id');
}
$user->setSessionId($sessionId)->setLoginIp('127.0.0.1')->save();
$rawSession = $session->getSession();
$rawSession->save();
if ($rawSession instanceof Session) {
    $rawSession->getStrategy()->writeClose();
}
Session::flushRequestSessions();

$origin = getenv('PLAYWRIGHT_TARGET_ORIGIN') ?: 'https://p05113ef3.test.weline.com';
$host = (string)(parse_url($origin, PHP_URL_HOST) ?: '');
$port = parse_url($origin, PHP_URL_PORT);
$authority = $host . (is_int($port) ? ':' . $port : '');
$name = SessionCookieNameResolver::resolve($authority, 'frontend');

echo json_encode([
    'customer_id' => (int)$user->getId(),
    'email' => (string)$user->getEmail(),
    'session_name' => $name,
    'session_id' => $sessionId,
    'cookie_path' => $sessionConfig['cookie_path'] ?? '/',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;

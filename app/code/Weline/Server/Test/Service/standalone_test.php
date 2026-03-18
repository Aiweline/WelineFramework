<?php
/**
 * 独立测试脚本 - 不依赖 PHPUnit，直接验证核心逻辑
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../../../autoload.php';

use Weline\Server\Service\Contract\HealthCheckResult;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\ServiceRegistry;
use Weline\Server\Service\Provider\WorkerProvider;
use Weline\Server\Service\Provider\DispatcherProvider;
use Weline\Server\Service\Provider\SessionServerProvider;
use Weline\Server\Service\Provider\HttpRedirectProvider;

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "✓ {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "✗ {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

function assertEqual(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \Exception("Expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ($msg ? " - {$msg}" : ''));
    }
}

function assertTrue(bool $value, string $msg = ''): void
{
    if (!$value) {
        throw new \Exception("Expected true, got false" . ($msg ? " - {$msg}" : ''));
    }
}

function assertFalse(bool $value, string $msg = ''): void
{
    if ($value) {
        throw new \Exception("Expected false, got true" . ($msg ? " - {$msg}" : ''));
    }
}

echo "=== HealthCheckResult Tests ===\n";

test('HealthCheckResult::healthy()', function () {
    $result = HealthCheckResult::healthy('All good');
    assertEqual(HealthCheckResult::STATUS_HEALTHY, $result->status);
    assertEqual('All good', $result->message);
    assertTrue($result->isHealthy());
});

test('HealthCheckResult::unhealthy()', function () {
    $result = HealthCheckResult::unhealthy('Connection refused');
    assertEqual(HealthCheckResult::STATUS_UNHEALTHY, $result->status);
    assertFalse($result->isHealthy());
});

test('HealthCheckResult::degraded()', function () {
    $result = HealthCheckResult::degraded('High latency');
    assertEqual(HealthCheckResult::STATUS_DEGRADED, $result->status);
    assertFalse($result->isHealthy());
});

echo "\n=== ServiceInstance Tests ===\n";

test('ServiceInstance::getKey()', function () {
    $instance = new ServiceInstance(role: 'worker', instanceId: 1);
    assertEqual('worker:1', $instance->getKey());
});

test('ServiceInstance::isHealthy()', function () {
    $instance = new ServiceInstance(role: 'worker', instanceId: 1);
    $instance->state = ServiceInstance::STATE_READY;
    assertTrue($instance->isHealthy());
    $instance->state = ServiceInstance::STATE_FAILED;
    assertFalse($instance->isHealthy());
});

test('ServiceInstance::isRunning()', function () {
    $instance = new ServiceInstance(role: 'worker', instanceId: 1);
    $instance->state = ServiceInstance::STATE_READY;
    assertTrue($instance->isRunning());
    $instance->state = ServiceInstance::STATE_STOPPED;
    assertFalse($instance->isRunning());
});

test('ServiceInstance::metadata', function () {
    $instance = new ServiceInstance(role: 'worker', instanceId: 1);
    assertEqual('default', $instance->getMeta('key1', 'default'));
    $instance->setMeta('key1', 'value1');
    assertEqual('value1', $instance->getMeta('key1'));
});

echo "\n=== ServiceCommand Tests ===\n";

test('ServiceCommand::build()', function () {
    $command = new ServiceCommand(
        script: 'bin/worker.php',
        arguments: ['--port=10443', '--instance=default'],
    );
    $built = $command->build();
    assertTrue(str_contains($built, 'worker.php'), 'Contains worker.php');
    assertTrue(str_contains($built, '--port=10443'), 'Contains port arg');
});

test('ServiceCommand::getWorkingDir()', function () {
    $command = new ServiceCommand(script: 'bin/worker.php');
    assertEqual(BP, $command->getWorkingDir());
});

echo "\n=== ServiceContext Tests ===\n";

test('ServiceContext::getConfig()', function () {
    $context = new ServiceContext(
        instanceName: 'test',
        epoch: 1,
        controlPort: 19000,
        masterPid: 12345,
        host: '0.0.0.0',
        mainPort: 443,
        sslEnabled: true,
        sslCert: '',
        sslKey: '',
        mode: 'multi',
        daemon: false,
        debug: true,
        frontend: false,
        envConfig: ['wls' => ['worker_count' => 4]],
    );
    assertEqual(4, $context->getConfig('wls.worker_count'));
    assertEqual('default', $context->getConfig('nonexistent', 'default'));
});

echo "\n=== ServiceRegistry Tests ===\n";

test('ServiceRegistry::addInstance and getters', function () {
    $registry = new ServiceRegistry();
    $instance = new ServiceInstance(
        role: 'worker',
        instanceId: 1,
        pid: 12345,
        port: 10443,
        state: ServiceInstance::STATE_READY,
    );
    $registry->addInstance($instance);

    assertEqual($instance, $registry->getInstance('worker', 1));
    assertEqual($instance, $registry->getInstanceByPid(12345));
    assertEqual($instance, $registry->getInstanceByPort(10443));
    assertEqual(1, $registry->getInstanceCount());
});

test('ServiceRegistry::removeInstance', function () {
    $registry = new ServiceRegistry();
    $instance = new ServiceInstance(role: 'worker', instanceId: 1, pid: 12345);
    $registry->addInstance($instance);
    $registry->removeInstance('worker', 1);
    assertEqual(null, $registry->getInstance('worker', 1));
    assertEqual(null, $registry->getInstanceByPid(12345));
});

test('ServiceRegistry::getInstancesByRole', function () {
    $registry = new ServiceRegistry();
    $registry->addInstance(new ServiceInstance(role: 'worker', instanceId: 1));
    $registry->addInstance(new ServiceInstance(role: 'worker', instanceId: 2));
    $registry->addInstance(new ServiceInstance(role: 'dispatcher', instanceId: 1));
    $workers = $registry->getInstancesByRole('worker');
    assertEqual(2, count($workers));
});

echo "\n=== Provider Tests ===\n";

$context = new ServiceContext(
    instanceName: 'test-instance',
    epoch: 1,
    controlPort: 19000,
    masterPid: 12345,
    host: '0.0.0.0',
    mainPort: 443,
    sslEnabled: true,
    sslCert: '/path/to/cert.pem',
    sslKey: '/path/to/key.pem',
    mode: 'multi',
    daemon: false,
    debug: true,
    frontend: false,
    envConfig: [
        'wls' => [
            'worker_count' => 4,
            'worker_base_port' => 10443,
            'dispatcher_port' => 18080,
            'session_server_port' => 18888,
        ],
    ],
);

test('WorkerProvider basic', function () use ($context) {
    $provider = new WorkerProvider();
    assertEqual('worker', $provider->getRole());
    assertEqual('HTTP Worker', $provider->getDisplayName());
    assertTrue($provider->isEnabled($context));
    assertEqual(4, $provider->getInstanceCount($context));
    assertEqual(20, $provider->getPriority());
});

test('WorkerProvider::getPort', function () use ($context) {
    $provider = new WorkerProvider();
    assertEqual(10443, $provider->getPort(0, $context));
    assertEqual(10444, $provider->getPort(1, $context));
});

test('DispatcherProvider basic', function () use ($context) {
    $provider = new DispatcherProvider();
    assertEqual('dispatcher', $provider->getRole());
    assertTrue($provider->isEnabled($context));
    assertEqual(1, $provider->getInstanceCount($context));
    assertEqual(443, $provider->getPort(0, $context));
});

test('SessionServerProvider basic', function () use ($context) {
    $provider = new SessionServerProvider();
    assertEqual('session_server', $provider->getRole());
    assertEqual(1, $provider->getInstanceCount($context));
    assertEqual(10, $provider->getPriority());
});

test('HttpRedirectProvider basic', function () use ($context) {
    $provider = new HttpRedirectProvider();
    assertEqual('redirect', $provider->getRole());
    assertTrue($provider->isEnabled($context));
    assertEqual(80, $provider->getPort(0, $context));
});

test('HttpRedirectProvider disabled without SSL', function () {
    $provider = new HttpRedirectProvider();
    $nonSslContext = new ServiceContext(
        instanceName: 'test',
        epoch: 1,
        controlPort: 19000,
        masterPid: 12345,
        host: '0.0.0.0',
        mainPort: 8080,
        sslEnabled: false,
        sslCert: '',
        sslKey: '',
        mode: 'multi',
        daemon: false,
        debug: false,
        frontend: false,
        envConfig: [],
    );
    assertFalse($provider->isEnabled($nonSslContext));
});

echo "\n=== Summary ===\n";
echo "Passed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);

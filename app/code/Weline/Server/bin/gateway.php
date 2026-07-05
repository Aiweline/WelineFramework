<?php
/**
 * WLS Gateway 启动脚本
 *
 * 用法：
 *   php gateway.php <listen_host> <listen_port> <control_port> <master_pid> <instance_name>
 *
 * 示例：
 *   php gateway.php 0.0.0.0 443 20443 12345 default
 */

// 解析命令行参数
$listenHost = $argv[1] ?? '0.0.0.0';
$listenPort = (int) ($argv[2] ?? 443);
$controlPort = (int) ($argv[3] ?? 0);
$masterPid = (int) ($argv[4] ?? 0);
$instanceName = $argv[5] ?? 'default';
$runtimeArgs = [];
foreach (\array_slice($argv, 6) as $arg) {
    if (!\is_string($arg) || !\str_starts_with($arg, '--')) {
        continue;
    }
    [$key, $value] = \array_pad(\explode('=', \substr($arg, 2), 2), 2, '');
    $runtimeArgs[$key] = \trim($value, "\"'");
}

// 设置进程标题
if (function_exists('cli_set_process_title')) {
    cli_set_process_title("weline-wls-gateway-{$instanceName}");
}

// 初始化框架
if (!defined('BP')) {
    define('BP', dirname(__DIR__, 5) . DIRECTORY_SEPARATOR);
}
require BP . 'app/bootstrap.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\WlsGateway;
use Weline\Server\Model\ReverseProxy;

// 创建 Gateway 实例
$gateway = new WlsGateway();
$gateway->setListenAddress($listenHost, $listenPort);
$gateway->setInstanceName((string)$instanceName);
$childMasterGuard = new \Weline\Server\IPC\ChildControl\ChildMasterGuard(
    $masterPid,
    (string)($runtimeArgs['master-lease-file'] ?? ''),
    (string)($runtimeArgs['master-token'] ?? ''),
    'Gateway:' . $listenPort,
    (string)$instanceName,
    (int)($runtimeArgs['epoch'] ?? 0)
);
$gateway->setMasterGuard($childMasterGuard);
$childMasterGuard->assertAliveOrExit('Gateway bootstrap 后 Master 自治检查');

// 如果提供了 IPC 参数，启用动态路由
if ($controlPort > 0 && $masterPid > 0) {
    try {
        $gateway->setIpcConfig($controlPort, $masterPid);
        $gateway->setIpcIdentity(
            (int)($runtimeArgs['epoch'] ?? 0),
            (string)($runtimeArgs['launch-id'] ?? ''),
            (string)($runtimeArgs['slot-id'] ?? ''),
            (string)($runtimeArgs['lease-id'] ?? ''),
            (int)($runtimeArgs['slot-generation'] ?? 0)
        );
        $gateway->enableDynamicRouting();
    } catch (\Throwable $e) {
        echo "警告: 无法启用动态路由: {$e->getMessage()}\n";
        echo "将以静态配置模式运行\n";
    }
}

// 从数据库加载初始路由
try {
    $proxyModel = ObjectManager::getInstance(ReverseProxy::class);
    $routes = $proxyModel->getActiveRules();

    if (!empty($routes)) {
        echo "从数据库加载 " . count($routes) . " 条路由规则\n";
        foreach ($routes as $route) {
            $gateway->addRoute(
                $route[ReverseProxy::schema_fields_DOMAIN],
                $route[ReverseProxy::schema_fields_BACKEND_HOST],
                (int) $route[ReverseProxy::schema_fields_BACKEND_PORT],
                (bool) $route[ReverseProxy::schema_fields_BACKEND_SSL],
                (int) $route[ReverseProxy::schema_fields_PRIORITY]
            );
        }
    } else {
        echo "数据库中没有路由规则\n";
    }
} catch (\Throwable $e) {
    echo "警告: 无法从数据库加载路由: {$e->getMessage()}\n";
    echo "将以空路由表启动\n";
}

// 启动 Gateway
try {
    $gateway->start();
} catch (\Throwable $e) {
    echo "Gateway 启动失败: {$e->getMessage()}\n";
    throw $e;
}

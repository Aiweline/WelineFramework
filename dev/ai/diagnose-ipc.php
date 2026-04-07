<?php
/**
 * IPC 连接诊断工具 - 2026-04-07
 * 
 * 用于诊断 MaintenanceSSL Worker 无法连接到 Master IPC 的问题
 * 
 * 用法: php dev/ai/diagnose-ipc.php
 */

$bp = __DIR__ . '/../../';
if (!defined('BP')) {
    define('BP', $bp);
}

require_once BP . 'app/autoload.php';

use Weline\Server\Log\WlsLogger;
use Weline\Server\IPC\ChildControl\InstanceInfoGateway;

echo "\n========== IPC 连接诊断 (2026-04-07) ==========\n";

$instanceName = $argv[1] ?? 'default';
echo "实例名: {$instanceName}\n";

// 1. 读取 instance JSON
$instanceFile = BP . 'var/server/instances/' . $instanceName . '.json';
echo "\n[1] 检查 instance JSON 文件\n";
echo "路径: {$instanceFile}\n";

if (!is_file($instanceFile)) {
    echo "❌ 文件不存在\n";
    exit(1);
}

echo "✓ 文件存在\n";
$content = file_get_contents($instanceFile);
if (!$content) {
    echo "❌ 无法读取文件\n";
    exit(1);
}

$data = json_decode($content, true);
if (!is_array($data)) {
    echo "❌ JSON 解析失败\n";
    exit(1);
}

echo "✓ JSON 解析成功\n";

// 2. 检查 control_port 字段
echo "\n[2] 检查 control_port 字段\n";
$controlPort = (int)($data['control_port'] ?? 0);
echo "control_port 值: {$controlPort}\n";

if ($controlPort <= 0) {
    echo "❌ control_port 无效（<=0）\n";
    exit(1);
}

echo "✓ control_port 有效\n";

// 3. 检查 Master PID
echo "\n[3] 检查 Master PID\n";
$masterPid = (int)($data['master_pid'] ?? 0);
echo "master_pid 值: {$masterPid}\n";

if ($masterPid <= 0) {
    echo "❌ master_pid 无效\n";
    exit(1);
}

$masterRunning = false;
if (function_exists('posix_kill')) {
    // Linux/macOS
    $masterRunning = (posix_kill($masterPid, 0) !== false);
} else {
    // Windows - 检查进程名
    $massiveOutput = shell_exec("tasklist /FI \"PID eq {$masterPid}\"");
    $masterRunning = (strpos($massiveOutput !== null ? $massiveOutput : '', (string)$masterPid) !== false);
}

if (!$masterRunning) {
    echo "⚠️  Master PID {$masterPid} 未运行\n";
} else {
    echo "✓ Master PID {$masterPid} 正在运行\n";
}

// 4. 检查 TCP 连接
echo "\n[4] 尝试 TCP 连接到 127.0.0.1:{$controlPort}\n";

$sock = @fsockopen('127.0.0.1', $controlPort, $errno, $errstr, 2);
if ($sock) {
    echo "✓ TCP 连接成功\n";
    fclose($sock);
} else {
    echo "❌ TCP 连接失败: {$errstr} (errno: {$errno})\n";
}

// 5. 使用 InstanceInfoGateway 动态读取
echo "\n[5] 使用 InstanceInfoGateway 读取最新信息\n";
$gateway = new InstanceInfoGateway($instanceName);
$latestPort = $gateway->getLatestControlPort(0);
echo "InstanceInfoGateway::getLatestControlPort() = {$latestPort}\n";

if ($latestPort !== $controlPort) {
    echo "⚠️  端口已变化: {$controlPort} → {$latestPort}\n";
    
    // 尝试连接新端口
    echo "\n[6] 尝试连接更新的端口 {$latestPort}\n";
    $sock2 = @fsockopen('127.0.0.1', $latestPort, $errno2, $errstr2, 2);
    if ($sock2) {
        echo "✓ 新端口 TCP 连接成功\n";
        fclose($sock2);
    } else {
        echo "❌ 新端口 TCP 连接失败: {$errstr2} (errno: {$errno2})\n";
    }
} else {
    echo "✓ 端口未变化\n";
}

// 7. 显示 updated_at 时间戳
echo "\n[7] 时间戳检查\n";
$updatedAt = (int)($data['updated_at'] ?? 0);
$now = time();
$delta = $now - $updatedAt;
echo "JSON 最后更新时间: " . date('Y-m-d H:i:s', $updatedAt) . " (距现在 {$delta} 秒)\n";

if ($delta > 60) {
    echo "⚠️  JSON 长时间未更新（超过 60 秒），可能 Master 已崩溃\n";
} else {
    echo "✓ JSON 更新及时\n";
}

echo "\n========== 诊断完成 ==========\n\n";

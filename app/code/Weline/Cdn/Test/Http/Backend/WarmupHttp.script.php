<?php
/**
 * HTTP Integration Test: Warmup Controller
 * 
 * Tests for:
 * - GET /cdn/backend/warmup/index (预热URL列表)
 * - GET /cdn/backend/warmup/statistics (统计信息)
 * - POST /cdn/backend/warmup/execute (执行预热)
 * - POST /cdn/backend/warmup/toggleEnable (启用/禁用)
 * - POST /cdn/backend/warmup/delete (删除URL)
 * 
 * Usage: php app/code/Weline/Cdn/Test/Http/Backend/WarmupHttp.script.php
 */

echo "\n=== Testing CDN Warmup Endpoints ===\n\n";
$output = [];

// Test 1: Get warmup URL list
echo "Test 1: Get warmup URL list...\n";
$command = 'php bin/w http:request cdn/backend/warmup/index -b';
echo "Command: $command\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
$output = [];
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";

// Test 2: Get statistics
echo "Test 2: Get statistics...\n";
$command = 'php bin/w http:request cdn/backend/warmup/statistics -b';
echo "Command: $command\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
$output = [];
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";

// Test 3: Execute warmup (POST)
echo "Test 3: Execute warmup...\n";
$command = 'php bin/w http:request cdn/backend/warmup/execute -b -m=POST';
echo "Command: $command\n";
echo "Note: This will process pending URLs\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
$output = [];
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";

// Test 4: Toggle enable/disable
echo "Test 4: Toggle enable/disable...\n";
$command = 'php bin/w http:request cdn/backend/warmup/toggleEnable -b -m=POST -d="id=1&enabled=1"';
echo "Command: $command\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
$output = [];
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";

// Test 5: Delete warmup URL (POST)
echo "Test 5: Delete warmup URL...\n";
$command = 'php bin/w http:request cdn/backend/warmup/delete -b -m=POST -d="id=999"';
echo "Command: $command\n";
echo "Note: This will fail if URL doesn't exist, which is expected\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
$output = [];
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED (expected if URL doesn't exist)") . "\n\n";

echo "\n=== All Warmup tests completed ===\n";


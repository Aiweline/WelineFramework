<?php
/**
 * HTTP Integration Test: Account Controller
 * 
 * Tests for: 
 * - GET /cdn/backend/account/index (账户列表)
 * - GET /cdn/backend/account/form (账户表单)
 * - POST /cdn/backend/account/save (保存账户)
 * - POST /cdn/backend/account/setDefault (设置默认账户)
 * - POST /cdn/backend/account/delete (删除账户)
 * - GET /cdn/backend/account/domains (关联域名)
 * 
 * Usage: php app/code/Weline/Cdn/Test/Http/Backend/AccountHttp.script.php
 */

echo "\n=== Testing CDN Account Endpoints ===\n\n";
$output = [];

// Test 1: Get account list
echo "Test 1: Get account list...\n";
$command = 'php bin/w http:request cdn/backend/account/index -b';
echo "Command: $command\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";
$output = [];

// Test 2: Get account form (create new)
echo "Test 2: Get account form (create new)...\n";
$command = 'php bin/w http:request cdn/backend/account/form -b';
echo "Command: $command\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";
$output = [];

// Test 3: Save account (POST)
echo "Test 3: Save account...\n";
$command = 'php bin/w http:request cdn/backend/account/save -b -m=POST -d="adapter=cloudflare&name=Test Account HTTP&credentials[api_token]=test-token-123&status=active"';
echo "Command: $command\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";
$output = [];

// Test 4: Set default account (POST)
echo "Test 4: Set default account...\n";
$command = 'php bin/w http:request cdn/backend/account/setDefault -b -m=POST -d="id=1"';
echo "Command: $command\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";
$output = [];

// Test 5: Get account domains
echo "Test 5: Get account domains...\n";
$command = 'php bin/w http:request "cdn/backend/account/domains?id=1" -b';
echo "Command: $command\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";
$output = [];

// Test 6: Delete account (POST)
echo "Test 6: Delete account...\n";
$command = 'php bin/w http:request cdn/backend/account/delete -b -m=POST -d="id=999"';
echo "Command: $command\n";
echo "Note: This will fail if account doesn't exist, which is expected\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED (expected if account doesn't exist)") . "\n\n";

echo "\n=== All Account tests completed ===\n";


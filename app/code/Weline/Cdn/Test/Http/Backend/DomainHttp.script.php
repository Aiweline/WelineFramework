<?php
/**
 * HTTP Integration Test: Domain Controller
 * 
 * Tests for:
 * - GET /cdn/backend/domain/index (域名列表)
 * - GET /cdn/backend/domain/form (域名表单)
 * - POST /cdn/backend/domain/save (保存域名)
 * - POST /cdn/backend/domain/toggleEnable (启用/禁用)
 * - POST /cdn/backend/domain/clearCache (清理缓存)
 * - POST /cdn/backend/domain/delete (删除域名)
 * 
 * Usage: php app/code/Weline/Cdn/Test/Http/Backend/DomainHttp.script.php
 */

echo "\n=== Testing CDN Domain Endpoints ===\n\n";
$output = [];

// Test 1: Get domain list
echo "Test 1: Get domain list...\n";
$command = 'php bin/w http:request cdn/backend/domain/index -b';
echo "Command: $command\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
$output = [];
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";

// Test 2: Get domain form (create new)
echo "Test 2: Get domain form (create new)...\n";
$command = 'php bin/w http:request cdn/backend/domain/form -b';
echo "Command: $command\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
$output = [];
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";

// Test 3: Save domain (POST)
echo "Test 3: Save domain...\n";
$command = 'php bin/w http:request cdn/backend/domain/save -b -m=POST -d="site_id=1&adapter=cloudflare&domain_name=test.example.com&zone_id=test-zone-123&enabled=1"';
echo "Command: $command\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
$output = [];
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";

// Test 4: Toggle enable/disable
echo "Test 4: Toggle enable/disable...\n";
$command = 'php bin/w http:request cdn/backend/domain/toggleEnable -b -m=POST -d="id=1&enabled=1"';
echo "Command: $command\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
$output = [];
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";

// Test 5: Clear cache
echo "Test 5: Clear cache...\n";
$command = 'php bin/w http:request cdn/backend/domain/clearCache -b -m=POST -d="id=1&mode=everything"';
echo "Command: $command\n";
echo "Note: This may fail if domain doesn't exist or CDN credentials are invalid\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
$output = [];
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED (may be expected)") . "\n\n";

// Test 6: Delete domain (POST)
echo "Test 6: Delete domain...\n";
$command = 'php bin/w http:request cdn/backend/domain/delete -b -m=POST -d="id=999"';
echo "Command: $command\n";
echo "Note: This will fail if domain doesn't exist, which is expected\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
$output = [];
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED (expected if domain doesn't exist)") . "\n\n";

echo "\n=== All Domain tests completed ===\n";


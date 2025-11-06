<?php
/**
 * HTTP Integration Test: Rules Controller
 * 
 * Tests for:
 * - GET /cdn/backend/rules/index (规则列表)
 * - GET /cdn/backend/rules/getGlobalRules (获取全局规则)
 * - POST /cdn/backend/rules/saveGlobalRules (保存全局规则)
 * - GET /cdn/backend/rules/getDomainRules (获取域名规则)
 * - POST /cdn/backend/rules/saveDomainRules (保存域名规则)
 * - POST /cdn/backend/rules/import (导入规则)
 * - POST /cdn/backend/rules/push (推送规则)
 * 
 * Usage: php app/code/Weline/Cdn/Test/Http/Backend/RulesHttp.script.php
 */

echo "\n=== Testing CDN Rules Endpoints ===\n\n";
$output = [];

// Test 1: Get rules index
echo "Test 1: Get rules index...\n";
$command = 'php bin/w http:request cdn/backend/rules/index -b';
echo "Command: $command\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
$output = [];
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";

// Test 2: Get global rules
echo "Test 2: Get global rules...\n";
$command = 'php bin/w http:request cdn/backend/rules/getGlobalRules -b';
echo "Command: $command\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
$output = [];
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";

// Test 3: Save global rules (POST)
echo "Test 3: Save global rules...\n";
$rulesJson = json_encode(['cache_level' => 'aggressive', 'browser_cache_ttl' => 3600]);
$command = 'php bin/w http:request cdn/backend/rules/saveGlobalRules -b -m=POST -d="rules=' . urlencode($rulesJson) . '"';
echo "Command: $command\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
$output = [];
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";

// Test 4: Get domain rules
echo "Test 4: Get domain rules...\n";
$command = 'php bin/w http:request "cdn/backend/rules/getDomainRules?domain_id=1" -b';
echo "Command: $command\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
$output = [];
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";

// Test 5: Save domain rules (POST)
echo "Test 5: Save domain rules...\n";
$rulesJson = json_encode(['cache_level' => 'standard']);
$command = 'php bin/w http:request cdn/backend/rules/saveDomainRules -b -m=POST -d="domain_id=1&rules=' . urlencode($rulesJson) . '"';
echo "Command: $command\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
$output = [];
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";

// Test 6: Import rules (POST)
echo "Test 6: Import rules from CDN...\n";
$command = 'php bin/w http:request "cdn/backend/rules/import?domain_id=1" -b -m=POST';
echo "Command: $command\n";
echo "Note: This may fail if domain doesn't exist or CDN credentials are invalid\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
$output = [];
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED (may be expected)") . "\n\n";

// Test 7: Push rules (POST)
echo "Test 7: Push rules to CDN...\n";
$command = 'php bin/w http:request "cdn/backend/rules/push?domain_id=1" -b -m=POST';
echo "Command: $command\n";
echo "Note: This may fail if domain doesn't exist or CDN credentials are invalid\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
$output = [];
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED (may be expected)") . "\n\n";

echo "\n=== All Rules tests completed ===\n";


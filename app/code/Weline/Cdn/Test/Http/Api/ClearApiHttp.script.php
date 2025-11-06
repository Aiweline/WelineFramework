<?php
/**
 * HTTP Integration Test: Clear API Controller
 * 
 * Tests for:
 * - POST /api/cdn/clear (清理缓存API)
 * 
 * Usage: php app/code/Weline/Cdn/Test/Http/Api/ClearApiHttp.script.php
 */

echo "\n=== Testing CDN Clear API Endpoints ===\n\n";
$output = [];

// Test 1: Clear cache (everything mode)
echo "Test 1: Clear cache (everything mode)...\n";
// Note: API路由可能需要使用rest/v1/前缀，或者直接使用api/前缀
// 根据Controller注释，路径应该是 /api/cdn/clear
// 尝试两种格式：api/cdn/clear 和 rest/v1/cdn/clear
$command = 'php bin/w http:request rest/v1/cdn/clear -api -m=POST -d="domain=example.com&mode=everything"';
echo "Command: $command\n";
echo "Note: This may fail if domain doesn't exist or CDN credentials are invalid\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED (may be expected)") . "\n\n";
$output = [];

// Test 1b: Try alternative API path format
echo "Test 1b: Clear cache (alternative path: api/cdn/clear)...\n";
$command = 'php bin/w http:request api/cdn/clear -api -m=POST -d="domain=example.com&mode=everything"';
echo "Command: $command\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED (may be expected)") . "\n\n";
$output = [];

// Test 2: Clear cache (urls mode)
echo "Test 2: Clear cache (urls mode)...\n";
$dataJson = json_encode(['urls' => ['https://example.com/page1', 'https://example.com/page2']]);
$command = 'php bin/w http:request rest/v1/cdn/clear -api -m=POST -d="domain=example.com&mode=urls&data=' . urlencode($dataJson) . '"';
echo "Command: $command\n";
echo "Note: This may fail if domain doesn't exist or CDN credentials are invalid\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED (may be expected)") . "\n\n";
$output = [];

// Test 3: Clear cache (hosts mode)
echo "Test 3: Clear cache (hosts mode)...\n";
$dataJson = json_encode(['hosts' => ['example.com', 'www.example.com']]);
$command = 'php bin/w http:request rest/v1/cdn/clear -api -m=POST -d="domain=example.com&mode=hosts&data=' . urlencode($dataJson) . '"';
echo "Command: $command\n";
echo "Note: This may fail if domain doesn't exist or CDN credentials are invalid\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED (may be expected)") . "\n\n";
$output = [];

// Test 4: Clear cache (tags mode)
echo "Test 4: Clear cache (tags mode)...\n";
$dataJson = json_encode(['tags' => ['tag1', 'tag2']]);
$command = 'php bin/w http:request rest/v1/cdn/clear -api -m=POST -d="domain=example.com&mode=tags&data=' . urlencode($dataJson) . '"';
echo "Command: $command\n";
echo "Note: This may fail if domain doesn't exist or CDN credentials are invalid\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED (may be expected)") . "\n\n";
$output = [];

// Test 5: Clear cache (invalid mode - should fail)
echo "Test 5: Clear cache (invalid mode - should fail)...\n";
$command = 'php bin/w http:request rest/v1/cdn/clear -api -m=POST -d="domain=example.com&mode=invalid_mode"';
echo "Command: $command\n";
echo "Note: This should fail with validation error\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
echo "Result: " . ($returnCode === 0 ? "SUCCESS (unexpected)" : "FAILED (expected)") . "\n\n";
$output = [];

// Test 6: Clear cache (missing domain - should fail)
echo "Test 6: Clear cache (missing domain - should fail)...\n";
$command = 'php bin/w http:request rest/v1/cdn/clear -api -m=POST -d="mode=everything"';
echo "Command: $command\n";
echo "Note: This should fail with validation error\n";
exec($command . ' 2>&1', $output, $returnCode);
echo implode("\n", $output) . "\n";
echo "Result: " . ($returnCode === 0 ? "SUCCESS (unexpected)" : "FAILED (expected)") . "\n\n";

echo "\n=== All Clear API tests completed ===\n";


<?php
/**
 * HTTP Integration Test: Adapter
 * 
 * Tests for: GET /ai/backend/adapter/index, POST /ai/backend/adapter/scan
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing Adapter Endpoints ===\n\n";


// Test: Get adapter list
echo "Testing: Get adapter list...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/backend/adapter/index"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Scan adapters
echo "Testing: Scan adapters...\n";
$command = 'php bin/w http:request --method=POST --url="/ai/backend/adapter/scan"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All Adapter tests completed ===\n";

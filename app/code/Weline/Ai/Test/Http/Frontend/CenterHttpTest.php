<?php
/**
 * HTTP Integration Test: Center
 * 
 * Tests for: GET /ai/frontend/center/index
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing Center Endpoints ===\n\n";


// Test: Get user center page
echo "Testing: Get user center page...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/frontend/center/index"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All Center tests completed ===\n";

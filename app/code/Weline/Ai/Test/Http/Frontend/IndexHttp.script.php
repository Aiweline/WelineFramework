<?php
/**
 * HTTP Integration Test: Index
 * 
 * Tests for: GET /ai/frontend/index/index
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing Index Endpoints ===\n\n";


// Test: Get frontend index page
echo "Testing: Get frontend index page...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/frontend/index/index"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All Index tests completed ===\n";

<?php
/**
 * HTTP Integration Test: AbTesting
 * 
 * Tests for: GET /ai/backend/abtesting/index, POST /ai/backend/abtesting/start
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing AbTesting Endpoints ===\n\n";


// Test: Get AB test list
echo "Testing: Get AB test list...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/backend/abtesting/index"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Start AB test
echo "Testing: Start AB test...\n";
$command = 'php bin/w http:request --method=POST --url="/ai/backend/abtesting/start" --data='{"test_name":"Test A/B"}'';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All AbTesting tests completed ===\n";

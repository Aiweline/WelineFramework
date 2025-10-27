<?php
/**
 * HTTP Integration Test: Test
 * 
 * Tests for: GET /ai/backend/test/index, POST /ai/backend/test/run
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing Test Endpoints ===\n\n";


// Test: Get test list
echo "Testing: Get test list...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/backend/test/index"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Run test
echo "Testing: Run test...\n";
$command = 'php bin/w http:request --method=POST --url="/ai/backend/test/run" --data='{"test_type":"unit"}'';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All Test tests completed ===\n";

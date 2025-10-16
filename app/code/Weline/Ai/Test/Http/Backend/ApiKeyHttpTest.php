<?php
/**
 * HTTP Integration Test: ApiKey
 * 
 * Tests for: GET /ai/backend/apikey/index, POST /ai/backend/apikey/save, DELETE /ai/backend/apikey/delete
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing ApiKey Endpoints ===\n\n";


// Test: Get API key list
echo "Testing: Get API key list...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/backend/apikey/index"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Create API key
echo "Testing: Create API key...\n";
$command = 'php bin/w http:request --method=POST --url="/ai/backend/apikey/save" --data='{"name":"Test Key"}'';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Delete API key
echo "Testing: Delete API key...\n";
$command = 'php bin/w http:request --method=DELETE --url="/ai/backend/apikey/delete" --data='{"id":"1"}'';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All ApiKey tests completed ===\n";

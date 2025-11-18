<?php
/**
 * HTTP Integration Test: Assistant
 * 
 * Tests for: GET /ai/backend/assistant/index, POST /ai/backend/assistant/save
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing Assistant Endpoints ===\n\n";


// Test: Get assistant list
echo "Testing: Get assistant list...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/backend/assistant/index"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Save assistant
echo "Testing: Save assistant...\n";
$command = 'php bin/w http:request --method=POST --url="/ai/backend/assistant/save" --data='{"name":"Test Assistant"}'';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All Assistant tests completed ===\n";

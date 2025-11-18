<?php
/**
 * HTTP Integration Test: Assistant
 * 
 * Tests for: GET /ai/frontend/assistant/index, POST /ai/frontend/assistant/chat
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing Assistant Endpoints ===\n\n";


// Test: Get assistant page
echo "Testing: Get assistant page...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/frontend/assistant/index"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Chat with assistant
echo "Testing: Chat with assistant...\n";
$command = 'php bin/w http:request --method=POST --url="/ai/frontend/assistant/chat" --data='{"message":"Hello"}'';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All Assistant tests completed ===\n";

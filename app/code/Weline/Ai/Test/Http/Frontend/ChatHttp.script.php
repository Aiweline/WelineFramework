<?php
/**
 * HTTP Integration Test: Chat
 * 
 * Tests for: GET /ai/frontend/chat/index, POST /ai/frontend/chat/send
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing Chat Endpoints ===\n\n";


// Test: Get chat page
echo "Testing: Get chat page...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/frontend/chat/index"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Send chat message
echo "Testing: Send chat message...\n";
$command = 'php bin/w http:request --method=POST --url="/ai/frontend/chat/send" --data=\'{"message":"Test message"}\'';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All Chat tests completed ===\n";

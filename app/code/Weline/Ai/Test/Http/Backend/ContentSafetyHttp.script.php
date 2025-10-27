<?php
/**
 * HTTP Integration Test: ContentSafety
 * 
 * Tests for: POST /ai/backend/contentsafety/check
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing ContentSafety Endpoints ===\n\n";


// Test: Check content safety
echo "Testing: Check content safety...\n";
$command = 'php bin/w http:request --method=POST --url="/ai/backend/contentsafety/check" --data='{"content":"test content"}'';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All ContentSafety tests completed ===\n";

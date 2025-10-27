<?php
/**
 * HTTP Integration Test: DeveloperTools
 * 
 * Tests for: GET /ai/backend/developertools/sdk, GET /ai/backend/developertools/docs
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing DeveloperTools Endpoints ===\n\n";


// Test: Get SDK info
echo "Testing: Get SDK info...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/backend/developertools/sdk"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Get documentation
echo "Testing: Get documentation...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/backend/developertools/docs"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All DeveloperTools tests completed ===\n";

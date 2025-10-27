<?php
/**
 * HTTP Integration Test: ThirdPartyIntegration
 * 
 * Tests for: GET /ai/backend/thirdpartyintegration/index, POST /ai/backend/thirdpartyintegration/connect
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing ThirdPartyIntegration Endpoints ===\n\n";


// Test: Get integration list
echo "Testing: Get integration list...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/backend/thirdpartyintegration/index"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Connect integration
echo "Testing: Connect integration...\n";
$command = 'php bin/w http:request --method=POST --url="/ai/backend/thirdpartyintegration/connect" --data='{"integration_type":"openai"}'';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All ThirdPartyIntegration tests completed ===\n";

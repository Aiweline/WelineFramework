<?php
/**
 * HTTP Integration Test: ModelDeployment
 * 
 * Tests for: GET /ai/backend/modeldeployment/index, POST /ai/backend/modeldeployment/deploy
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing ModelDeployment Endpoints ===\n\n";


// Test: Get deployment list
echo "Testing: Get deployment list...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/backend/modeldeployment/index"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Deploy model
echo "Testing: Deploy model...\n";
$command = 'php bin/w http:request --method=POST --url="/ai/backend/modeldeployment/deploy" --data='{"model_id":"1"}'';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All ModelDeployment tests completed ===\n";

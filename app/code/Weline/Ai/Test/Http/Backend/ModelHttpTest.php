<?php
/**
 * HTTP Integration Test: Model
 * 
 * Tests for: GET /ai/backend/model/index, POST /ai/backend/model/save, POST /ai/backend/model/copy
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing Model Endpoints ===\n\n";


// Test: Get model list
echo "Testing: Get model list...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/backend/model/index"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Save model
echo "Testing: Save model...\n";
$command = 'php bin/w http:request --method=POST --url="/ai/backend/model/save" --data='{"model_code":"test-model","supplier":"test"}'';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Copy model
echo "Testing: Copy model...\n";
$command = 'php bin/w http:request --method=POST --url="/ai/backend/model/copy" --data='{"id":"1"}'';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All Model tests completed ===\n";

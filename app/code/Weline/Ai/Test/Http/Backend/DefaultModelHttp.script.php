<?php
/**
 * HTTP Integration Test: DefaultModel
 * 
 * Tests for: GET /ai/backend/defaultmodel/index, POST /ai/backend/defaultmodel/setDefault
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing DefaultModel Endpoints ===\n\n";


// Test: Get default model config
echo "Testing: Get default model config...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/backend/defaultmodel/index"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Set default model
echo "Testing: Set default model...\n";
$command = 'php bin/w http:request --method=POST --url="/ai/backend/defaultmodel/setDefault" --data='{"service_type":"chat","model_code":"gpt-4"}'';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All DefaultModel tests completed ===\n";

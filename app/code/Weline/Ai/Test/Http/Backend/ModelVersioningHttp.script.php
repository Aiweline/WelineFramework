<?php
/**
 * HTTP Integration Test: ModelVersioning
 * 
 * Tests for: GET /ai/backend/modelversioning/index, POST /ai/backend/modelversioning/createVersion
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing ModelVersioning Endpoints ===\n\n";


// Test: Get version list
echo "Testing: Get version list...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/backend/modelversioning/index"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Create version
echo "Testing: Create version...\n";
$command = 'php bin/w http:request --method=POST --url="/ai/backend/modelversioning/createVersion" --data='{"model_id":"1"}'';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All ModelVersioning tests completed ===\n";

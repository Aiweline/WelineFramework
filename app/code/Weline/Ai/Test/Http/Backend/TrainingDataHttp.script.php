<?php
/**
 * HTTP Integration Test: TrainingData
 * 
 * Tests for: GET /ai/backend/trainingdata/index, POST /ai/backend/trainingdata/upload
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing TrainingData Endpoints ===\n\n";


// Test: Get training data list
echo "Testing: Get training data list...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/backend/trainingdata/index"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Upload training data
echo "Testing: Upload training data...\n";
$command = 'php bin/w http:request --method=POST --url="/ai/backend/trainingdata/upload" --data='{"file":"test.csv"}'';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All TrainingData tests completed ===\n";

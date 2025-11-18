<?php
/**
 * HTTP Integration Test: ModelBenchmark
 * 
 * Tests for: GET /ai/backend/modelbenchmark/index, POST /ai/backend/modelbenchmark/run
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing ModelBenchmark Endpoints ===\n\n";


// Test: Get benchmark list
echo "Testing: Get benchmark list...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/backend/modelbenchmark/index"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Run benchmark
echo "Testing: Run benchmark...\n";
$command = 'php bin/w http:request --method=POST --url="/ai/backend/modelbenchmark/run" --data='{"model_id":"1"}'';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All ModelBenchmark tests completed ===\n";

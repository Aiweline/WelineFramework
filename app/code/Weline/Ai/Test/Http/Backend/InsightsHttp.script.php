<?php
/**
 * HTTP Integration Test: Insights
 * 
 * Tests for: GET /ai/backend/insights/dashboard, GET /ai/backend/insights/report
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing Insights Endpoints ===\n\n";


// Test: Get insights dashboard
echo "Testing: Get insights dashboard...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/backend/insights/dashboard"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Get insights report
echo "Testing: Get insights report...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/backend/insights/report"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All Insights tests completed ===\n";

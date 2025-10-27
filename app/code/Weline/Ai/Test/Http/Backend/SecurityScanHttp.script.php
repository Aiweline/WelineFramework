<?php
/**
 * HTTP Integration Test: SecurityScan
 * 
 * Tests for: GET /ai/backend/securityscan/index, POST /ai/backend/securityscan/scan
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing SecurityScan Endpoints ===\n\n";


// Test: Get security scan list
echo "Testing: Get security scan list...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/backend/securityscan/index"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Run security scan
echo "Testing: Run security scan...\n";
$command = 'php bin/w http:request --method=POST --url="/ai/backend/securityscan/scan" --data='{"scan_type":"full"}'';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All SecurityScan tests completed ===\n";

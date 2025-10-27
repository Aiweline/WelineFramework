<?php
/**
 * HTTP Integration Test: CustomerSupport
 * 
 * Tests for: GET /ai/backend/customersupport/tickets, POST /ai/backend/customersupport/createTicket
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing CustomerSupport Endpoints ===\n\n";


// Test: Get ticket list
echo "Testing: Get ticket list...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/backend/customersupport/tickets"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Create ticket
echo "Testing: Create ticket...\n";
$command = 'php bin/w http:request --method=POST --url="/ai/backend/customersupport/createTicket" --data='{"subject":"Test Ticket"}'';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All CustomerSupport tests completed ===\n";

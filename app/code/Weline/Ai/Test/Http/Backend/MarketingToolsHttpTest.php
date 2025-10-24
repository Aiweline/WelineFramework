<?php
/**
 * HTTP Integration Test: MarketingTools
 * 
 * Tests for: GET /ai/backend/marketingtools/campaigns, POST /ai/backend/marketingtools/createCampaign
 * 
 * Usage: php bin/w http:request --method={METHOD} --url="{URL}" --data='{DATA}'
 */

echo "\n=== Testing MarketingTools Endpoints ===\n\n";


// Test: Get campaign list
echo "Testing: Get campaign list...\n";
$command = 'php bin/w http:request --method=GET --url="/ai/backend/marketingtools/campaigns"';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


// Test: Create campaign
echo "Testing: Create campaign...\n";
$command = 'php bin/w http:request --method=POST --url="/ai/backend/marketingtools/createCampaign" --data='{"campaign_name":"Test Campaign"}'';
echo "Command: $command\n";
system($command, $returnCode);
echo "Result: " . ($returnCode === 0 ? "SUCCESS" : "FAILED") . "\n\n";


echo "\n=== All MarketingTools tests completed ===\n";

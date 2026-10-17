<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiSiteProvisioning;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Api\AiSiteProvisioningInterface;
use Weline\Websites\Service\ProvisioningQueryHandler;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllWebsitesAiSiteProvisioningContractsPass().
 */
final class WebsitesAiSiteProvisioningContractsTest extends TestCase
{
    public function testAllWebsitesAiSiteProvisioningContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'AiSiteProvisioningBoundary::testQueryHandlerKeepsExistingOperationsAndAiBindingUsesPublicInterface' => function () {
            $this->assert_AiSiteProvisioningBoundary_testQueryHandlerKeepsExistingOperationsAndAiBindingUsesPublicInterface();
        },
            'AiSiteProvisioningBoundary::testQueueBoundaryHasNoControllerReflectionOrDirectRunnerAndNoPlanSnapshotPayload' => function () {
            $this->assert_AiSiteProvisioningBoundary_testQueueBoundaryHasNoControllerReflectionOrDirectRunnerAndNoPlanSnapshotPayload();
        },
            'AiSiteProvisioningBoundary::testQueryProviderMainExecuteMethodWasNotDuplicatedForTheNewBoundary' => function () {
            $this->assert_AiSiteProvisioningBoundary_testQueryProviderMainExecuteMethodWasNotDuplicatedForTheNewBoundary();
        },
        ];
    }

private function assert_AiSiteProvisioningBoundary_testQueryHandlerKeepsExistingOperationsAndAiBindingUsesPublicInterface(): void
{
    $operations = ProvisioningQueryHandler::operationNames();
    foreach (['startProvisioning', 'startPurchasedLifecycle', 'getOrder', 'getOrderByDomain', 'getDomainLifecycleStatus', 'getOrders', 'processOrder', 'runStepDns', 'runStepCdn', 'runStepSsl', 'switchNameservers', 'getOrderSteps', 'getPublicIp'] as $operation) {
        self::assertContains($operation, $operations);
    }
    self::assertNotContains('requestAiSiteProvisioning', $operations);
    self::assertNotContains('getAiSiteProvisioning', $operations);
    $handler = (new \ReflectionClass(ProvisioningQueryHandler::class))->newInstanceWithoutConstructor();
    $descriptorNames = \array_map(static fn(array $descriptor): string => (string) ($descriptor['name'] ?? ''), $handler->getDescriptorOperations());
    self::assertEqualsCanonicalizing($operations, $descriptorNames);
    $publicMethods = \array_map(static fn(\ReflectionMethod $method): string => $method->getName(), (new \ReflectionClass(AiSiteProvisioningInterface::class))->getMethods());
    self::assertEqualsCanonicalizing(['requestBinding', 'configureStartPage', 'getStatus', 'forceBindIgnoringLocalHosts'], $publicMethods);
}

private function assert_AiSiteProvisioningBoundary_testQueueBoundaryHasNoControllerReflectionOrDirectRunnerAndNoPlanSnapshotPayload(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Websites';
    $queueSource = (string) \file_get_contents($moduleRoot . '/Queue/AiSiteProvisioningQueue.php');
    $gatewaySource = (string) \file_get_contents($moduleRoot . '/Service/AiSiteProvisioningQueueGateway.php');
    self::assertStringNotContainsString('Controller', $queueSource);
    self::assertStringNotContainsString('Reflection', $queueSource);
    self::assertStringNotContainsString('queue:run', $queueSource . $gatewaySource);
    self::assertStringNotContainsString('plan_json', $queueSource . $gatewaySource);
    self::assertStringNotContainsString('purchaseDomain', $queueSource . $gatewaySource);
    self::assertStringNotContainsString('DomainPurchase', $queueSource . $gatewaySource);
    self::assertStringContainsString("\\w_query('queue', 'takeover'", $gatewaySource);
    self::assertStringNotContainsString("\\w_query('queue', 'dispatch'", $gatewaySource);
    self::assertStringContainsString("'dispatch' => false", $gatewaySource);
    self::assertStringNotContainsString("'status' => 'pending',\n            'patch'", $gatewaySource);
    self::assertMatchesRegularExpression("/'content'\\s*=>\\s*\\[\\s*'request_id'\\s*=>[^,]+,\\s*'execution_token'\\s*=>[^,]+,?\\s*\\]/s", $gatewaySource);
}

private function assert_AiSiteProvisioningBoundary_testQueryProviderMainExecuteMethodWasNotDuplicatedForTheNewBoundary(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Websites';
    $providerSource = (string) \file_get_contents($moduleRoot . '/extends/module/Weline_Framework/Query/WebsitesQueryProvider.php');
    self::assertStringNotContainsString('requestAiSiteProvisioning', $providerSource);
    self::assertStringNotContainsString('getAiSiteProvisioning', $providerSource);
}
}

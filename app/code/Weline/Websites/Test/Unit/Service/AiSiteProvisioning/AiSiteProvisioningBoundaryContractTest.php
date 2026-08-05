<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiSiteProvisioning;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Api\AiSiteProvisioningInterface;
use Weline\Websites\Service\ProvisioningQueryHandler;

final class AiSiteProvisioningBoundaryContractTest extends TestCase
{
    public function testQueryHandlerKeepsExistingOperationsAndAiBindingUsesPublicInterface(): void
    {
        $operations = ProvisioningQueryHandler::operationNames();

        foreach ([
            'startProvisioning',
            'startPurchasedLifecycle',
            'getOrder',
            'getOrderByDomain',
            'getDomainLifecycleStatus',
            'getOrders',
            'processOrder',
            'runStepDns',
            'runStepCdn',
            'runStepSsl',
            'switchNameservers',
            'getOrderSteps',
            'getPublicIp',
        ] as $operation) {
            self::assertContains($operation, $operations);
        }
        self::assertNotContains('requestAiSiteProvisioning', $operations);
        self::assertNotContains('getAiSiteProvisioning', $operations);

        $handler = (new \ReflectionClass(ProvisioningQueryHandler::class))->newInstanceWithoutConstructor();
        $descriptorNames = \array_map(
            static fn (array $descriptor): string => (string)($descriptor['name'] ?? ''),
            $handler->getDescriptorOperations()
        );
        self::assertEqualsCanonicalizing($operations, $descriptorNames);

        $publicMethods = \array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(AiSiteProvisioningInterface::class))->getMethods()
        );
        self::assertEqualsCanonicalizing(
            ['requestBinding', 'configureStartPage', 'getStatus', 'forceBindIgnoringLocalHosts'],
            $publicMethods
        );
    }

    public function testQueueBoundaryHasNoControllerReflectionOrDirectRunnerAndNoPlanSnapshotPayload(): void
    {
        $moduleRoot = \dirname(__DIR__, 4);
        $queueSource = (string)\file_get_contents($moduleRoot . '/Queue/AiSiteProvisioningQueue.php');
        $gatewaySource = (string)\file_get_contents($moduleRoot . '/Service/AiSiteProvisioningQueueGateway.php');

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
        self::assertMatchesRegularExpression(
            "/'content'\\s*=>\\s*\\[\\s*'request_id'\\s*=>[^,]+,\\s*'execution_token'\\s*=>[^,]+,?\\s*\\]/s",
            $gatewaySource
        );
    }

    public function testQueryProviderMainExecuteMethodWasNotDuplicatedForTheNewBoundary(): void
    {
        $moduleRoot = \dirname(__DIR__, 4);
        $providerSource = (string)\file_get_contents(
            $moduleRoot . '/extends/module/Weline_Framework/Query/WebsitesQueryProvider.php'
        );

        self::assertStringNotContainsString('requestAiSiteProvisioning', $providerSource);
        self::assertStringNotContainsString('getAiSiteProvisioning', $providerSource);
    }
}

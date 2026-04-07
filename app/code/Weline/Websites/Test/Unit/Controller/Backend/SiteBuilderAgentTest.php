<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Websites\Controller\Backend\SiteBuilderAgent;
use Weline\Websites\Service\AiWorkbench\DomainLifecycleBridgeService;
use Weline\Websites\Service\AiWorkbench\ProviderWorkbenchService;
use Weline\Websites\Service\AiWorkbench\SessionService;

class SiteBuilderAgentTest extends TestCase
{
    /**
     * Test that the controller class exists and has the expected public API methods.
     */
    public function testControllerHasRequiredPublicMethods(): void
    {
        $this->assertTrue(\method_exists(SiteBuilderAgent::class, 'getDomainLifecycleStatus'));
        $this->assertTrue(\method_exists(SiteBuilderAgent::class, 'getStageInfo'));
        $this->assertTrue(\method_exists(SiteBuilderAgent::class, 'getStateJson'));
        $this->assertTrue(\method_exists(SiteBuilderAgent::class, 'postCreateSession'));
        $this->assertTrue(\method_exists(SiteBuilderAgent::class, 'postDeleteSession'));
        $this->assertTrue(\method_exists(SiteBuilderAgent::class, 'postSetStage'));
    }

    /**
     * Test that the DomainLifecycleBridgeService has the required public methods.
     */
    public function testDomainLifecycleBridgeServiceHasRequiredPublicMethods(): void
    {
        $this->assertTrue(\method_exists(DomainLifecycleBridgeService::class, 'buildLifecycleStatus'));
        $this->assertTrue(\method_exists(DomainLifecycleBridgeService::class, 'appendLifecycleEvent'));
        $this->assertTrue(\method_exists(DomainLifecycleBridgeService::class, 'isDomainReadyForBuild'));
        $this->assertTrue(\method_exists(DomainLifecycleBridgeService::class, 'getStageLabel'));
    }

    /**
     * Test that SessionService has the loadByPublicId method used by new APIs.
     */
    public function testSessionServiceHasLoadByPublicIdMethod(): void
    {
        $this->assertTrue(\method_exists(SessionService::class, 'loadByPublicId'));
        $this->assertTrue(\method_exists(SessionService::class, 'deleteSessionByPublicId'));
    }

    /**
     * Test that ProviderWorkbenchService has the buildWorkbenchConfigForSession method used by getStageInfo.
     */
    public function testProviderWorkbenchServiceHasBuildWorkbenchConfigForSessionMethod(): void
    {
        $this->assertTrue(\method_exists(ProviderWorkbenchService::class, 'buildWorkbenchConfigForSession'));
    }
}

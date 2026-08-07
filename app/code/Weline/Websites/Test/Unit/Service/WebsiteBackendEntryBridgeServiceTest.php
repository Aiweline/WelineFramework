<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\WebsiteBackendEntryBridgeService;

final class WebsiteBackendEntryBridgeServiceTest extends TestCase
{
    public function testIssueTokenRejectsDefaultWebsite(): void
    {
        /** @var WebsiteBackendEntryBridgeService $bridge */
        $bridge = ObjectManager::getInstance(WebsiteBackendEntryBridgeService::class);
        $this->expectException(\InvalidArgumentException::class);
        $bridge->issueToken(0, 1);
    }

    public function testConsumeRejectsMalformedToken(): void
    {
        /** @var WebsiteBackendEntryBridgeService $bridge */
        $bridge = ObjectManager::getInstance(WebsiteBackendEntryBridgeService::class);
        $session = \Weline\Framework\Session\SessionFactory::getInstance()->createBackendSession();
        $this->expectException(\InvalidArgumentException::class);
        $bridge->consumeAndLogin('not-a-token', $session, '127.0.0.1');
    }
}

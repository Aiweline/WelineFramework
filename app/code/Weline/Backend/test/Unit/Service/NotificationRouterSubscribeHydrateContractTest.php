<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 锁死：订阅判定不得依赖 select()->fetch()->getId()（常不 hydrate → 外发全灭）。
 */
class NotificationRouterSubscribeHydrateContractTest extends TestCase
{
    public function testIsUserSubscribedUsesFetchArrayNotModelGetId(): void
    {
        $path = BP . '/app/code/Weline/Backend/Service/NotificationRouter.php';
        $this->assertFileExists($path);
        $src = (string) file_get_contents($path);
        $methodPos = strpos($src, 'function isUserSubscribed');
        $this->assertNotFalse($methodPos);
        $next = strpos($src, 'function getUserSubscribedChannels', $methodPos);
        $this->assertNotFalse($next);
        $body = substr($src, $methodPos, $next - $methodPos);
        $this->assertStringContainsString('fetchArray()', $body);
        $this->assertStringNotContainsString('->fetch()', $body);
        $this->assertStringNotContainsString('getId()', $body);
    }
}

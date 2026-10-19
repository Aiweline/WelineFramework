<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\WorkerPolicyKernel;

/**
 * Wrong/stale backend keys and bare /admin must fail closed as tiny 404s
 * before storefront theme work (see WorkerPolicyKernel::shouldDenyBackendKeyPath).
 */
final class WorkerPolicyBackendKeyEarlyRejectTest extends TestCase
{
    public function testStaleSecretShapedKeyIsDenied(): void
    {
        $denied = $this->invokeShouldDeny(
            '/admin_6aa79c6fe4c05/',
            [
                'backend_prefix' => 'I6bLo3Gu9V6Z605Z6Tl5O60aUyfwCkb5',
                'rest_backend_prefix' => 'toNsvJ36hatmzNuwNz1FliV7mDXdO57z',
                'protected_prefixes' => ['admin', 'rest_admin'],
            ],
        );
        self::assertTrue($denied, 'stale admin bookmark must early-deny');
    }

    public function testCorrectBackendPrefixIsNotDenied(): void
    {
        $prefix = 'I6bLo3Gu9V6Z605Z6Tl5O60aUyfwCkb5';
        $denied = $this->invokeShouldDeny(
            '/' . $prefix . '/admin/login',
            [
                'backend_prefix' => $prefix,
                'rest_backend_prefix' => 'toNsvJ36hatmzNuwNz1FliV7mDXdO57z',
                'protected_prefixes' => ['admin', 'rest_admin'],
            ],
        );
        self::assertFalse($denied);
    }

    public function testBareAdminWithoutKeyIsDenied(): void
    {
        $denied = $this->invokeShouldDeny(
            '/admin/login',
            [
                'backend_prefix' => 'I6bLo3Gu9V6Z605Z6Tl5O60aUyfwCkb5',
                'rest_backend_prefix' => 'toNsvJ36hatmzNuwNz1FliV7mDXdO57z',
                'protected_prefixes' => ['admin', 'rest_admin'],
            ],
        );
        self::assertTrue($denied);
    }

    public function testHyphenatedSeoSlugIsNotTreatedAsSecretKey(): void
    {
        $denied = $this->invokeShouldDeny(
            '/chinese-hanfu-dress-2024/',
            [
                'backend_prefix' => 'I6bLo3Gu9V6Z605Z6Tl5O60aUyfwCkb5',
                'rest_backend_prefix' => 'toNsvJ36hatmzNuwNz1FliV7mDXdO57z',
                'protected_prefixes' => ['admin', 'rest_admin'],
            ],
        );
        self::assertFalse($denied, 'hyphenated SEO slugs must reach router/404 theme path');
    }

    public function testKnownRestFrontendPrefixIsNotDenied(): void
    {
        $denied = $this->invokeShouldDeny(
            '/api1234567890abcd/framework/query-bin',
            [
                'backend_prefix' => 'I6bLo3Gu9V6Z605Z6Tl5O60aUyfwCkb5',
                'rest_backend_prefix' => 'toNsvJ36hatmzNuwNz1FliV7mDXdO57z',
                'rest_frontend_prefix' => 'api1234567890abcd',
                'known_area_prefixes' => ['api1234567890abcd'],
                'protected_prefixes' => ['admin', 'rest_admin'],
            ],
        );
        self::assertFalse($denied);
    }

    public function testWorkerPhpShortCircuitsPolicyDenyBeforeFiberAdmission(): void
    {
        $workerPhp = \dirname(__DIR__, 3) . '/bin/worker.php';
        $src = (string)\file_get_contents($workerPhp);
        $dispatchPos = \strpos($src, 'function wlsDispatchRequestFiberStep');
        self::assertNotFalse($dispatchPos);
        $fiberPos = \strpos($src, 'new \\Fiber(', $dispatchPos);
        self::assertNotFalse($fiberPos);
        $denyPos = \strpos($src, 'if (!$policyDecision->allowed)', $dispatchPos);
        self::assertNotFalse($denyPos);
        self::assertLessThan(
            $fiberPos,
            $denyPos,
            'policy deny must short-circuit before Fiber start / admission wait',
        );
        $slice = \substr($src, $denyPos, $fiberPos - $denyPos);
        self::assertStringContainsString('sendResponseAndCleanup', $slice);
        self::assertStringContainsString('return;', $slice);
    }

    /**
     * @param array<string, mixed> $matcher
     */
    private function invokeShouldDeny(string $path, array $matcher): bool
    {
        $kernel = (new \ReflectionClass(WorkerPolicyKernel::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(WorkerPolicyKernel::class, 'shouldDenyBackendKeyPath');
        $method->setAccessible(true);
        return (bool)$method->invoke($kernel, $path, $matcher);
    }
}

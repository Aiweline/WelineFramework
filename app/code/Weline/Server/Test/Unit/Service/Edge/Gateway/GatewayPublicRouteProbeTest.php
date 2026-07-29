<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayPublicRouteProbe;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;
use Weline\Server\Service\Edge\Gateway\ProjectIdentityStore;

final class GatewayPublicRouteProbeTest extends TestCase
{
    private string $root = '';
    private GatewayRegistrationBuilder $builder;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-public-probe-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir(
            $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl',
            0700,
            true,
        ));
        $canonical = \realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
        $this->builder = new GatewayRegistrationBuilder(
            new ProjectIdentityStore(
                $this->root,
                $this->root . DIRECTORY_SEPARATOR . 'host-state',
                $this->root . DIRECTORY_SEPARATOR . 'legacy-desired-state.json',
            ),
            new ProjectCertificateGenerationStore($this->root),
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testMissingCertificateReferenceIsFailClosedWithoutThrowing(): void
    {
        $registration = [
            'project_uuid' => '123e4567-e89b-42d3-a456-426614174000',
            'instance_id' => 'gateway-probe-test',
            'routes' => [[
                'domain' => 'probe.example.test',
                'backend_identity' => [
                    'generation' => 1,
                    'launch_id' => \str_repeat('a', 32),
                    'master_epoch' => 1,
                ],
                'certificate' => [
                    'cert' => [],
                ],
            ]],
        ];

        self::assertFalse(
            (new GatewayPublicRouteProbe($this->builder))->registrationIsHealthy(
                $registration,
                21443,
            ),
        );
    }

    public function testCertificateReferenceTraversalAndUnknownAliasAreRejected(): void
    {
        self::assertNull($this->builder->resolveCertificateSourceReference([
            'root_alias' => 'project_ssl',
            'relative_path' => '../outside.pem',
        ]));
        self::assertNull($this->builder->resolveCertificateSourceReference([
            'root_alias' => 'unknown',
            'relative_path' => 'certificate.pem',
        ]));
        self::assertNull($this->builder->resolveCertificateSourceReference([
            'root_alias' => 'project_ssl',
            'relative_path' => '',
        ]));
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root) || \is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink() ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($root);
    }
}

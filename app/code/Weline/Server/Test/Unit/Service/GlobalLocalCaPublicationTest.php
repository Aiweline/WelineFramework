<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\SslCertificateService;

require_once dirname(__DIR__, 6) . '/bootstrap_phpunit.php';

/**
 * 整机共享 Local CA 目录只允许「从无到有」发布本仓 CA；
 * 目录里已有任何 CA（可用或损坏）都不得覆盖——那可能是其他项目签发的。
 */
final class GlobalLocalCaPublicationTest extends TestCase
{
    public function testUnusableGlobalCaIsPreservedAndNotOverwritten(): void
    {
        $service = $this->probe();
        $this->writeGlobalCa($service->globalCaDir, 'foreign-broken-cert', 'foreign-broken-key');

        $synced = $service->sync(
            $service->projectCaDir . 'rootCA.pem',
            $service->projectCaDir . 'rootCA.key',
        );

        self::assertFalse($synced, 'An existing unusable shared CA must never be overwritten.');
        self::assertSame(
            'foreign-broken-cert',
            \file_get_contents($service->globalCaDir . 'rootCA.pem'),
            'Shared Local CA certificate bytes must stay untouched.',
        );
        self::assertSame(
            'foreign-broken-key',
            \file_get_contents($service->globalCaDir . 'rootCA.key'),
            'Shared Local CA key bytes must stay untouched.',
        );
    }

    public function testUsableGlobalCaFromAnotherProjectIsPreserved(): void
    {
        $service = $this->probe();
        $service->globalUsable = true;
        $service->fingerprintsMatch = false;
        $this->writeGlobalCa($service->globalCaDir, 'other-project-cert', 'other-project-key');

        $synced = $service->sync(
            $service->projectCaDir . 'rootCA.pem',
            $service->projectCaDir . 'rootCA.key',
        );

        self::assertFalse($synced);
        self::assertSame(
            'other-project-cert',
            \file_get_contents($service->globalCaDir . 'rootCA.pem'),
        );
        self::assertSame(
            'other-project-key',
            \file_get_contents($service->globalCaDir . 'rootCA.key'),
        );
    }

    public function testSameGlobalCaIsReusedWithoutRewrite(): void
    {
        $service = $this->probe();
        $service->globalUsable = true;
        $service->fingerprintsMatch = true;
        $this->writeGlobalCa($service->globalCaDir, 'same-cert', 'same-key');

        $synced = $service->sync(
            $service->projectCaDir . 'rootCA.pem',
            $service->projectCaDir . 'rootCA.key',
        );

        self::assertTrue($synced);
        self::assertSame('same-cert', \file_get_contents($service->globalCaDir . 'rootCA.pem'));
        self::assertSame('same-key', \file_get_contents($service->globalCaDir . 'rootCA.key'));
    }

    private function probe(): GlobalCaPublicationProbe
    {
        $projectDir = $this->makeDirectory();
        $globalDir = $this->makeDirectory();
        \file_put_contents($projectDir . 'rootCA.pem', 'project-cert');
        \file_put_contents($projectDir . 'rootCA.key', 'project-key');

        return new GlobalCaPublicationProbe($projectDir, $globalDir);
    }

    private function writeGlobalCa(string $globalDir, string $cert, string $key): void
    {
        \file_put_contents($globalDir . 'rootCA.pem', $cert);
        \file_put_contents($globalDir . 'rootCA.key', $key);
    }

    private function makeDirectory(): string
    {
        $tempRoot = \realpath(\sys_get_temp_dir());
        self::assertIsString($tempRoot);
        $dir = $tempRoot . DIRECTORY_SEPARATOR . 'wls-global-ca-' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($dir, 0700, true));
        $real = \realpath($dir);
        self::assertIsString($real);

        return \rtrim($real, '/\\') . DIRECTORY_SEPARATOR;
    }
}

final class GlobalCaPublicationProbe extends SslCertificateService
{
    public bool $globalUsable = false;
    public bool $fingerprintsMatch = false;

    public function __construct(
        public string $projectCaDir,
        public string $globalCaDir,
    ) {
        parent::__construct(true);
    }

    public function sync(string $certPath, string $keyPath): bool
    {
        return $this->syncGlobalLocalCertificateAuthority($certPath, $keyPath);
    }

    protected function getLocalCaDir(): string
    {
        return $this->projectCaDir;
    }

    protected function getGlobalLocalCaDir(bool $create = true): string
    {
        unset($create);

        return $this->globalCaDir;
    }

    protected function canUseLocalCertificateAuthorityPair(string $certPath, string $keyPath): bool
    {
        unset($keyPath);

        return $this->globalUsable
            || \str_starts_with($certPath, $this->projectCaDir);
    }

    protected function certificateFilesHaveSameFingerprint(string $leftPath, string $rightPath): bool
    {
        unset($leftPath, $rightPath);

        return $this->fingerprintsMatch;
    }
}

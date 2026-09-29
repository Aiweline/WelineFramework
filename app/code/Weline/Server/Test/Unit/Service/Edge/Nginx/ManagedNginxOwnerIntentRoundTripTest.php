<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter;
use Weline\Server\Service\Edge\Nginx\ManagedNginxInstaller;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPortAllocator;
use Weline\Server\Service\Edge\Nginx\ManagedNginxProcessManager;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;

final class ManagedNginxOwnerIntentRoundTripTest extends TestCase
{
    private string $root = '';
    private ManagedNginxPaths $paths;
    private ManagedNginxService $service;
    private string|false $previousLocalAppData = false;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-managed-nginx-atomic-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $canonicalRoot = \realpath($this->root);
        self::assertIsString($canonicalRoot);
        $this->root = $canonicalRoot;
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->previousLocalAppData = \getenv('LOCALAPPDATA');
            self::assertTrue(\putenv('LOCALAPPDATA=' . $this->root));
        }
        $this->paths = new ManagedNginxPaths($this->root, [
            'managed' => true,
            'install_root' => 'nginx-install',
            'runtime_root' => 'nginx-runtime',
        ]);
        $this->paths->ensureRuntimeDirectories();
        $this->service = new ManagedNginxService(
            $this->paths,
            new ManagedNginxInstaller($this->paths),
            new ManagedNginxConfigWriter($this->paths),
            new ManagedNginxProcessManager($this->paths),
            new ManagedNginxPortAllocator($this->paths),
        );
    }

    protected function tearDown(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::assertTrue(\putenv($this->previousLocalAppData === false
                ? 'LOCALAPPDATA'
                : 'LOCALAPPDATA=' . $this->previousLocalAppData));
        }
        $this->removeTree($this->root);
    }

    private function call(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($this->service,$method))->invoke($this->service,...$args);
    }

    private function lightOwner(): array
    {
        $config = "worker_processes 1;\n";
        file_put_contents($this->paths->confFile(),$config);
        $raw = ['transaction_id'=>str_repeat('a',32),'instance_name'=>'test-owner',
            'upstream_host'=>'127.0.0.1','upstream_port'=>19502,'upstream_ports'=>[19502],
            'config_generation'=>str_repeat('b',32),'config_sha256'=>hash('sha256',$config),
            'upstream_endpoint_sha256'=>hash('sha256','127.0.0.1:19502'),
            'tls_session_resumption_config_sha256'=>hash('sha256',$config),
            'tls_session_resumption_ssl_certificate_sha256'=>str_repeat('e',64),
            'tls_session_resumption_config_generation'=>str_repeat('b',32),
            'listen_http'=>18080,'listen_https'=>18443,'updated_at'=>'2026-09-29T00:00:00+00:00'];
        $this->call('writeOwnerIntent',$raw);
        $normalized=$this->call('readOwnerFile',$this->paths->ownerIntentFile());
        self::assertIsArray($normalized);
        // 生产 light reload 的两个 unset 分支移除了这些已规范化字段。
        foreach (array_keys($normalized) as $key) {
            if (str_starts_with($key,'tls_session_resumption_reload_')) { unset($normalized[$key]); }
        }
        foreach (['http3_runtime_verified','tls_session_resumption_runtime_verified',
            'tls_session_resumption_same_worker_runtime_verified','tls_session_resumption_cross_worker_runtime_verified'] as $key) {
            unset($normalized[$key]);
        }
        return $normalized;
    }

    public function testLightOwnerRoundTripCommitsWithoutClaimingUnperformedProofs(): void
    {
        $expected=$this->lightOwner();
        $this->call('writeOwnerIntent',$expected);
        $digest=hash_file('sha256',$this->paths->ownerIntentFile());
        $this->call('commitOwnerIntent',$expected);
        self::assertSame($digest,hash_file('sha256',$this->paths->ownerIntentFile()),'No concurrent writer was involved.');
        $committed=$this->call('readOwnerFile',$this->paths->ownerFile());
        self::assertSame($expected['transaction_id'],$committed['transaction_id']);
        self::assertFalse($committed['http3_runtime_verified']);
        self::assertFalse($committed['tls_session_resumption_runtime_verified']);
        self::assertFalse($committed['tls_session_resumption_reload_continuity_verified']);
    }

    public function testRealIdentityOrProofChangeStillRejectsCommit(): void
    {
        foreach (['transaction_id'=>str_repeat('c',32),'config_sha256'=>str_repeat('d',64),
            'upstream_port'=>19503,'updated_at'=>'2026-09-29T00:00:01+00:00',
            'http3_runtime_verified'=>true] as $key=>$value) {
            $expected=$this->lightOwner();
            $changed=$expected; $changed[$key]=$value;
            $this->call('writeOwnerIntent',$changed);
            try { $this->call('commitOwnerIntent',$expected); self::fail('Accepted changed '.$key); }
            catch (\RuntimeException $error) { self::assertStringContainsString('intent changed',$error->getMessage()); }
            self::assertFileDoesNotExist($this->paths->ownerFile());
        }
    }

    public function testOnlyExactWorkerDrainFailuresAreSoft(): void
    {
        foreach (['unable to prove old nginx worker generation drain',
            'old nginx workers did not drain before the reload generation deadline'] as $message) {
            self::assertTrue($this->call('isReloadWorkerDrainSoftFailure',$message));
        }
        foreach (['nginx PID identity changed after reload','nginx master exited after reload',
            'isolated nginx -t failed; existing workers were left unchanged',
            'reload signal failed: old nginx workers did not drain before the reload generation deadline',''] as $message) {
            self::assertFalse($this->call('isReloadWorkerDrainSoftFailure',$message));
        }
    }

    public function testSoftDrainNeedsBothLiveProofsAndHardFailureStillRollsBack(): void
    {
        $soft=['ok'=>false,'message'=>'unable to prove old nginx worker generation drain'];
        self::assertTrue($this->call('reloadActivationIsVerified',$soft,true,true,true));
        self::assertFalse($this->call('reloadActivationIsVerified',$soft,false,true,true));
        self::assertFalse($this->call('reloadActivationIsVerified',$soft,true,true,false));
        self::assertFalse($this->call('reloadActivationIsVerified',$soft,true,false,true));
        self::assertFalse($this->call('reloadActivationIsVerified',
            ['ok'=>false,'message'=>'nginx PID identity changed after reload'],true,true,true));
        self::assertTrue($this->call('reloadActivationIsVerified',['ok'=>true],true,true,true));
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

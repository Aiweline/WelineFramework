<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\{ManagedNginxService,ManagedNginxPaths,ManagedNginxConfigWriter,ManagedNginxInstaller,ManagedNginxProcessManager,ManagedNginxPortAllocator,ManagedNginxHostCacheState};

final class ManagedNginxHostInvalidationReceiptTest extends TestCase
{
    private string $root;
    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . '/wls-host-receipt-' . bin2hex(random_bytes(8));
        mkdir($path,0700,true);
        $this->root = realpath($path);
    }
    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($this->root);
    }
    private function service(array $config): array
    {
        $paths = new ManagedNginxPaths($this->root,$config+['runtime_root'=>'runtime','install_root'=>'install','listen_http'=>18093,'listen_https'=>18493]);
        $writer = new ManagedNginxConfigWriter($paths);
        $service = new ManagedNginxService($paths,new ManagedNginxInstaller($paths),$writer,
            new ManagedNginxProcessManager($paths),new ManagedNginxPortAllocator($paths));
        return [$service,$paths,$writer];
    }
    public function testUnmanagedAndDisabledReturnExplicitCompleteSkipWithCanonicalHosts(): void
    {
        foreach ([['managed'=>false],['managed'=>true,'edge_cache'=>false]] as $config) {
            [$service] = $this->service($config);
            $receipt = $service->invalidateHosts(['B.test.','A.test'],'skip-1');
            self::assertTrue($receipt['success']);
            self::assertTrue($receipt['completed']);
            self::assertFalse($receipt['applicable']);
            self::assertSame(['a.test','b.test'],$receipt['hosts']);
            self::assertSame($config['managed'] ? 'cache_disabled' : 'not_managed',$receipt['reason']);
        }
    }
    public function testDefaultDynamicDisabledSkipsWithoutLifecycleOrGenerationMutation(): void
    {
        [$service,$paths] = $this->service(['managed'=>true,'edge_cache'=>true]);
        $receipt=$service->invalidateHosts(['a.test'],'default-disabled');
        self::assertTrue($receipt['success']);
        self::assertTrue($receipt['completed']);
        self::assertFalse($receipt['applicable']);
        self::assertSame('cache_disabled',$receipt['reason']);
        self::assertFileDoesNotExist($paths->lifecycleLockFile(),'Skip must not enter reload lifecycle.');
        self::assertFileDoesNotExist($paths->confFile());
        self::assertTrue($paths->edgeCacheEnabled(),'Static cache remains enabled.');
    }

    public function testReloadFailureDoesNotCommitGenerationOrOperation(): void
    {
        [$service,$paths,$writer] = $this->service(['managed'=>true,'edge_cache'=>true,'edge_cache_dynamic'=>true]);
        $writer->write(19093,'127.0.0.1',['a.test']);
        $before = hash_file('sha256',$paths->confFile());
        $receipt = $service->invalidateHosts(['a.test'],'failure-1');
        self::assertFalse($receipt['success']);
        self::assertFalse($receipt['completed']);
        self::assertSame('reload_failed',$receipt['error_code']);
        self::assertSame($before,hash_file('sha256',$paths->confFile()));
        $state = ManagedNginxHostCacheState::read($paths->confFile());
        self::assertSame([],$state->generations());
        self::assertFalse($state->advance(['a.test'],'failure-1')['already_applied']);
    }
    public function testCommittedOperationReplayAndConflictUseStableReceipt(): void
    {
        [$service,$paths,$writer] = $this->service(['managed'=>true,'edge_cache'=>true,'edge_cache_dynamic'=>true]);
        $state = ManagedNginxHostCacheState::fromConfig('')->advance(['a.test'],'op-1')['state'];
        $writer->write(19093,'127.0.0.1',['a.test'],false,true,false,false,[],null,$state->toArray());
        $before = hash_file('sha256',$paths->confFile());
        $receipt = $service->invalidateHosts(['a.test'],'op-1');
        self::assertTrue($receipt['success']);
        self::assertSame('already_applied',$receipt['reason']);
        self::assertSame(['a.test'=>1],$receipt['generation_by_host']);
        self::assertSame($before,hash_file('sha256',$paths->confFile()));
        $conflict = $service->invalidateHosts(['b.test'],'op-1');
        self::assertFalse($conflict['completed']);
        self::assertSame('operation_conflict',$conflict['error_code']);
        self::assertSame($before,hash_file('sha256',$paths->confFile()));
    }
}

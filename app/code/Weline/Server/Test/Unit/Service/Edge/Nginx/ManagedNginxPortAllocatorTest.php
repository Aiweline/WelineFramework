<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPortAllocator;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPublicPortProbeInterface;
use Weline\Server\Service\MasterProcess;

/**
 * 托管 Nginx 是本项目的公网网关：默认必须监听公网 80/443，只有被**别人**占着
 * （或本用户无权绑定）才回退 8080/8443 + projectPortOffset，而且回退必须可见。
 */
final class ManagedNginxPortAllocatorTest extends TestCase
{
    private string $root = '';
    private string|false $previousListenHttp = false;
    private string|false $previousListenHttps = false;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-nginx-port-allocator-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $canonical = \realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;

        // 端口覆盖环境变量会盖掉本测试自己给的配置，先摘掉再还原。
        $this->previousListenHttp = \getenv('WLS_NGINX_LISTEN_HTTP');
        $this->previousListenHttps = \getenv('WLS_NGINX_LISTEN_HTTPS');
        \putenv('WLS_NGINX_LISTEN_HTTP');
        \putenv('WLS_NGINX_LISTEN_HTTPS');
    }

    protected function tearDown(): void
    {
        \putenv($this->previousListenHttp === false
            ? 'WLS_NGINX_LISTEN_HTTP'
            : 'WLS_NGINX_LISTEN_HTTP=' . $this->previousListenHttp);
        \putenv($this->previousListenHttps === false
            ? 'WLS_NGINX_LISTEN_HTTPS'
            : 'WLS_NGINX_LISTEN_HTTPS=' . $this->previousListenHttps);
        $this->removeTree($this->root);
    }

    public function testDefaultIsThePublicGatewayPorts(): void
    {
        $probe = new FakeManagedNginxPublicPortProbe();
        $ports = $this->allocate([], $probe);

        self::assertSame(80, $ports['http']);
        self::assertSame(443, $ports['https']);
        self::assertSame(ManagedNginxPortAllocator::SOURCE_PUBLIC_DEFAULT, $ports['source']);
        self::assertSame(ManagedNginxPortAllocator::SOURCE_PUBLIC_DEFAULT, $ports['http_source']);
        self::assertSame(ManagedNginxPortAllocator::SOURCE_PUBLIC_DEFAULT, $ports['https_source']);
        self::assertSame([80, 443], $probe->probed);
        self::assertSame([], $ports['notes']);
        self::assertSame(MasterProcess::getProjectPortOffset(), $ports['offset']);
    }

    public function testOccupiedPublicPortFallsBackToProjectOffsetAndSaysSo(): void
    {
        $probe = new FakeManagedNginxPublicPortProbe([
            80 => ManagedNginxPublicPortProbeInterface::STATE_FOREIGN,
        ]);
        $ports = $this->allocate([], $probe);
        $offset = MasterProcess::getProjectPortOffset();

        self::assertSame(8080 + $offset, $ports['http']);
        self::assertSame(443, $ports['https'], '只回退被占的那一端，另一端仍留在公网端口');
        self::assertSame(
            ManagedNginxPortAllocator::SOURCE_PROJECT_OFFSET_FALLBACK,
            $ports['source'],
        );
        self::assertSame(
            ManagedNginxPortAllocator::SOURCE_PROJECT_OFFSET_FALLBACK,
            $ports['http_source'],
        );
        self::assertSame(ManagedNginxPortAllocator::SOURCE_PUBLIC_DEFAULT, $ports['https_source']);
        self::assertCount(1, $ports['notes']);
        self::assertStringContainsString('HTTP 80', $ports['notes'][0]);
        self::assertStringContainsString('occupied', $ports['notes'][0]);
        self::assertStringContainsString((string)(8080 + $offset), $ports['notes'][0]);
    }

    public function testUnbindablePublicPortFallsBackWithItsOwnReason(): void
    {
        $probe = new FakeManagedNginxPublicPortProbe([
            443 => ManagedNginxPublicPortProbeInterface::STATE_UNBINDABLE,
        ]);
        $ports = $this->allocate([], $probe);

        self::assertSame(80, $ports['http']);
        self::assertSame(8443 + MasterProcess::getProjectPortOffset(), $ports['https']);
        self::assertCount(1, $ports['notes']);
        self::assertStringContainsString('not bindable', $ports['notes'][0]);
    }

    public function testOwnManagedNginxHoldingThePublicPortIsNotAFallback(): void
    {
        // 重载/证书续期路径：公网端口被本项目托管 Nginx 自己占着。判成「被占用」
        // 就会把一个健康的公网入口悄悄挪到高位端口上，所以 STATE_SELF 必须沿用。
        $probe = new FakeManagedNginxPublicPortProbe([
            80 => ManagedNginxPublicPortProbeInterface::STATE_SELF,
            443 => ManagedNginxPublicPortProbeInterface::STATE_SELF,
        ]);
        $ports = $this->allocate([], $probe);

        self::assertSame(80, $ports['http']);
        self::assertSame(443, $ports['https']);
        self::assertSame(ManagedNginxPortAllocator::SOURCE_PUBLIC_DEFAULT, $ports['source']);
        self::assertSame([], $ports['notes']);
    }

    public function testExplicitConfigWinsAndIsNeverProbed(): void
    {
        $probe = new FakeManagedNginxPublicPortProbe([
            80 => ManagedNginxPublicPortProbeInterface::STATE_FOREIGN,
        ]);
        $ports = $this->allocate(['listen_http' => 8081, 'listen_https' => 8444], $probe);

        self::assertSame(8081, $ports['http']);
        self::assertSame(8444, $ports['https']);
        self::assertSame(ManagedNginxPortAllocator::SOURCE_ENV, $ports['source']);
        self::assertSame([], $probe->probed, '运维点名了端口就不再探测占用');
    }

    public function testHalfConfiguredPairOnlyProbesTheUnsetHalf(): void
    {
        $probe = new FakeManagedNginxPublicPortProbe();
        $ports = $this->allocate(['listen_http' => 8081], $probe);

        self::assertSame(8081, $ports['http']);
        self::assertSame(443, $ports['https']);
        self::assertSame([443], $probe->probed);
        self::assertSame(ManagedNginxPortAllocator::SOURCE_ENV, $ports['http_source']);
        self::assertSame(ManagedNginxPortAllocator::SOURCE_PUBLIC_DEFAULT, $ports['https_source']);
        self::assertSame(ManagedNginxPortAllocator::SOURCE_PUBLIC_DEFAULT, $ports['source']);
    }

    public function testBothHalvesFallingBackStillStayDistinct(): void
    {
        $probe = new FakeManagedNginxPublicPortProbe([
            80 => ManagedNginxPublicPortProbeInterface::STATE_FOREIGN,
            443 => ManagedNginxPublicPortProbeInterface::STATE_UNBINDABLE,
        ]);
        $ports = $this->allocate([], $probe);
        $offset = MasterProcess::getProjectPortOffset();

        self::assertSame(8080 + $offset, $ports['http']);
        self::assertSame(8443 + $offset, $ports['https']);
        self::assertCount(2, $ports['notes']);
    }

    public function testExplicitPortCollidingWithResolvedPublicPortIsRejected(): void
    {
        // listen_http 被显式指到 443，而 443 正是 https 的默认公网端口：
        // 必须报错，不能静默生成一份自相冲突的 nginx.conf。
        $probe = new FakeManagedNginxPublicPortProbe();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must differ');
        $this->allocate(['listen_http' => 443], $probe);
    }

    public function testIdenticalExplicitPortsAreRejected(): void
    {
        $probe = new FakeManagedNginxPublicPortProbe();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must differ');
        $this->allocate(['listen_http' => 8443, 'listen_https' => 8443], $probe);
    }

    public function testDescribeSourceKeepsUnknownValuesVisible(): void
    {
        self::assertSame(
            __('公网默认 80/443'),
            ManagedNginxPortAllocator::describeSource(ManagedNginxPortAllocator::SOURCE_PUBLIC_DEFAULT),
        );
        self::assertSame(
            'something-new',
            ManagedNginxPortAllocator::describeSource('something-new'),
            '未知来源原样回显，不吞信息',
        );
    }

    /**
     * @param array<string,mixed> $config
     * @return array{
     *   http:int,https:int,offset:int,source:string,
     *   http_source:string,https_source:string,notes:list<string>
     * }
     */
    private function allocate(array $config, ManagedNginxPublicPortProbeInterface $probe): array
    {
        $paths = new ManagedNginxPaths($this->root, \array_merge([
            'managed' => true,
            'install_root' => 'nginx-install',
            'runtime_root' => 'nginx-runtime',
        ], $config));

        return (new ManagedNginxPortAllocator($paths, $probe))->allocate();
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

/**
 * 端口占用状态由测试给，避免把「80/443 在 CI 上是否空闲」变成测试依赖。
 */
final class FakeManagedNginxPublicPortProbe implements ManagedNginxPublicPortProbeInterface
{
    /** @var list<int> */
    public array $probed = [];

    /** @param array<int,string> $states */
    public function __construct(private readonly array $states = [])
    {
    }

    public function inspect(int $port): array
    {
        $this->probed[] = $port;
        $state = $this->states[$port] ?? self::STATE_FREE;

        return [
            'state' => $state,
            'pid' => $state === self::STATE_FOREIGN ? 4242 : 0,
            'pname' => $state === self::STATE_FOREIGN ? 'nginx' : '',
            'detail' => $state === self::STATE_FREE ? '' : 'fake probe detail for port ' . $port,
        ];
    }
}

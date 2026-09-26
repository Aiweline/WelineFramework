<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPublicPortProbe;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPublicPortProbeInterface;

/**
 * 公网端口探测必须用**通配地址**判定占用，并且能区分「被外人占着」和
 * 「被本项目自己的托管 Nginx 占着」—— 后者是重载/证书续期路径，绝不能回退。
 */
final class ManagedNginxPublicPortProbeTest extends TestCase
{
    private string $root = '';
    /** @var list<resource> */
    private array $listeners = [];
    /** @var resource|null */
    private $helperProcess = null;
    private int $helperPid = 0;
    private string|false $previousListenHttp = false;
    private string|false $previousListenHttps = false;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-nginx-public-port-probe-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $canonical = \realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;

        $this->previousListenHttp = \getenv('WLS_NGINX_LISTEN_HTTP');
        $this->previousListenHttps = \getenv('WLS_NGINX_LISTEN_HTTPS');
        \putenv('WLS_NGINX_LISTEN_HTTP');
        \putenv('WLS_NGINX_LISTEN_HTTPS');
    }

    protected function tearDown(): void
    {
        foreach ($this->listeners as $listener) {
            if (\is_resource($listener)) {
                @\fclose($listener);
            }
        }
        $this->listeners = [];
        if (\is_resource($this->helperProcess)) {
            @\proc_terminate($this->helperProcess, 9);
            @\proc_close($this->helperProcess);
        }
        $this->helperProcess = null;
        $this->helperPid = 0;
        \putenv($this->previousListenHttp === false
            ? 'WLS_NGINX_LISTEN_HTTP'
            : 'WLS_NGINX_LISTEN_HTTP=' . $this->previousListenHttp);
        \putenv($this->previousListenHttps === false
            ? 'WLS_NGINX_LISTEN_HTTPS'
            : 'WLS_NGINX_LISTEN_HTTPS=' . $this->previousListenHttps);
        $this->removeTree($this->root);
    }

    public function testAnIdlePortIsReportedFree(): void
    {
        $port = $this->freePort();
        $verdict = $this->probe()->inspect($port);

        self::assertSame(ManagedNginxPublicPortProbeInterface::STATE_FREE, $verdict['state']);
        self::assertSame(0, $verdict['pid']);
        self::assertSame('', $verdict['detail']);
    }

    public function testAWildcardListenerIsReportedForeign(): void
    {
        // 托管 Nginx 的 conf 里是裸 `listen 80;`，即绑定 0.0.0.0。探测也必须绑
        // 通配地址：BSD/macOS 允许「具体地址」与「通配监听」共存，绑 127.0.0.1:PORT
        // 在别人已经 listen *:PORT 时仍会成功，从而把已占用的端口误判成空闲。
        $port = $this->holdPort();
        $verdict = $this->probe()->inspect($port);

        self::assertSame(ManagedNginxPublicPortProbeInterface::STATE_FOREIGN, $verdict['state']);
        self::assertStringContainsString((string)$port, $verdict['detail']);
    }

    public function testTheProjectsOwnManagedNginxIsNotAForeignOccupant(): void
    {
        $port = $this->holdPort();
        $this->writePidFile((int)\getmypid());
        $this->writeOwnerRecord($port);

        $verdict = $this->probe()->inspect($port);

        self::assertSame(ManagedNginxPublicPortProbeInterface::STATE_SELF, $verdict['state']);
        self::assertSame((int)\getmypid(), $verdict['pid']);
    }

    public function testAnOwnerRecordAloneCannotClaimThePort(): void
    {
        // owner 记录说「这个端口归我」，但 pid 文件不存在（没有存活的 master）：
        // 两个门禁缺一不可，否则一份过期的 owner 文件就能让探测认错端口。
        $port = $this->holdPort();
        $this->writeOwnerRecord($port);

        $verdict = $this->probe()->inspect($port);

        self::assertSame(ManagedNginxPublicPortProbeInterface::STATE_FOREIGN, $verdict['state']);
    }

    public function testTheActiveConfigProvesThePortIsOursEvenWhenTheKernelListenerPidIsAmbiguous(): void
    {
        // 本机实测的坑：同一个端口可以被**多个**进程同时 listen（macOS 允许具体
        // 地址与通配监听共存），而 Processer::getProcessIdByPort() 只返回 lsof 的
        // 第一条 —— 托管 Nginx master 持 *:443 时它却返回了另一个只监听
        // 127.0.0.1:443 的桌面应用 PID，于是自己的网关被判成外人占用、被挪到高位端口。
        // 所以「我们的 master 活着 + 生效 conf 声明了这个端口」必须足以判定为自己。
        //
        // 这里用无关的存活进程当 master（不是占用端口的本测试进程），
        // 才能真正隔离出「靠 conf 认定」这条路径。
        $port = $this->holdPort();
        $this->writePidFile($this->liveUnrelatedPid());
        $this->writeActiveConfig($port);

        $verdict = $this->probe()->inspect($port);

        self::assertSame(ManagedNginxPublicPortProbeInterface::STATE_SELF, $verdict['state']);
    }

    public function testTheActiveConfigDoesNotClaimAPortItDoesNotDeclare(): void
    {
        $port = $this->holdPort();
        $this->writePidFile($this->liveUnrelatedPid());
        // conf 只声明了 80，没声明被占的 $port。
        $this->writeActiveConfig(80);

        $verdict = $this->probe()->inspect($port);

        self::assertSame(ManagedNginxPublicPortProbeInterface::STATE_FOREIGN, $verdict['state']);
    }

    public function testOutOfRangePortsAreReportedFreeInsteadOfThrowing(): void
    {
        foreach ([0, -1, 65536, 70000] as $port) {
            $verdict = $this->probe()->inspect($port);
            self::assertSame(
                ManagedNginxPublicPortProbeInterface::STATE_FREE,
                $verdict['state'],
                'port ' . $port . ' 不该让探测抛异常',
            );
        }
    }

    private function probe(): ManagedNginxPublicPortProbe
    {
        return new ManagedNginxPublicPortProbe($this->paths());
    }

    /**
     * 每次都按同一份配置重建 paths，并保证 runtime 目录已存在 —— 写 pid/owner
     * 夹具之前必须先建目录，否则 file_put_contents 会静默失败。
     */
    private function paths(): ManagedNginxPaths
    {
        $paths = new ManagedNginxPaths($this->root, [
            'managed' => true,
            'install_root' => 'nginx-install',
            'runtime_root' => 'nginx-runtime',
        ]);
        $paths->ensureRuntimeDirectories();

        return $paths;
    }

    /**
     * 占住一个通配端口，模拟「别人已经在 listen *:PORT」。
     */
    private function holdPort(): int
    {
        $port = $this->freePort();
        $errno = 0;
        $errstr = '';
        $listener = @\stream_socket_server(
            'tcp://0.0.0.0:' . $port,
            $errno,
            $errstr,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($listener, 'unable to hold port ' . $port . ': ' . $errstr);
        $this->listeners[] = $listener;

        return $port;
    }

    private function freePort(): int
    {
        $errno = 0;
        $errstr = '';
        $server = @\stream_socket_server('tcp://0.0.0.0:0', $errno, $errstr, \STREAM_SERVER_BIND);
        self::assertIsResource($server, 'unable to reserve a probe port: ' . $errstr);
        $name = \stream_socket_get_name($server, false);
        @\fclose($server);
        self::assertIsString($name);
        $port = (int)\substr((string)\strrchr($name, ':'), 1);
        self::assertGreaterThan(1024, $port);

        return $port;
    }

    private function writePidFile(int $pid): void
    {
        $file = $this->paths()->pidFile();
        self::assertNotFalse(\file_put_contents($file, $pid . "\n"));
    }

    private function writeOwnerRecord(int $port): void
    {
        $file = $this->paths()->ownerFile();
        self::assertNotFalse(\file_put_contents($file, (string)\json_encode([
            'instance_name' => 'probe-fixture',
            'upstream_host' => '127.0.0.1',
            'upstream_port' => 9981,
            'listen_http' => $port,
            'listen_https' => $port + 1,
            'config_generation' => \str_repeat('a', 32),
        ], JSON_THROW_ON_ERROR)));
    }

    /**
     * 写一份「生效中」的 nginx.conf，只声明 listen $listenPort。
     */
    private function writeActiveConfig(int $listenPort): void
    {
        $conf = \implode("\n", [
            'worker_processes  auto;',
            'pid        run/nginx.pid;',
            'events {}',
            'http {',
            '    server {',
            '        listen ' . $listenPort . ';',
            '    }',
            '}',
        ]) . "\n";
        self::assertNotFalse(\file_put_contents($this->paths()->confFile(), $conf));
    }

    /**
     * 一个**存活但与端口无关**的进程 PID，用来当 pid 文件里的 master：
     * 只有 master 不是端口占用者，才能隔离出「靠 owner 记录/生效 conf 认定」的路径。
     */
    private function liveUnrelatedPid(): int
    {
        if ($this->helperPid > 0) {
            return $this->helperPid;
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX helper process fixture.');
        }
        $process = \proc_open(
            ['sleep', '120'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process, 'unable to start the helper process');
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
        }
        $status = \proc_get_status($process);
        $pid = (int)($status['pid'] ?? 0);
        self::assertGreaterThan(0, $pid);
        $this->helperProcess = $process;
        $this->helperPid = $pid;

        return $pid;
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

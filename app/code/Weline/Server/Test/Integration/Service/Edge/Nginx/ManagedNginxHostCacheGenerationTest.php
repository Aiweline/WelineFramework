<?php

declare(strict_types=1);

namespace Weline\Server\Test\Integration\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;
use Weline\Server\Service\Edge\Nginx\ManagedNginxProcessManager;
use Weline\Server\Service\Edge\Nginx\Runtime\NginxLiveProbe;

final class ManagedNginxHostCacheGenerationTest extends TestCase
{
    private $origin = null;
    private \Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter $writer;
    private int $originPort;
    private string $versionFile;
    private ManagedNginxPaths $paths;
    private string $root = '';
    private ?ManagedNginxProcessManager $manager = null;
    private int $startedPid = 0;
    private int $listenPort = 0;

    protected function setUp(): void
    {
        if ((string)\getenv('WLS_RUN_NGINX_INTEGRATION') !== '1') {
            self::markTestSkipped('Set WLS_RUN_NGINX_INTEGRATION=1 for the real Nginx lifecycle test.');
        }
        $seedProject = \realpath((string)\getenv('WLS_NGINX_SEED_PROJECT'));
        if (!\is_string($seedProject) || $seedProject === '') {
            self::markTestSkipped('WLS_NGINX_SEED_PROJECT must point at a project with managed Nginx.');
        }
        if (!\defined('BP')) {
            \define('BP', $seedProject);
        }
        $seedConfig = ['managed' => true, 'auto_start' => true];
        $seedInstallRoot = \trim((string)\getenv('WLS_NGINX_SEED_INSTALL_ROOT'));
        if ($seedInstallRoot !== '') {
            $normalizedSeedRoot = \str_replace('\\', '/', $seedInstallRoot);
            if (\str_starts_with($normalizedSeedRoot, '/')
                || \preg_match('/\A[A-Za-z]:/', $normalizedSeedRoot) === 1
                || \in_array('..', \explode('/', $normalizedSeedRoot), true)
                || \str_contains($normalizedSeedRoot, "\0")
            ) {
                self::fail('WLS_NGINX_SEED_INSTALL_ROOT must be a contained relative path.');
            }
            $seedConfig['install_root'] = $seedInstallRoot;
        }
        $seed = new ManagedNginxPaths($seedProject, $seedConfig);
        if (!$seed->isInstalled() || !\is_file($seed->manifestFile())) {
            self::markTestSkipped('Managed Nginx seed binary or manifest is unavailable.');
        }

        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-nginx-host-cache-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $this->root = realpath($this->root);
        $this->listenPort = $this->allocatePort();
        $paths = new ManagedNginxPaths($this->root, [
            'listen_http' => $this->listenPort,
            'listen_https' => $this->allocatePort(),
            'edge_cache' => true,
            'edge_cache_dynamic' => true,
            'worker_processes' => 1,
            'managed' => true,
            'auto_start' => true,
            'install_root' => 'nginx-install',
            'runtime_root' => 'nginx-runtime',
        ]);
        self::assertTrue(\mkdir(\dirname($paths->binary()), 0700, true));
        self::assertTrue(\copy($seed->binary(), $paths->binary()));
        self::assertTrue(\chmod($paths->binary(), 0700));
        $manifest = \json_decode((string)\file_get_contents($seed->manifestFile()), true);
        self::assertIsArray($manifest);
        $manifest['schema_version'] = 2;
        $manifest['role'] = 'legacy-project-nginx';
        $manifest['binary'] = $paths->binary();
        $manifest['prefix'] = $paths->installRoot();
        $manifest['binary_sha256'] = \hash_file('sha256', $paths->binary());
        unset($manifest['runtime_generation']);
        $canonicalize = function (array $a) use (&$canonicalize): array {
            if (!array_is_list($a)) { ksort($a, SORT_STRING); }
            foreach ($a as $k=>$v) { if (is_array($v)) { $a[$k]=$canonicalize($v); } }
            return $a;
        };
        $manifest = $canonicalize($manifest);
        $manifest['runtime_generation'] = \hash('sha256', \json_encode(
            $manifest,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        self::assertNotFalse(\file_put_contents(
            $paths->manifestFile(),
            \json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
        $paths->ensureRuntimeDirectories();
        $this->paths = $paths;
        $this->originPort = $this->allocatePort();
        $this->versionFile = $this->root . '/version';
        file_put_contents($this->versionFile, 'old');
        $router = $this->root . '/origin.php';
        file_put_contents($router, '<?php $v=file_get_contents(__DIR__."/version"); '
            . 'if(str_starts_with($_SERVER["REQUEST_URI"],"/slow")){file_put_contents(__DIR__."/started","1");sleep(3);} '
            . 'header("Cache-Control: public, max-age=120");header("Content-Type: text/plain");echo $v;');
        $this->origin = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $this->originPort, $router],
            [0=>['pipe','r'],1=>['file',$this->root . '/origin.log','a'],2=>['file',$this->root . '/origin.log','a']], $pipes);
        self::assertIsResource($this->origin);
        fclose($pipes[0]);
        for ($i=0;$i<50;$i++) {
            $socket = @fsockopen('127.0.0.1', $this->originPort);
            if (is_resource($socket)) { fclose($socket); break; }
            usleep(20000);
        }
        $this->writer = new \Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter($paths);
        $this->writer->write($this->originPort, '127.0.0.1', ['a.test','b.test']);
        $this->manager = new ManagedNginxProcessManager($paths);
    }

    protected function tearDown(): void
    {
        if ($this->manager !== null) {
            $this->manager->stop();
        }
        $this->cleanupOwnedPid();
        if (is_resource($this->origin)) { proc_terminate($this->origin); proc_close($this->origin); }
        $this->removeTree($this->root);
    }

    public function testNewHostGenerationMissesOldCacheAndPreservesOtherHostAndStatic(): void
    {
        $start = $this->manager->start();
        $this->startedPid = (int)($start['pid'] ?? 0);
        self::assertTrue($start['ok'], (string)($start['message'] ?? 'start failed'));
        foreach ([['a.test','/page'],['b.test','/page'],['a.test','/asset.css']] as [$host,$path]) {
            self::assertSame('old', $this->request($host,$path)['body']);
            self::assertSame('HIT', $this->request($host,$path)['cache']);
        }
        $slowOutput = $this->root . '/slow-response';
        $slow = proc_open(['curl','--silent','--max-time','15','--header','Host: a.test',
            'http://127.0.0.1:' . $this->listenPort . '/slow'],
            [0=>['pipe','r'],1=>['file',$slowOutput,'w'],2=>['file',$this->root . '/slow-error','w']], $pipes);
        self::assertIsResource($slow);
        fclose($pipes[0]);
        for ($i=0;$i<100 && !is_file($this->root . '/started');$i++) { usleep(10000); }
        self::assertFileExists($this->root . '/started');
        file_put_contents($this->versionFile, 'new');
        self::assertSame('old', $this->request('a.test','/page')['body']);
        $stateClass = \Weline\Server\Service\Edge\Nginx\ManagedNginxHostCacheState::class;
        $plan = $stateClass::read($this->paths->confFile())->advance(['a.test'], 'live-operation:1');
        $state = $plan['state']->toArray();
        $candidate = $this->writer->write($this->originPort,'127.0.0.1',['a.test','b.test'],false,true,true,false,[],null,$state);
        self::assertSame(0, $this->manager->testConfig($candidate['conf'])['code']);
        $publication = $this->writer->publishCandidate($candidate['conf'],bin2hex(random_bytes(16)));
        $reload = $this->manager->reload();
        self::assertTrue($reload['ok'], (string)($reload['message'] ?? 'reload failed'));
        $probe = (new NginxLiveProbe())->probeHttp(address:'127.0.0.1',port:$this->listenPort,host:'a.test',
            path:'/_wls/health',expectedHeaders:['X-Wls-Nginx-Config'=>$candidate['config_generation']],
            maxAttempts:20,requiredConsecutive:2);
        self::assertTrue($probe['ok'], (string)$probe['reason']);
        self::assertTrue($this->writer->commitPublished($publication['rollback'] ?? null));
        $first = $this->request('a.test','/page');
        self::assertSame('MISS', $first['cache'], 'New host generation must not serve the old HIT.');
        self::assertSame('new', $first['body']);
        self::assertSame('HIT', $this->request('a.test','/page')['cache']);
        self::assertSame('old', $this->request('b.test','/page')['body']);
        self::assertSame('old', $this->request('a.test','/asset.css')['body']);
        self::assertSame(0, proc_close($slow));
        self::assertSame('old', file_get_contents($slowOutput), 'Old request completed across publication.');
        $slowAfter = $this->request('a.test','/slow');
        self::assertSame('MISS', $slowAfter['cache'], 'Old in-flight fill must stay unreachable.');
        self::assertSame('new', $slowAfter['body']);
        self::assertSame('HIT', $this->request('a.test','/slow')['cache']);
        $replay = $stateClass::read($this->paths->confFile())->advance(['a.test'], 'live-operation:1');
        self::assertTrue($replay['already_applied']);
        self::assertSame(['a.test'=>1], $replay['state']->generations());

        // 发布失败后恢复原配置，代次与幂等记录必须同时回滚。
        $next = $replay['state']->advance(['a.test'], 'live-operation:2');
        $bad = $this->writer->write($this->originPort,'127.0.0.1',['a.test','b.test'],false,true,true,false,[],null,$next['state']->toArray());
        file_put_contents($bad['conf'], "\ninvalid_test_directive;\n", FILE_APPEND);
        self::assertNotSame(0, $this->manager->testConfig($bad['conf'])['code']);
        $failed = $this->writer->publishCandidate($bad['conf'],bin2hex(random_bytes(16)));
        self::assertFalse($this->manager->reload()['ok']);
        $this->writer->rollbackPublished($failed['rollback'] ?? null);
        $afterFailure = $stateClass::read($this->paths->confFile());
        self::assertSame(['a.test'=>1], $afterFailure->generations());
        self::assertFalse($afterFailure->advance(['a.test'], 'live-operation:2')['already_applied']);
        self::assertSame('HIT', $this->request('a.test','/page')['cache']);
        self::assertSame('new', $this->request('a.test','/page')['body']);
        // 普通后续 writer 也必须保留已提交代次。
        $normal = $this->writer->write($this->originPort,'127.0.0.1',['a.test','b.test'],false,true,true);
        self::assertSame(['a.test'=>1], $stateClass::read($normal['conf'])->generations());
        $this->writer->discardCandidate($normal['conf']);
    }

    public function testDynamicCacheDisabledMakesOldEntriesUnreachableWhileStaticStaysHit(): void
    {
        $start=$this->manager->start();
        $this->startedPid=(int)($start['pid'] ?? 0);
        self::assertTrue($start['ok'],(string)($start['message'] ?? 'start failed'));
        foreach (['/page','/api/catalog','/asset.css'] as $path) {
            self::assertSame('old',$this->request('a.test',$path)['body']);
            self::assertSame('HIT',$this->request('a.test',$path)['cache']);
        }
        // 模块默认关闭动态缓存；不传新开关即走新默认。
        $config=$this->paths->config();unset($config['edge_cache_dynamic']);
        $paths=new ManagedNginxPaths($this->root,$config);
        $writer=new \Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter($paths);
        $candidate=$writer->write($this->originPort,'127.0.0.1',['a.test','b.test'],false,true,true);
        self::assertSame(0,$this->manager->testConfig($candidate['conf'])['code']);
        $publication=$writer->publishCandidate($candidate['conf'],bin2hex(random_bytes(16)));
        self::assertTrue($this->manager->reload()['ok']);
        $probe=(new NginxLiveProbe())->probeHttp(address:'127.0.0.1',port:$this->listenPort,host:'a.test',
            path:'/_wls/health',expectedHeaders:['X-Wls-Nginx-Config'=>$candidate['config_generation']],
            maxAttempts:20,requiredConsecutive:2);
        self::assertTrue($probe['ok'],(string)$probe['reason']);
        self::assertTrue($writer->commitPublished($publication['rollback'] ?? null));
        foreach (['new','latest'] as $version) {
            file_put_contents($this->versionFile,$version);
            foreach (['/page','/api/catalog'] as $path) {
                $response=$this->request('a.test',$path);
                self::assertSame($version,$response['body'],'Dynamic response must reach current origin.');
                self::assertSame('',$response['cache'],'Dynamic response must not read or write Nginx proxy cache.');
            }
            $static=$this->request('a.test','/asset.css');
            self::assertSame('HIT',$static['cache']);
            self::assertSame('old',$static['body']);
        }
    }

    private function request(string $host,string $path): array
    {
        $curl = curl_init('http://127.0.0.1:' . $this->listenPort . $path);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>10,
            CURLOPT_HTTPHEADER=>['Host: '.$host,'Connection: close']]);
        $response = curl_exec($curl);
        self::assertIsString($response, curl_error($curl));
        $size = curl_getinfo($curl,CURLINFO_HEADER_SIZE);
        curl_close($curl);
        preg_match('/^X-Wls-Edge-Cache:\s*(\S+)/mi',substr($response,0,$size),$match);
        return ['body'=>substr($response,$size),'cache'=>$match[1] ?? '', 'headers'=>substr($response,0,$size)];
    }

    private function allocatePort(): int
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, $error);
        $address = \stream_socket_get_name($socket, false);
        \fclose($socket);
        self::assertIsString($address);
        $port = (int)\substr($address, (int)\strrpos($address, ':') + 1);
        self::assertGreaterThanOrEqual(9502, $port);
        return $port;
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

    private function cleanupOwnedPid(): void
    {
        if ($this->startedPid < 1 || $this->root === '') {
            return;
        }
        $output = [];
        if (\PHP_OS_FAMILY === 'Windows') {
            @\exec(
                'wmic process where processid=' . $this->startedPid . ' get CommandLine /value 2>NUL',
                $output,
            );
        } else {
            @\exec('ps -p ' . $this->startedPid . ' -o command= 2>/dev/null', $output);
        }
        $command = \implode("\n", $output);
        if (!\str_contains($command, $this->root . DIRECTORY_SEPARATOR . 'nginx-install')
            || !\str_contains($command, $this->root . DIRECTORY_SEPARATOR . 'nginx-runtime')
        ) {
            return;
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            @\exec('taskkill /PID ' . $this->startedPid . ' /T /F 2>NUL');
            return;
        }
        if (\function_exists('posix_kill')) {
            @\posix_kill($this->startedPid, 15);
            for ($attempt = 0; $attempt < 20 && @\posix_kill($this->startedPid, 0); $attempt++) {
                \usleep(50_000);
            }
            if (@\posix_kill($this->startedPid, 0)) {
                @\posix_kill($this->startedPid, 9);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Connection\Pool;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\DbManager\ConfigProvider;

/**
 * 连接归因：`application_name = weline:w<workerId|m<pid>>:<poolShort>`。
 * 名字写进 DSN（连接启动包参数），归还时的 DISCARD ALL 不会重置它。
 */
final class ConnectionPoolApplicationNameTest extends TestCase
{
    private string $previousWorkerId = '';

    protected function setUp(): void
    {
        $this->previousWorkerId = (string) (\getenv('WLS_WORKER_ID') ?: '');
    }

    protected function tearDown(): void
    {
        \putenv('WLS_WORKER_ID=' . $this->previousWorkerId);
    }

    public function testWorkerIdIsEncodedWhenPresent(): void
    {
        \putenv('WLS_WORKER_ID=7');
        $config = $this->config();

        $name = ConnectionPool::applicationNameFor($config);

        self::assertMatchesRegularExpression('/\Aweline:w7:[a-f0-9]{6}\z/', $name);
        self::assertSame($name, ConnectionPool::applicationNameFor($config), '同一库配置必须稳定');
    }

    public function testPidFallbackWhenWorkerIdMissing(): void
    {
        \putenv('WLS_WORKER_ID');

        $name = ConnectionPool::applicationNameFor($this->config());

        self::assertMatchesRegularExpression('/\Aweline:wm\d+:[a-f0-9]{6}\z/', $name, 'master/CLI 必须用 pid 兜底，不能都叫 w0');
    }

    public function testDifferentDatabasesGetDifferentPoolShort(): void
    {
        \putenv('WLS_WORKER_ID=7');
        $a = ConnectionPool::applicationNameFor($this->config('db_a'));
        $b = ConnectionPool::applicationNameFor($this->config('db_b'));

        self::assertNotSame($a, $b);
    }

    private function config(string $database = 'weline_unit_appname'): ConfigProvider
    {
        return new ConfigProvider([
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => $database,
            'username' => 'unit',
            'password' => 'unit',
            'charset' => 'utf8',
            'pool_size' => 1,
            'persistent' => false,
            'path' => 'probe/appname',
        ]);
    }
}

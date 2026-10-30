<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Runtime\Runtime;

/**
 * PDO 选项契约：属性键必须是 PDO 常量本身。
 * array_merge 会把数字键重新编号，使 ATTR_PERSISTENT/ATTR_TIMEOUT/ATTR_ERRMODE 全部错位。
 */
final class ConfigProviderOptionContractTest extends TestCase
{
    public function testPdoAttributeKeysArePreserved(): void
    {
        $options = $this->config()->getOptions();

        self::assertArrayHasKey(PDO::ATTR_ERRMODE, $options);
        self::assertSame(PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);
        self::assertArrayHasKey(PDO::ATTR_DEFAULT_FETCH_MODE, $options);
        self::assertSame(PDO::FETCH_ASSOC, $options[PDO::ATTR_DEFAULT_FETCH_MODE]);
        self::assertArrayHasKey(PDO::ATTR_AUTOCOMMIT, $options);
        self::assertTrue($options[PDO::ATTR_AUTOCOMMIT]);
    }

    public function testConfiguredTimeoutReachesPdoAttribute(): void
    {
        $options = $this->config()->getOptions();

        self::assertArrayHasKey(PDO::ATTR_TIMEOUT, $options);
        self::assertSame(30, $options[PDO::ATTR_TIMEOUT]);
    }

    public function testUserOptionsOverrideWithoutRenumbering(): void
    {
        $config = $this->config(['options' => [
            PDO::ATTR_CASE => PDO::CASE_LOWER,
            PDO::ATTR_TIMEOUT => 7,
        ]]);
        $options = $config->getOptions();

        self::assertSame(PDO::CASE_LOWER, $options[PDO::ATTR_CASE]);
        self::assertSame(7, $options[PDO::ATTR_TIMEOUT], '用户配置必须覆盖默认超时且保留常量键');
        self::assertSame(PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);
    }

    public function testPersistentDefaultsOnForNonWlsRuntime(): void
    {
        self::assertFalse(Runtime::isWls(), 'CLI 单测不应处于 WLS 运行时');

        $options = $this->config()->getOptions();
        self::assertArrayHasKey(PDO::ATTR_PERSISTENT, $options);
        self::assertTrue($options[PDO::ATTR_PERSISTENT]);
    }

    public function testPersistentCanBeDisabledExplicitly(): void
    {
        $options = $this->config(['persistent' => false])->getOptions();

        self::assertArrayHasKey(PDO::ATTR_PERSISTENT, $options);
        self::assertFalse($options[PDO::ATTR_PERSISTENT]);
    }

    /** @param array<string, mixed> $overrides */
    private function config(array $overrides = []): ConfigProvider
    {
        return new ConfigProvider(\array_replace([
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'weline_unit',
            'username' => 'unit',
            'password' => 'unit',
            'charset' => 'utf8',
            'timeout' => 30,
        ], $overrides));
    }
}

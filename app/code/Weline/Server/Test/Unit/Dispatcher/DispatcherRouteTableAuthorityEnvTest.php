<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\Dispatcher;

/**
 * B-ii 阶段：环境变量灰度开关 WLS_ROUTE_TABLE_AS_AUTHORITY 的解析行为契约测试。
 *
 * 真值（开关启用）：1 / true / yes / on，大小写不敏感、容忍前后空格。
 * 其它值（含未设置、空、0、false、no、off、随机字符串）一律视为关闭，
 * 保证默认行为兼容 B-i（SET_WORKER_POOL 仍是业务路由权威）。
 */
final class DispatcherRouteTableAuthorityEnvTest extends TestCase
{
    private ?string $originalEnv = null;
    private bool $envWasSet = false;

    protected function setUp(): void
    {
        parent::setUp();
        // 捕获原始 env 值，确保 tearDown 能完整还原（包括「未设置」也能还原回未设置）
        $raw = \getenv('WLS_ROUTE_TABLE_AS_AUTHORITY');
        if ($raw === false) {
            $this->envWasSet = false;
            $this->originalEnv = null;
        } else {
            $this->envWasSet = true;
            $this->originalEnv = (string)$raw;
        }
    }

    protected function tearDown(): void
    {
        if ($this->envWasSet) {
            \putenv('WLS_ROUTE_TABLE_AS_AUTHORITY=' . (string)$this->originalEnv);
        } else {
            \putenv('WLS_ROUTE_TABLE_AS_AUTHORITY');
        }
        parent::tearDown();
    }

    public function testReturnsFalseWhenEnvNotSet(): void
    {
        \putenv('WLS_ROUTE_TABLE_AS_AUTHORITY');
        self::assertFalse(Dispatcher::resolveRouteTableAuthorityFromEnv());
    }

    /**
     * @dataProvider truthyEnvValuesProvider
     */
    public function testReturnsTrueForTruthyEnvValues(string $rawValue): void
    {
        \putenv('WLS_ROUTE_TABLE_AS_AUTHORITY=' . $rawValue);
        self::assertTrue(
            Dispatcher::resolveRouteTableAuthorityFromEnv(),
            "Expected truthy resolution for env value '{$rawValue}'"
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function truthyEnvValuesProvider(): array
    {
        return [
            'lower 1'        => ['1'],
            'lower true'     => ['true'],
            'upper TRUE'     => ['TRUE'],
            'mixed True'     => ['True'],
            'lower yes'      => ['yes'],
            'lower on'       => ['on'],
            'padded space'   => ['  1  '],
            'padded true'    => ["\ttrue\n"],
        ];
    }

    /**
     * @dataProvider falsyEnvValuesProvider
     */
    public function testReturnsFalseForFalsyEnvValues(string $rawValue): void
    {
        \putenv('WLS_ROUTE_TABLE_AS_AUTHORITY=' . $rawValue);
        self::assertFalse(
            Dispatcher::resolveRouteTableAuthorityFromEnv(),
            "Expected falsy resolution for env value '{$rawValue}'"
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function falsyEnvValuesProvider(): array
    {
        return [
            'empty string'  => [''],
            'literal 0'     => ['0'],
            'literal false' => ['false'],
            'literal no'    => ['no'],
            'literal off'   => ['off'],
            'random word'   => ['enabled-please'],
            'unicode noise' => ['是'],
        ];
    }
}

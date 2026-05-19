<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\Dispatcher;

/**
 * B-iii 阶段：环境变量灰度开关 WLS_ROUTE_TABLE_AS_AUTHORITY 的解析行为契约测试。
 *
 * **B-iii 起契约翻转**：默认（env 未设置 / 空 / 不识别）→ true，SET_ROUTE_TABLE 成为新的权威。
 * - 显式 truthy 值（1 / true / yes / on）→ true，启用 B-iii 权威路径；
 * - 显式 falsy 值（0 / false / no / off）→ false，应急回退到 B-i 行为；
 * - 其它字符串（如随机词）→ true，保守默认权威，避免误配置导致悄悄回退。
 *
 * 解析对大小写不敏感、容忍前后空格。
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

    public function testReturnsTrueWhenEnvNotSet(): void
    {
        \putenv('WLS_ROUTE_TABLE_AS_AUTHORITY');
        self::assertTrue(
            Dispatcher::resolveRouteTableAuthorityFromEnv(),
            'B-iii: env 未设置时应默认返回 true（SET_ROUTE_TABLE 成为默认权威）'
        );
    }

    public function testReturnsTrueForEmptyStringEnv(): void
    {
        \putenv('WLS_ROUTE_TABLE_AS_AUTHORITY=');
        self::assertTrue(
            Dispatcher::resolveRouteTableAuthorityFromEnv(),
            'B-iii: 空字符串等同于未设置，应默认 true'
        );
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
    public function testReturnsFalseForExplicitFalsyEnvValues(string $rawValue): void
    {
        \putenv('WLS_ROUTE_TABLE_AS_AUTHORITY=' . $rawValue);
        self::assertFalse(
            Dispatcher::resolveRouteTableAuthorityFromEnv(),
            "Expected falsy resolution (B-i emergency rollback) for env value '{$rawValue}'"
        );
    }

    /**
     * 仅显式 falsy 值会回退到 B-i 行为，避免误配置悄悄掉队。
     *
     * @return array<string, array{0: string}>
     */
    public static function falsyEnvValuesProvider(): array
    {
        return [
            'literal 0'         => ['0'],
            'literal false'     => ['false'],
            'literal no'        => ['no'],
            'literal off'       => ['off'],
            'upper FALSE'       => ['FALSE'],
            'padded off'        => ["  off\n"],
        ];
    }

    /**
     * @dataProvider unknownEnvValuesProvider
     */
    public function testReturnsTrueForUnknownEnvValues(string $rawValue): void
    {
        \putenv('WLS_ROUTE_TABLE_AS_AUTHORITY=' . $rawValue);
        self::assertTrue(
            Dispatcher::resolveRouteTableAuthorityFromEnv(),
            "B-iii: 不识别的 env 值（'{$rawValue}'）应保守返回 true（默认权威路径）"
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unknownEnvValuesProvider(): array
    {
        return [
            'random word'   => ['enabled-please'],
            'unicode noise' => ['是'],
            'numeric 2'     => ['2'],
            'numeric -1'    => ['-1'],
        ];
    }
}

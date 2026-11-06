<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Controller\Extra;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Service\FpcPolicyStateReducer;
use Weline\Framework\Controller\Extra\ExtraCollector;
use Weline\Framework\Controller\Extra\FpcPolicySnapshot;
use Weline\Framework\Extends\Module\Weline_Framework\Extra\Type\FpcExtraType;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * 接口 FPC 的两条新维度：
 * 1. **method** —— 同一 URL 上 GET 可缓存、POST 禁缓存必须互不污染
 *    （`get` / `post` 这类「方法名即动词」的声明，公开路径不含动词段，只按路径匹配必然撞车）。
 * 2. **rate_\*** —— 每 IP 窗口限流参数走 `@Extra`，且**不进** `policy_fingerprint`
 *    （否则改限流参数会让全站 FPC 一次性失效）。
 */
final class FpcPolicyRateAndMethodTest extends TestCase
{
    protected function setUp(): void
    {
        FpcPolicySnapshot::clearProcessCaches();
    }

    public function testNormalizeParsesRateKeysWithDefaults(): void
    {
        $defaults = (new FpcExtraType())->normalize(['type' => 'fpc']);
        self::assertFalse($defaults['rate_enabled']);
        self::assertSame(10, $defaults['rate_window']);
        self::assertSame(0, $defaults['rate_max_requests']);
        self::assertSame(0, $defaults['rate_block_duration']);

        $n = (new FpcExtraType())->normalize([
            'type' => 'fpc',
            'rate_enabled' => 'true',
            'rate_window' => '10',
            'rate_max_requests' => '5',
            'rate_block_duration' => '30',
        ]);
        self::assertTrue($n['rate_enabled']);
        self::assertSame(10, $n['rate_window']);
        self::assertSame(5, $n['rate_max_requests']);
        self::assertSame(30, $n['rate_block_duration']);

        // 'false' 字符串不得被判成真（与 T1 的布尔口径一致）。
        self::assertFalse((new FpcExtraType())->normalize(['type' => 'fpc', 'rate_enabled' => 'false'])['rate_enabled']);
    }

    public function testNormalizeThrowsWhenRateEnabledWithoutPositiveMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rate_max_requests must be > 0 when rate_enabled');
        (new FpcExtraType())->normalize(['type' => 'fpc', 'rate_enabled' => 'true', 'rate_max_requests' => '0']);
    }

    public function testNormalizeThrowsWhenRateEnabledWithoutMaxKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rate_max_requests must be > 0 when rate_enabled');
        (new FpcExtraType())->normalize(['type' => 'fpc', 'rate_enabled' => 'true']);
    }

    public function testNormalizeThrowsOnNonPositiveWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rate_window must be > 0');
        (new FpcExtraType())->normalize(['type' => 'fpc', 'rate_window' => '0']);
    }

    public function testParseExtraDocParsesRateKeys(): void
    {
        $ref = new \ReflectionClass(ExtraCollector::class);
        $collector = $ref->newInstanceWithoutConstructor();
        $parse = $ref->getMethod('parseExtraDoc');
        $parse->setAccessible(true);

        $get = fn (string $raw): array => $parse->invoke($collector, " * @Extra " . $raw . "\n */");

        self::assertSame(
            ['type' => 'fpc', 'rate_enabled' => true, 'rate_window' => 5, 'rate_max_requests' => 3, 'rate_block_duration' => 60],
            $get('type=fpc rate_enabled=true rate_window=5 rate_max_requests=3 rate_block_duration=60'),
        );
        self::assertSame(
            ['type' => 'fpc', 'rate_enabled' => false],
            $get('type=fpc rate_enabled=false'),
        );
    }

    /** GET 与 POST 在同一 URL 上互不污染；`$method===null` 保持保守（禁用胜出）。 */
    public function testResolveSeparatesMethodsOnTheSamePath(): void
    {
        $scope = ScopeIdentity::channel(0, 'default', 'main', 'web', 'normal');
        $snapshot = $this->snapshot();

        $get = FpcPolicySnapshot::resolve($snapshot, '/x', $scope, null, 'GET');
        self::assertSame('g', $get['declaration_id']);
        self::assertTrue($get['enabled']);

        $post = FpcPolicySnapshot::resolve($snapshot, '/x', $scope, null, 'POST');
        self::assertSame('p', $post['declaration_id']);
        self::assertFalse($post['enabled']);

        // 向后兼容：不给 method 时两条都命中，按 enabled 升序 → 禁用声明保守胜出。
        $legacy = FpcPolicySnapshot::resolve($snapshot, '/x', $scope);
        self::assertSame('p', $legacy['declaration_id']);
        self::assertFalse($legacy['enabled']);
    }

    /**
     * 带显式动作段的声明（postPhrase）同样是**动词绑定**的：它的路由键是 `<base>/phrase::POST`。
     * 若这里不做 method 过滤，同一控制器上的 `getPhrase` / `postPhrase` 会同时声称拥有 `/x/phrase`。
     */
    public function testExplicitActionDeclarationIsAlsoMethodBound(): void
    {
        $scope = ScopeIdentity::channel(0, 'default', 'main', 'web', 'normal');
        $snapshot = $this->snapshot();
        $snapshot['declarations']['a'] = [
            'declaration_id' => 'a', 'method' => 'postPhrase', 'path_pattern' => '/x/phrase',
            'attrs' => ['enabled' => true, 'ttl' => 300], 'namespaces' => [],
        ];
        FpcPolicySnapshot::clearProcessCaches();

        self::assertNull(FpcPolicySnapshot::resolve($snapshot, '/x/phrase', $scope, null, 'GET'));
        $post = FpcPolicySnapshot::resolve($snapshot, '/x/phrase', $scope, null, 'POST');
        self::assertSame('a', $post['declaration_id']);
        // 不给 method 时不丢声明（向后兼容：宁可保守拿不到政策，也不能假装没有声明）。
        self::assertSame('a', FpcPolicySnapshot::resolve($snapshot, '/x/phrase', $scope)['declaration_id']);
    }

    public function testFingerprintExcludesRateKeys(): void
    {
        $scope = ScopeIdentity::channel(0, 'default', 'main', 'web', 'normal');
        $snapshot = $this->snapshot();

        $before = FpcPolicySnapshot::resolve($snapshot, '/x', $scope, null, 'GET')['policy_fingerprint'];
        $snapshot['declarations']['g']['attrs']['rate_max_requests'] = 99;
        $snapshot['declarations']['g']['attrs']['rate_window'] = 1;
        FpcPolicySnapshot::clearProcessCaches();

        self::assertSame(
            $before,
            FpcPolicySnapshot::resolve($snapshot, '/x', $scope, null, 'GET')['policy_fingerprint'],
            '限流参数不得改动 policy_fingerprint',
        );
    }

    public function testPolicySurfacesRateKeys(): void
    {
        $scope = ScopeIdentity::channel(0, 'default', 'main', 'web', 'normal');
        $snapshot = $this->snapshot();
        $snapshot['declarations']['g']['attrs'] += [
            'rate_enabled' => true, 'rate_window' => 10, 'rate_max_requests' => 5, 'rate_block_duration' => 0,
        ];
        FpcPolicySnapshot::clearProcessCaches();

        $policy = FpcPolicySnapshot::resolve($snapshot, '/x', $scope, null, 'GET');
        self::assertTrue($policy['rate_enabled']);
        self::assertSame(10, $policy['rate_window']);
        self::assertSame(5, $policy['rate_max_requests']);
        self::assertSame(0, $policy['rate_block_duration']);

        // 缺省值必须补齐（T6 直接读这几个键，不得依赖 null 判断）。
        $plain = FpcPolicySnapshot::resolve($this->snapshot(), '/x', $scope, null, 'POST');
        self::assertFalse($plain['rate_enabled']);
        self::assertSame(10, $plain['rate_window']);
        self::assertSame(0, $plain['rate_max_requests']);
        self::assertSame(0, $plain['rate_block_duration']);
    }

    /**
     * 控制面 reducer 会**重建** attrs；不扩白名单的话 rate 键会被静默丢掉
     * （保存一次策略后限流配置凭空消失）。
     */
    public function testStateReducerKeepsRateKeys(): void
    {
        $row = FpcPolicyStateReducer::declaration([
            'module' => 'Weline_Test',
            'class' => '\\Weline\\Test\\Controller\\Frontend\\X',
            'method' => 'get',
            'path_pattern' => '/x',
            'attrs' => [
                'enabled' => true, 'ttl' => 300,
                'rate_enabled' => true, 'rate_window' => 10,
                'rate_max_requests' => 5, 'rate_block_duration' => 30,
            ],
        ]);

        self::assertTrue($row['attrs']['rate_enabled']);
        self::assertSame(10, $row['attrs']['rate_window']);
        self::assertSame(5, $row['attrs']['rate_max_requests']);
        self::assertSame(30, $row['attrs']['rate_block_duration']);
        self::assertSame('get', $row['method']);
    }

    private function snapshot(): array
    {
        return [
            'schema_version' => 'fpc-policy-snapshot.v1',
            'revision' => 'one',
            'source_version' => 1,
            'declarations' => [
                'g' => ['declaration_id' => 'g', 'method' => 'get', 'path_pattern' => '/x',
                    'attrs' => ['enabled' => true, 'ttl' => 300], 'namespaces' => []],
                'p' => ['declaration_id' => 'p', 'method' => 'post', 'path_pattern' => '/x',
                    'attrs' => ['enabled' => false, 'ttl' => 0], 'namespaces' => []],
            ],
            'overrides' => [],
            'scope_chains' => [],
        ];
    }
}

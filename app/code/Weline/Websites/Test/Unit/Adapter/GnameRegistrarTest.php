<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Adapter;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Adapter\GnameRegistrar;
use Weline\Websites\Api\DomainRegistrarInterface;

/**
 * GnameRegistrar 单元测试
 *
 * 覆盖：接口实现、签名算法、凭据标准化、返回结构。
 * 实际 API 调用测试需配置有效的 GName 凭据。
 */
class GnameRegistrarTest extends TestCase
{
    private GnameRegistrar $adapter;

    protected function setUp(): void
    {
        $this->adapter = new GnameRegistrar();
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(DomainRegistrarInterface::class, $this->adapter);
    }

    public function testGetRegistrarCode(): void
    {
        $this->assertSame('gname', $this->adapter->getRegistrarCode());
    }

    public function testGetRegistrarName(): void
    {
        $this->assertSame('GName', $this->adapter->getRegistrarName());
    }

    public function testGetVersion(): void
    {
        $this->assertNotEmpty($this->adapter->getVersion());
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $this->adapter->getVersion());
    }

    public function testGetConfigFieldsStructure(): void
    {
        $fields = $this->adapter->getConfigFields();
        $this->assertIsArray($fields);
        $this->assertNotEmpty($fields);

        $names = \array_column($fields, 'name');
        $this->assertContains('appid', $names);
        $this->assertContains('appkey', $names);

        foreach ($fields as $field) {
            $this->assertArrayHasKey('name', $field);
            $this->assertArrayHasKey('label', $field);
            $this->assertArrayHasKey('type', $field);
            $this->assertArrayHasKey('required', $field);
        }
    }

    public function testSignatureGeneration(): void
    {
        $reflection = new \ReflectionClass($this->adapter);
        $method = $reflection->getMethod('generateSignature');
        $method->setAccessible(true);

        $params = [
            'appid' => 'TESTAPPID',
            'gntime' => '1618998008',
            'ym' => 'example.com',
        ];
        $appKey = 'TESTAPPKEY';

        $signature = $method->invoke($this->adapter, $params, $appKey);

        $this->assertIsString($signature);
        $this->assertSame(32, \strlen($signature));
        $this->assertSame(\strtoupper($signature), $signature);

        \ksort($params);
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = $k . '=' . \urlencode((string) $v);
        }
        $expected = \strtoupper(\md5(\implode('&', $parts) . $appKey));
        $this->assertSame($expected, $signature);
    }

    public function testSignatureWithUrlEncoding(): void
    {
        $reflection = new \ReflectionClass($this->adapter);
        $method = $reflection->getMethod('generateSignature');
        $method->setAccessible(true);

        $params = [
            'appid' => 'APPID',
            'gntime' => '1234567890',
            'ym' => 'example.com',
            'url' => 'https://www.example.com',
        ];
        $appKey = 'APPKEY';

        $signature = $method->invoke($this->adapter, $params, $appKey);

        \ksort($params);
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = $k . '=' . \urlencode((string) $v);
        }
        $expected = \strtoupper(\md5(\implode('&', $parts) . $appKey));
        $this->assertSame($expected, $signature);
    }

    public function testCredentialNormalizationFromAccountModel(): void
    {
        $reflection = new \ReflectionClass($this->adapter);
        $method = $reflection->getMethod('normalizeCredentials');
        $method->setAccessible(true);

        $accountCredentials = [
            'api_key' => 'MY_APP_ID',
            'api_secret' => 'MY_APP_KEY',
            'region' => '',
            'extra' => [
                'api_host' => 'custom.gname.com',
                'default_template_id' => '12345',
            ],
        ];

        $normalized = $method->invoke($this->adapter, $accountCredentials);

        $this->assertSame('MY_APP_ID', $normalized['appid']);
        $this->assertSame('MY_APP_KEY', $normalized['appkey']);
        $this->assertSame('custom.gname.com', $normalized['api_host']);
        $this->assertSame('12345', $normalized['default_template_id']);
    }

    public function testCredentialNormalizationPassthroughDirect(): void
    {
        $reflection = new \ReflectionClass($this->adapter);
        $method = $reflection->getMethod('normalizeCredentials');
        $method->setAccessible(true);

        $directCredentials = [
            'appid' => 'DIRECT_ID',
            'appkey' => 'DIRECT_KEY',
            'api_host' => 'www.gname.com',
        ];

        $normalized = $method->invoke($this->adapter, $directCredentials);

        $this->assertSame('DIRECT_ID', $normalized['appid']);
        $this->assertSame('DIRECT_KEY', $normalized['appkey']);
    }

    public function testTestConnectionThrowsOnEmptyCredentials(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->adapter->testConnection(['appid' => '', 'appkey' => '']);
    }

    public function testTestConnectionThrowsOnMissingAppId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->adapter->testConnection(['api_key' => '', 'api_secret' => 'test', 'region' => '', 'extra' => []]);
    }

    public function testExtractTld(): void
    {
        $reflection = new \ReflectionClass($this->adapter);
        $method = $reflection->getMethod('extractTld');
        $method->setAccessible(true);

        $this->assertSame('com', $method->invoke($this->adapter, 'example.com'));
        $this->assertSame('co.uk', $method->invoke($this->adapter, 'example.co.uk'));
        $this->assertSame('net', $method->invoke($this->adapter, 'test.net'));
    }

    public function testNormalizeStatus(): void
    {
        $reflection = new \ReflectionClass($this->adapter);
        $method = $reflection->getMethod('normalizeStatus');
        $method->setAccessible(true);

        $this->assertSame('suspended', $method->invoke($this->adapter, '1'));
        $this->assertSame('active', $method->invoke($this->adapter, '0'));
        $this->assertSame('expired', $method->invoke($this->adapter, '-1'));
        $this->assertSame('active', $method->invoke($this->adapter, 'active'));
        $this->assertSame('suspended', $method->invoke($this->adapter, 'clientHold'));
        $this->assertSame('customstatus', $method->invoke($this->adapter, 'customstatus'));
    }

    public function testGetDescriptionNotEmpty(): void
    {
        $desc = $this->adapter->getDescription();
        $this->assertIsString($desc);
        $this->assertNotEmpty($desc);
    }

    public function testShouldConfirmPurchasedDomainForAmbiguousAlreadyRegisteredResponse(): void
    {
        $reflection = new \ReflectionClass($this->adapter);
        $method = $reflection->getMethod('shouldConfirmPurchasedDomain');
        $method->setAccessible(true);

        $result = $method->invoke($this->adapter, -1, [
            'msg' => '对不起，域名已被注册了',
        ]);

        $this->assertTrue($result);
    }

    public function testShouldNotConfirmPurchasedDomainForUnrelatedErrorCode(): void
    {
        $reflection = new \ReflectionClass($this->adapter);
        $method = $reflection->getMethod('shouldConfirmPurchasedDomain');
        $method->setAccessible(true);

        $result = $method->invoke($this->adapter, -1002, [
            'msg' => '权限错误',
        ]);

        $this->assertFalse($result);
    }

    public function testDomainExistsInListMatchesCaseInsensitive(): void
    {
        $reflection = new \ReflectionClass($this->adapter);
        $method = $reflection->getMethod('domainExistsInList');
        $method->setAccessible(true);

        $result = $method->invoke($this->adapter, 'Example.COM', [
            ['domain' => 'example.com'],
            ['domain' => 'demo.net'],
        ]);

        $this->assertTrue($result);
    }

    public function testDomainExistsInListReturnsFalseWhenDomainMissing(): void
    {
        $reflection = new \ReflectionClass($this->adapter);
        $method = $reflection->getMethod('domainExistsInList');
        $method->setAccessible(true);

        $result = $method->invoke($this->adapter, 'missing.com', [
            ['domain' => 'example.com'],
        ]);

        $this->assertFalse($result);
    }
}

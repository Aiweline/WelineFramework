<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\PlatformAppStore\Service\LicenseService;

/**
 * 许可证服务单元测试
 */
class LicenseServiceTest extends TestCase
{
    private LicenseService $licenseService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->licenseService = new LicenseService();
    }

    /**
     * 测试生成许可证密钥
     */
    public function testGenerateLicenseKey(): void
    {
        $key = $this->licenseService->generateLicenseKey();

        // 验证格式：WL- + 32位十六进制字符
        $this->assertStringStartsWith('WL-', $key);
        $this->assertEquals(35, strlen($key));
        $this->assertMatchesRegularExpression('/^WL-[A-F0-9]{32}$/', $key);
    }

    /**
     * 测试生成多个许可证密钥唯一性
     */
    public function testGenerateLicenseKeyUniqueness(): void
    {
        $keys = [];
        for ($i = 0; $i < 100; $i++) {
            $keys[] = $this->licenseService->generateLicenseKey();
        }

        $uniqueKeys = array_unique($keys);
        $this->assertCount(100, $uniqueKeys, 'Generated license keys should be unique');
    }

    /**
     * 测试域名标准化
     */
    public function testNormalizeDomain(): void
    {
        // 测试移除协议
        $this->assertEquals('example.com', $this->licenseService->normalizeDomain('https://example.com'));
        $this->assertEquals('example.com', $this->licenseService->normalizeDomain('http://example.com'));

        // 测试移除端口
        $this->assertEquals('example.com', $this->licenseService->normalizeDomain('example.com:8080'));

        // 测试移除路径
        $this->assertEquals('example.com', $this->licenseService->normalizeDomain('example.com/path'));

        // 测试转小写
        $this->assertEquals('example.com', $this->licenseService->normalizeDomain('EXAMPLE.COM'));
    }

    /**
     * 测试域名匹配（完全匹配）
     */
    public function testDomainsMatchExact(): void
    {
        $this->assertTrue($this->licenseService->domainsMatch('example.com', 'example.com'));
        $this->assertTrue($this->licenseService->domainsMatch('sub.example.com', 'sub.example.com'));
    }

    /**
     * 测试域名匹配（www 匹配）
     */
    public function testDomainsMatchWww(): void
    {
        // www 匹配非 www
        $this->assertTrue($this->licenseService->domainsMatch('www.example.com', 'example.com'));
        $this->assertTrue($this->licenseService->domainsMatch('example.com', 'www.example.com'));
    }

    /**
     * 测试域名不匹配
     */
    public function testDomainsNotMatch(): void
    {
        $this->assertFalse($this->licenseService->domainsMatch('example.com', 'other.com'));
        $this->assertFalse($this->licenseService->domainsMatch('sub.example.com', 'example.com'));
        $this->assertFalse($this->licenseService->domainsMatch('example.com', 'sub.example.com'));
    }

    /**
     * 测试计算过期日期
     */
    public function testCalculateExpirationDate(): void
    {
        // 测试月度订阅
        $monthly = $this->licenseService->calculateExpirationDate('monthly');
        $this->assertNotNull($monthly);
        $expectedMonthly = date('Y-m-d', strtotime('+30 days'));
        $this->assertStringContainsString($expectedMonthly, $monthly);

        // 测试年度订阅
        $yearly = $this->licenseService->calculateExpirationDate('yearly');
        $this->assertNotNull($yearly);
        $expectedYearly = date('Y-m-d', strtotime('+365 days'));
        $this->assertStringContainsString($expectedYearly, $yearly);
    }
}

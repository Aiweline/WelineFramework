<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once \dirname(__DIR__, 7) . '/app/bootstrap_phpunit.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;

/**
 * 回归（T-MW-2）：托管 Nginx 启动所需的证书必须**作为参数**进入 startMasterInBackground()。
 *
 * 原来该方法体内直接读 `$activeCertificate`，而该变量定义在 execute() 里、且只在
 * SSL/证书分支内初始化 → 跨方法作用域读到**未定义变量**：E_WARNING + null 一路流入
 * maybeStartManagedNginxAfterReady()（函数内 5 处使用，含 is_array() 判定），
 * 使托管 Nginx 的启动/协议门禁判定失真。
 */
final class StartMasterBackgroundCertificateArgTest extends TestCase
{
    public function testStartMasterInBackgroundAcceptsCertificateExplicitly(): void
    {
        $method = new \ReflectionMethod(Start::class, 'startMasterInBackground');
        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }

        self::assertArrayHasKey(
            'activeCertificate',
            $parameters,
            '证书必须显式传参，不能依赖调用方作用域（跨方法读未定义变量即本回归的缺陷）',
        );
        self::assertTrue(
            $parameters['activeCertificate']->allowsNull(),
            '未进入 SSL/证书分支时证书本就为 null，参数必须允许 null',
        );
    }

    public function testCallSiteNormalizesCertificateBeforePassing(): void
    {
        $source = (string)\file_get_contents(
            (string)(new \ReflectionClass(Start::class))->getFileName(),
        );

        self::assertStringContainsString(
            '$activeCertificate ?? null,',
            $source,
            '证书变量只在 SSL/证书分支内初始化，调用点必须归一为 null 再传入',
        );
    }
}

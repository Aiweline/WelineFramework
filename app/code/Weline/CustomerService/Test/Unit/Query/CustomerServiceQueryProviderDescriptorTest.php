<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\CustomerService\Extends\Module\Weline_Framework\Query\CustomerServiceQueryProvider;

final class CustomerServiceQueryProviderDescriptorTest extends TestCase
{
    public function testAdminRequestIsExposedToBackendWorkerApi(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CustomerServiceQueryProvider.php'
        );

        self::assertMatchesRegularExpression(
            "/'name'\\s*=>\\s*'adminRequest'[\\s\\S]*?'frontend'\\s*=>\\s*true[\\s\\S]*?'auth'\\s*=>\\s*'backend'/",
            $source,
        );
        self::assertStringContainsString("'backend_acl' => ['kind' => 'self']", $source);
        self::assertStringContainsString('AdminControllerBridge::invoke', $source);
        self::assertStringNotContainsString('ObjectManager::getInstance($class)', $source);
        // kebab action 必须去空格，否则 bridge 候选变成 "getAgent Statistics"
        self::assertStringContainsString(
            "str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', \$actionRaw))",
            $source
        );
    }

    public function testTransferSessionsKebabMapsToPostTransferSessions(): void
    {
        $actionRaw = 'transfer-sessions';
        $actionSeg = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $actionRaw)));
        self::assertSame('TransferSessions', $actionSeg);
        self::assertSame('postTransferSessions', 'post' . $actionSeg);
        self::assertTrue(method_exists(
            \Weline\CustomerService\Controller\Backend\Agent::class,
            'postTransferSessions'
        ));
        self::assertStringNotContainsString(' ', 'post' . $actionSeg);
    }

    public function testSendVerificationCaptchaResponseAllowsLongTokens(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CustomerServiceQueryProvider.php'
        );

        self::assertMatchesRegularExpression(
            "/'name'\\s*=>\\s*'sendVerification'[\\s\\S]*?'captcha_response'\\s*=>\\s*\\[[^\\]]*max_length'\\s*=>\\s*8192/",
            $source,
        );
    }
}

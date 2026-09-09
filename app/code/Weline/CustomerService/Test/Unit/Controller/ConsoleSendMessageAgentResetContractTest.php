<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class ConsoleSendMessageAgentResetContractTest extends TestCase
{
    public function testPostSendMessageResetsAgentQuery(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/Console.php');
        $js = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/backend-console.js');

        $this->assertMatchesRegularExpression(
            '/function postSendMessage\(\)[\s\S]*?\$agent->reset\(\)\s*->where\(ServiceAgent::schema_fields_USER_ID/',
            $src
        );
        $this->assertStringContainsString('keepBusinessResult', $js);
        $this->assertStringContainsString('csExtractErrorMessage', $js);
        $this->assertStringContainsString('csExtractErrorMessage(error, __(\'发送失败，请稍后重试\'))', $js);
    }
}

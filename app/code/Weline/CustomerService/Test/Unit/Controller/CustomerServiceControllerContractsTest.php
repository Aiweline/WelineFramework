<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCustomerServiceControllerContractsPass().
 */
final class CustomerServiceControllerContractsTest extends TestCase
{
    public function testAllCustomerServiceControllerContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ConsoleSendMessageAgentReset::testPostSendMessageResetsAgentQuery' => function () {
            $this->assert_ConsoleSendMessageAgentReset_testPostSendMessageResetsAgentQuery();
        },
        ];
    }

private function assert_ConsoleSendMessageAgentReset_testPostSendMessageResetsAgentQuery(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/Controller/Backend/Console.php');
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/js/backend-console.js');
    $this->assertMatchesRegularExpression('/function postSendMessage\(\)[\s\S]*?\$agent->reset\(\)\s*->where\(ServiceAgent::schema_fields_USER_ID/', $src);
    $this->assertStringContainsString('keepBusinessResult', $js);
    $this->assertStringContainsString('csExtractErrorMessage', $js);
    $this->assertStringContainsString('csExtractErrorMessage(error, __(\'发送失败，请稍后重试\'))', $js);
}
}

<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\Adapter;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Api\Notification\ChannelAdapterInterface;
use Weline\Backend\Service\ChannelAdapterCollector;
use Weline\Framework\Contract\InterfaceSourceContractProbe;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllBackendAdapterContractsPass().
 */
final class BackendAdapterContractsTest extends TestCase
{
    public function testAllBackendAdapterContractsPass(): void
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
            'ChannelAdapterInterface::testAllRegisteredAdaptersSatisfyInterfaceSourceContract' => function () {
            $this->assert_ChannelAdapterInterface_testAllRegisteredAdaptersSatisfyInterfaceSourceContract();
        },
            'ChannelAdapterInterface::testCollectorValidateRegisteredContractsReportsOk' => function () {
            $this->assert_ChannelAdapterInterface_testCollectorValidateRegisteredContractsReportsOk();
        },
            'EmailAdapterMailTemplate::testSendKeepsChannelAndUsesVars' => function () {
            $this->assert_EmailAdapterMailTemplate_testSendKeepsChannelAndUsesVars();
        },
        ];
    }

private function assert_ChannelAdapterInterface_testAllRegisteredAdaptersSatisfyInterfaceSourceContract(): void
{
    $extendsFile = '/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/extends.php';
    self::assertFileExists($extendsFile);
    $config = include $extendsFile;
    $classes = $config[ChannelAdapterInterface::class] ?? [];
    self::assertIsArray($classes);
    self::assertNotEmpty($classes);
    foreach ($classes as $class) {
        self::assertIsString($class);
        $probe = InterfaceSourceContractProbe::check(ChannelAdapterInterface::class, $class);
        self::assertTrue($probe['ok'], $class . ' missing=[' . \implode(', ', $probe['missing']) . '] note=' . $probe['note']);
    }
}

private function assert_ChannelAdapterInterface_testCollectorValidateRegisteredContractsReportsOk(): void
{
    ChannelAdapterCollector::resetCache();
    $collector = new ChannelAdapterCollector();
    $rows = $collector->validateRegisteredContracts();
    self::assertNotEmpty($rows);
    foreach ($rows as $row) {
        self::assertTrue($row['ok'], ($row['class'] ?? '') . ' missing=[' . \implode(', ', $row['missing'] ?? []) . ']');
    }
}

private function assert_EmailAdapterMailTemplate_testSendKeepsChannelAndUsesVars(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/Adapter/Notification/EmailAdapter.php');
    self::assertStringContainsString("'channel' => \$channel", $src);
    self::assertStringContainsString("'vars'", $src);
    self::assertStringContainsString("'title'", $src);
    self::assertStringContainsString("'content'", $src);
    self::assertStringContainsString("'type_label'", $src);
    self::assertStringContainsString("'topic_code'", $src);
    self::assertStringContainsString('sender_code', $src);
    self::assertStringNotContainsString("unset(\$params['channel'])", $src);
    self::assertStringNotContainsString("unset(\$params[\"channel\"])", $src);
    self::assertDoesNotMatchRegularExpression("/'subject'\\s*=>\\s*\\\$/", $src);
    $provider = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/Extends/MailChannelProvider.php');
    self::assertStringContainsString("'default_templates'", $provider);
    self::assertStringContainsString('getEnabledTopics', $provider);
    self::assertStringContainsString('view/email/notification/', $provider);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/view/email/notification/zh_Hans_CN.html');
}
}

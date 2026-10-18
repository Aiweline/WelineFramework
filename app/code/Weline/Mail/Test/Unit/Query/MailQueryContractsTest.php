<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

/**
 * Mail QueryProvider 符号契约（只锁正确性；不冒充交付）。
 */
final class MailQueryContractsTest extends TestCase
{
    public function testAllMailQueryContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ': ' . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'SmtpTlsStatusBatch::testExposesBatchOpAndDescriptor' => function () {
                $this->assert_SmtpTlsStatusBatch_testExposesBatchOpAndDescriptor();
            },
            'SmtpTlsStatusBatch::testBatchDedupMemoAndShapeSymbols' => function () {
                $this->assert_SmtpTlsStatusBatch_testBatchDedupMemoAndShapeSymbols();
            },
            'MailboxAccountsAdminOps::testExposesListUpdateDeleteBatchOps' => function () {
                $this->assert_MailboxAccountsAdminOps_testExposesListUpdateDeleteBatchOps();
            },
        ];
    }

    private function mailQueryProviderSource(): string
    {
        $path = BP . '/app/code/Weline/Mail/extends/module/Weline_Framework/Query/MailQueryProvider.php';
        $src = (string) \file_get_contents($path);
        self::assertNotSame('', $src, 'MailQueryProvider source missing');

        return $src;
    }

    private function assert_SmtpTlsStatusBatch_testExposesBatchOpAndDescriptor(): void
    {
        $src = $this->mailQueryProviderSource();
        self::assertStringContainsString(
            "'getSmtpTlsStatusBatch' => \$this->getSmtpTlsStatusBatch(\$params)",
            $src
        );
        self::assertStringContainsString('function getSmtpTlsStatusBatch', $src);
        $descPos = \strpos($src, "'name' => 'getSmtpTlsStatusBatch'");
        self::assertNotFalse($descPos);
        $slice = \substr($src, (int) $descPos, 500);
        self::assertStringContainsString("'name' => 'hostnames'", $slice);
        self::assertStringContainsString("'type' => 'array'", $slice);
        self::assertStringContainsString(
            "'getSmtpTlsStatus' => \$this->getSmtpTlsStatus(\$params)",
            $src
        );
        self::assertStringContainsString("'name' => 'getSmtpTlsStatus'", $src);
    }

    private function assert_SmtpTlsStatusBatch_testBatchDedupMemoAndShapeSymbols(): void
    {
        $src = $this->mailQueryProviderSource();
        self::assertStringContainsString('smtpTlsStatusMemo', $src);
        self::assertStringContainsString("'by_hostname'", $src);
        self::assertStringContainsString("'items'", $src);
        self::assertStringContainsString('isset($seen[$hostname])', $src);
        self::assertStringContainsString(
            "getSmtpTlsStatus(['hostname' => \$hostname])",
            $src
        );
        self::assertStringContainsString('tlsSync()->getStatus($hostname)', $src);
    }

    private function assert_MailboxAccountsAdminOps_testExposesListUpdateDeleteBatchOps(): void
    {
        $src = $this->mailQueryProviderSource();
        foreach ([
            'listMailboxAccounts',
            'updateMailboxAccount',
            'setMailboxAccountStatus',
            'deleteMailboxAccount',
            'batchSetMailboxAccountStatus',
            'batchDeleteMailboxAccounts',
            'createMailboxAccount',
        ] as $op) {
            self::assertStringContainsString("'" . $op . "'", $src);
            self::assertStringContainsString("'name' => '" . $op . "'", $src);
        }
        self::assertStringContainsString('listAccounts($params)', $src);
        self::assertStringContainsString('updateAccount($params)', $src);
        self::assertStringContainsString('batchDelete(', $src);
        self::assertStringContainsString('Weline_Mail::mail_accounts_manage', $src);
        self::assertStringContainsString('isReservedSystemLocalPart', $src);
        self::assertStringContainsString('系统账号', $src);

        $model = (string) file_get_contents(BP . '/app/code/Weline/Mail/Model/MailAccount.php');
        self::assertStringContainsString('schema_fields_IS_SYSTEM', $model);
        self::assertStringContainsString('function isSystemAccount', $model);
        $mgmt = (string) file_get_contents(BP . '/app/code/Weline/Mail/Service/MailAccountManagementService.php');
        self::assertStringContainsString('assertSystemMutable', $mgmt);
        self::assertStringContainsString("'is_system'", $mgmt);
    }
}

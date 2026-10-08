<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Mail\Service\DnsRecordAdvisor;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class DnsRecordAdvisorContractTest extends TestCase
{
    public function testExpectedRecordsCoverMailChecklistCodes(): void
    {
        $advisor = new DnsRecordAdvisor();
        $rows = $advisor->expectedRecords('example.com', 'mail.example.com', 'default');
        $codes = array_column($rows, 'code');
        self::assertSame(['a', 'mx', 'spf', 'dkim', 'dmarc', 'smtp_cname'], $codes);
        self::assertFalse((bool)($rows[5]['required'] ?? true));
    }

    public function testAdvisorSourceValidatesTxtContentNotAnyTxt(): void
    {
        $src = (string)file_get_contents(BP . 'app/code/Weline/Mail/Service/DnsRecordAdvisor.php');
        self::assertStringContainsString("probeTxtContains(\$host, 'v=spf1')", $src);
        self::assertStringContainsString("probeTxtContains(\$host, 'v=DMARC1')", $src);
        self::assertStringContainsString("probeTxtContains(\$host, 'p=')", $src);
        self::assertStringContainsString('dig +short', $src);
        self::assertStringContainsString('@1.1.1.1', $src);
        self::assertStringNotContainsString('return is_array($records) && !empty($records);', $src);
    }
}

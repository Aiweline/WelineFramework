<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\View;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class EnterpriseDkimAutoNoManualFieldContractTest extends TestCase
{
    public function testEnterpriseHidesManualDkimPasteAndDocumentsAutoGenerate(): void
    {
        $src = (string)file_get_contents(
            BP . 'app/code/Weline/Mail/view/templates/Backend/Index/enterprise.phtml'
        );
        self::assertStringNotContainsString('DKIM 公钥（只填公钥正文）', $src);
        self::assertStringContainsString('mail-dkim-auto-note', $src);
        self::assertStringContainsString('本机自动生成', $src);
        self::assertStringContainsString("name=\"dkim_public_key\" value=\"\"", $src);

        $index = (string)file_get_contents(
            BP . 'app/code/Weline/Mail/Controller/Backend/Index.php'
        );
        self::assertStringContainsString('MailDkimLocalEnsureService', $index);
    }
}

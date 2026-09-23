<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Smtp\Service\MailShellRegionStore;

final class MailShellRegionStoreContractTest extends TestCase
{
    public function testMergeRegionHtmlReplacesHeaderAndFooterRows(): void
    {
        $shell = '<table><tr><td data-weline-mail-region="header">OLD-H</td></tr>'
            . '<tr><td data-weline-mail-region="body">{{MAIL_BODY}}</td></tr>'
            . '<tr><td data-weline-mail-region="footer">OLD-F1</td></tr>'
            . '<tr><td data-weline-mail-region="footer">OLD-F2</td></tr></table>';
        $out = MailShellRegionStore::mergeRegionHtml($shell, [
            'header' => '<strong>NEW-H</strong>',
            'footer' => ['<p>F1</p>', '<p>F2</p>'],
        ]);
        self::assertStringContainsString('<strong>NEW-H</strong>', $out);
        self::assertStringContainsString('<p>F1</p>', $out);
        self::assertStringContainsString('<p>F2</p>', $out);
        self::assertStringNotContainsString('OLD-H', $out);
        self::assertStringNotContainsString('OLD-F1', $out);
    }

    public function testMergeRegionHtmlKeepsNestedHeaderTableIntact(): void
    {
        $shell = '<table width="600"><tr>'
            . '<td bgcolor="#16181a" data-weline-mail-region="header">'
            . '<table width="100%"><tr>'
            . '<td align="left">LOGO-OLD</td>'
            . '<td align="right">TEXT-OLD</td>'
            . '</tr></table>'
            . '</td></tr></table>';
        $newInner = '<table width="100%"><tr>'
            . '<td align="left">LOGO-NEW</td>'
            . '<td align="right">TEXT-NEW</td>'
            . '</tr></table>';
        $out = MailShellRegionStore::mergeRegionHtml($shell, ['header' => $newInner]);

        self::assertStringContainsString('LOGO-NEW', $out);
        self::assertStringContainsString('TEXT-NEW', $out);
        self::assertStringNotContainsString('LOGO-OLD', $out);
        self::assertStringNotContainsString('TEXT-OLD', $out);
        // 壳行仅 1 个区域 TD + 内表 2 列；开闭平衡，无残留旧右栏挤成兄弟格
        self::assertSame(3, preg_match_all('/<td\b/i', $out));
        self::assertSame(3, preg_match_all('/<\/td>/i', $out));
        self::assertSame(1, substr_count(strtolower($out), 'data-weline-mail-region="header"'));
        self::assertSame(
            '<table width="600"><tr><td bgcolor="#16181a" data-weline-mail-region="header">'
            . $newInner
            . '</td></tr></table>',
            $out
        );
    }

    public function testMergeRegionHtmlJoinsLegacyFooterRowsIntoSingleSlot(): void
    {
        $shell = '<table><tr><td data-weline-mail-region="footer">OLD</td></tr></table>';
        $out = MailShellRegionStore::mergeRegionHtml($shell, [
            'footer' => ['<p>HELP</p>', '<p>DISCLAIMER</p>'],
        ]);
        self::assertSame(1, substr_count(strtolower($out), 'data-weline-mail-region="footer"'));
        self::assertStringContainsString('<p>HELP</p>', $out);
        self::assertStringContainsString('<p>DISCLAIMER</p>', $out);
        self::assertStringNotContainsString('OLD', $out);
    }

    public function testClearMethodPersistsEmptyRegionsViaSave(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/MailShellRegionStore.php');
        self::assertStringContainsString('function clear(string $storageScope)', $src);
        self::assertMatchesRegularExpression(
            '/function clear\(string \$storageScope\): void\s*\{[^}]*\$this->save\(\$storageScope,\s*\'\',\s*\[\]\)/s',
            $src
        );
    }
}

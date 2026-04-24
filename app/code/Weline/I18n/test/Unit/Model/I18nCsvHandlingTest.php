<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Model;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\I18n\Model\I18n;

class I18nCsvHandlingTest extends TestCore
{
    private I18n $i18n;

    protected function setUp(): void
    {
        parent::setUp();
        $this->i18n = ObjectManager::getInstance(I18n::class);
    }

    public function testBomOnlyCsvRowIsTreatedAsEmpty(): void
    {
        $normalizeRow = new \ReflectionMethod($this->i18n, 'normalizeCsvRow');
        $normalizeRow->setAccessible(true);
        $normalizedRow = $normalizeRow->invoke($this->i18n, ["\xEF\xBB\xBF"], 1);

        $isEmptyRow = new \ReflectionMethod($this->i18n, 'isEffectivelyEmptyCsvRow');
        $isEmptyRow->setAccessible(true);

        self::assertSame('', $normalizedRow[0]);
        self::assertTrue($isEmptyRow->invoke($this->i18n, $normalizedRow));
    }

    public function testEmptyModuleCsvIsWrittenWithoutBomPlaceholder(): void
    {
        $writeCsv = new \ReflectionMethod($this->i18n, 'writeModuleLanguageCsvFile');
        $writeCsv->setAccessible(true);

        $tempFile = tempnam(sys_get_temp_dir(), 'i18n-empty-');
        self::assertNotFalse($tempFile);

        try {
            $writeCsv->invoke($this->i18n, $tempFile, []);
            self::assertFileExists($tempFile);
            self::assertSame(0, filesize($tempFile));
        } finally {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}

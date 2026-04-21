<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;

final class StopCommandSourceEncodingTest extends TestCase
{
    public function testStopCommandSourceDoesNotContainKnownMojibakeMarkers(): void
    {
        $path = \dirname(__DIR__, 3) . '/Console/Server/Stop.php';
        $contents = (string) \file_get_contents($path);

        self::assertNotSame('', $contents, 'Stop command source should be readable.');
        self::assertStringNotContainsString(
            "\xEF\xBF\xBD",
            $contents,
            'Unexpected UTF-8 replacement character found in Stop.php.'
        );

        foreach ($this->knownMojibakeMarkers() as $marker) {
            self::assertStringNotContainsString(
                $marker,
                $contents,
                'Unexpected mojibake marker found in Stop.php: ' . $marker
            );
        }
    }

    /**
     * @return list<string>
     */
    private function knownMojibakeMarkers(): array
    {
        return [
            'ֹͣ',
            '锟',
            'ï¿½',
            '�T',
            '�U',
            '�d',
            '�^',
            '��ӭ��',
            ' ?? ?',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class DatabaseFreeTranslatorTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }

        parent::tearDown();
    }

    public function testNonNativeLocaleFallsBackToEnglishBeforeChinese(): void
    {
        $root = $this->createFixture('pt_BR', [
            'en_US' => 'English lock message',
            'zh_Hans_CN' => '中文锁提示',
        ]);

        self::assertSame('English lock message', $this->translate($root));
    }

    public function testIdentityTranslationDoesNotStopTheFallbackChain(): void
    {
        $root = $this->createFixture('ar_SA', [
            'ar_SA' => 'Lock message',
            'en_US' => 'English lock message',
            'zh_Hans_CN' => '中文锁提示',
        ]);

        self::assertSame('English lock message', $this->translate($root));
    }

    public function testChineseLocaleStillUsesChineseTranslation(): void
    {
        $root = $this->createFixture('zh-Hans-CN', [
            'en_US' => 'English lock message',
            'zh_Hans_CN' => '中文锁提示',
        ]);

        self::assertSame('中文锁提示', $this->translate($root));
    }

    /** @param array<string, string> $translations */
    private function createFixture(?string $locale, array $translations): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'weline-database-free-translator-' . bin2hex(random_bytes(8));
        $this->temporaryDirectories[] = $root;

        self::assertTrue(mkdir($root . '/app/etc', 0777, true));
        self::assertTrue(mkdir($root . '/app/code/Vendor/Module/i18n', 0777, true));

        $configuredLocale = $locale === null
            ? '[]'
            : "['lang' => " . var_export($locale, true) . ']';
        file_put_contents(
            $root . '/app/etc/env.php',
            "<?php\n\nreturn ['system' => {$configuredLocale}];\n",
        );

        foreach ($translations as $translationLocale => $translation) {
            $handle = fopen(
                $root . '/app/code/Vendor/Module/i18n/' . $translationLocale . '.csv',
                'wb',
            );
            self::assertIsResource($handle);
            fputcsv($handle, ['Lock message', $translation], ',', '"', '');
            fclose($handle);
        }

        file_put_contents(
            $root . '/translate.php',
            <<<'PHP'
<?php

declare(strict_types=1);

define('BP', __DIR__ . DIRECTORY_SEPARATOR);
require $argv[1];

fwrite(
    STDOUT,
    \Weline\Framework\Phrase\DatabaseFreeTranslator::translate('Lock message', 'Vendor_Module'),
);
PHP,
        );

        return $root;
    }

    private function translate(string $root): string
    {
        $source = dirname(__DIR__, 3) . '/Phrase/DatabaseFreeTranslator.php';
        $process = proc_open(
            [PHP_BINARY, $root . '/translate.php', $source],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $stderr);

        return (string)$stdout;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}

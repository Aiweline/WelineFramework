<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Phrase\DictionaryCollectBusyException;
use Weline\Framework\Phrase\DictionaryCollectGate;

final class DictionaryCollectGateContractTest extends TestCase
{
    private string $lockDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockDir = sys_get_temp_dir() . '/weline-dict-collect-gate-' . getmypid() . '-' . bin2hex(random_bytes(4));
        self::assertTrue(@mkdir($this->lockDir, 0755, true) || is_dir($this->lockDir));
        putenv('WELINE_TEST_DICTIONARY_COLLECT_LOCK_DIR=' . $this->lockDir);
        $_ENV['WELINE_TEST_DICTIONARY_COLLECT_LOCK_DIR'] = $this->lockDir;
        DictionaryCollectGate::resetProcessStateForTests();
    }

    protected function tearDown(): void
    {
        DictionaryCollectGate::resetProcessStateForTests();
        putenv('WELINE_TEST_DICTIONARY_COLLECT_LOCK_DIR');
        unset($_ENV['WELINE_TEST_DICTIONARY_COLLECT_LOCK_DIR']);
        if ($this->lockDir !== '' && is_dir($this->lockDir)) {
            foreach (glob($this->lockDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->lockDir);
        }
        parent::tearDown();
    }

    public function testGateAndCompilerContracts(): void
    {
        $root = dirname(__DIR__, 3);
        $gate = (string)file_get_contents($root . '/Phrase/DictionaryCollectGate.php');
        $busy = (string)file_get_contents($root . '/Phrase/DictionaryCollectBusyException.php');
        $compiler = (string)file_get_contents($root . '/Phrase/DictionaryCompiler.php');
        $collect = (string)file_get_contents($root . '/Console/Console/I18n/Collect.php');

        self::assertStringContainsString('class DictionaryCollectGate', $gate);
        self::assertStringContainsString('LOCK_EX | LOCK_NB', $gate);
        self::assertStringContainsString('DEFAULT_WAIT_SECONDS = 0', $gate);
        self::assertStringContainsString('STALE_HOLD_SECONDS = 7200', $gate);
        self::assertStringContainsString('dictionary-collect.lock', $gate);
        self::assertStringContainsString('WELINE_TEST_DICTIONARY_COLLECT_LOCK_DIR', $gate);
        self::assertStringContainsString('disconnectStaleHolder', $gate);
        self::assertStringContainsString('(string)\\BP', $gate);
        self::assertStringContainsString('I18N_DICTIONARY_COLLECT_BUSY', $busy);
        self::assertStringContainsString('DictionaryCollectGate::acquire', $compiler);
        self::assertStringContainsString('DictionaryCollectGate::release', $compiler);
        self::assertStringContainsString('DictionaryCollectBusyException', $collect);
        self::assertStringContainsString('return 75', $collect);
        self::assertStringNotContainsString("\nsleep(", $gate);
        self::assertStringNotContainsString('\\sleep(', $gate);
    }

    public function testSameProcessReentrancy(): void
    {
        DictionaryCollectGate::acquire('Weline_Cart');
        DictionaryCollectGate::acquire('Weline_Checkout'); // nested depth, same process
        self::assertSame('Weline_Cart', DictionaryCollectGate::heldScope());
        DictionaryCollectGate::release();
        DictionaryCollectGate::release();
        self::assertSame('__all__', DictionaryCollectGate::heldScope());
    }

    public function testAcquireFailsWhenExternalHolderOwnsLock(): void
    {
        $path = $this->lockDir . '/dictionary-collect.lock';
        $external = fopen($path, 'c+');
        self::assertNotFalse($external);
        self::assertTrue(flock($external, LOCK_EX | LOCK_NB));
        fwrite($external, json_encode([
            'pid' => 1,
            'scope' => 'external-holder',
            'acquired_at' => date('c'),
        ]) ?: '{}');
        fflush($external);

        try {
            $this->expectException(DictionaryCollectBusyException::class);
            $this->expectExceptionMessage(DictionaryCollectBusyException::BUSY_MARKER);
            DictionaryCollectGate::acquire('Weline_Cart', 0);
        } finally {
            @flock($external, LOCK_UN);
            @fclose($external);
            DictionaryCollectGate::resetProcessStateForTests();
        }
    }
}

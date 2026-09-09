<?php
declare(strict_types=1);
namespace Weline\I18n\Test\Unit\Service;

use PDO;
use PHPUnit\Framework\TestCase;

final class AiTranslationPublisherAtomicPublicationTest extends TestCase
{
    private string $directory;
    private PDO $db;
    private const OLD_FILE = "<?php return ['word' => 'old'];";

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/weline-i18n-publish-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/generated/language', 0755, true);
        file_put_contents($this->directory . '/generated/language/en_US.php', self::OLD_FILE);
        $this->db = new PDO('sqlite:' . $this->directory . '/probe.sqlite');
        $this->db->exec('CREATE TABLE revisions(resource_key TEXT PRIMARY KEY, resource_type TEXT NOT NULL, resource_id TEXT NOT NULL, revision INTEGER NOT NULL, updated_at TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE dictionary(word TEXT NOT NULL, translate TEXT NOT NULL)');
        $this->db->exec("INSERT INTO dictionary VALUES ('word', 'new')");
        $this->db->exec('CREATE TABLE versions(generation INTEGER NOT NULL)');
        $this->db->exec('INSERT INTO versions VALUES (0)');
        $this->db->exec('CREATE TABLE publications(content_sha256 TEXT NOT NULL)');
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testChangedFailureRestoresOldFileAndRetryPublishesVersion(): void
    {
        [$process, $pipes] = $this->start('failure');
        $failed = $this->finish($process, $pipes);
        self::assertSame('fixture_critical_observer_failed', $failed['error'] ?? null, json_encode($failed));
        self::assertSame(self::OLD_FILE, file_get_contents($this->directory . '/generated/language/en_US.php'));
        self::assertSame(0, (int)$this->db->query('SELECT generation FROM versions')->fetchColumn());
        [$process, $pipes] = $this->start('normal');
        $result = $this->finish($process, $pipes);
        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame(1, (int)$this->db->query('SELECT generation FROM versions')->fetchColumn());
        self::assertSame(hash_file('sha256', $this->directory . '/generated/language/en_US.php'), $this->db->query('SELECT content_sha256 FROM publications')->fetchColumn());
        [$process, $pipes] = $this->start('normal');
        $result = $this->finish($process, $pipes);
        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame(2, (int)$this->db->query('SELECT generation FROM versions')->fetchColumn(), '相同文件不能证明上次版本已提交，本轮保留显式版本发布。');
    }

    public function testConcurrentLocalePublisherCannotReadBeforePriorPublicationFinishes(): void
    {
        [$first, $firstPipes] = $this->start('hold');
        self::assertSame('dictionary_read', json_decode((string)fgets($firstPipes[1]), true)['stage'] ?? null);
        [$second, $secondPipes] = $this->start('second');
        $read = [$secondPipes[1]];
        $write = $except = [];
        $readyBeforeRelease = stream_select($read, $write, $except, 0, 200000);
        fwrite($firstPipes[0], "release\n");
        $firstResult = $this->finish($first, $firstPipes);
        $secondResult = $this->finish($second, $secondPipes);
        self::assertSame(0, $readyBeforeRelease, '第二发布者必须先取得同 locale 修订行锁，不能先读旧快照。' . json_encode([$firstResult, $secondResult]));
        self::assertTrue($firstResult['ok'], json_encode($firstResult));
        self::assertTrue($secondResult['ok'], json_encode($secondResult));
        self::assertSame(2, (int)$this->db->query('SELECT generation FROM versions')->fetchColumn());
    }

    private function start(string $mode): array
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/Fixtures/i18n_file_publish_probe.php', $mode, $this->directory],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        return [$process, $pipes];
    }

    private function finish($process, array $pipes): array
    {
        fclose($pipes[0]);
        $lines = trim(stream_get_contents($pipes[1]));
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        $lines = explode("\n", $lines);
        $result = json_decode((string)end($lines), true);
        self::assertIsArray($result, $errors . implode("\n", $lines));
        return $result;
    }
}

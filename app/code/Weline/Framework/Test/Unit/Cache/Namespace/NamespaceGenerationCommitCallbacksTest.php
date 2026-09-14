<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache\Namespace;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Cache\Namespace\NamespaceGenerationSnapshot;
use Weline\Framework\Cache\Namespace\NamespaceKeyDecorator;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Model\Cache\NamespaceVersion;
use Weline\Framework\Runtime\RequestContext;

final class NamespaceGenerationCommitCallbacksTest extends TestCase
{
    private string $file;
    private Connector $connector;
    private ConnectionFactory $connection;
    private TransactionCoordinator $transactions;
    private NamespaceGenerationRepository $repository;

    protected function setUp(): void
    {
        RequestContext::init();
        TransactionContext::reset();
        $this->file = tempnam(sys_get_temp_dir(), 'weline-namespace-commit-');
        $config = new ConfigProvider(['type' => 'sqlite', 'database' => '', 'path' => $this->file, 'persistent' => false]);
        $this->connector = new Connector($config);
        $this->connection = $this->createMock(ConnectionFactory::class);
        $this->connection->method('getConnector')->willReturn($this->connector);
        $this->connection->method('getConfigProvider')->willReturn($config);
        $this->connector->query('CREATE TABLE namespace_versions (namespace_hash TEXT PRIMARY KEY, namespace TEXT NOT NULL, generation INTEGER NOT NULL, updated_at TEXT NOT NULL)')->fetch();
        $model = (new \ReflectionClass(NamespaceVersion::class))->newInstanceWithoutConstructor();
        $model->setConnection($this->connection);
        $model->_primary_key = 'namespace_hash';
        $model->origin_table_name = 'namespace_versions';
        $this->transactions = new TransactionCoordinator();
        $this->repository = new NamespaceGenerationRepository($model, new NamespacePath(), new NamespaceGenerationSnapshot(), new NamespaceKeyDecorator(), $this->transactions);
    }

    protected function tearDown(): void
    {
        TransactionContext::reset();
        RequestContext::cleanup();
        $this->connector->close();
        unlink($this->file);
    }

    public function testNamedCommitCallbackReceivesEveryNamespaceOnceAfterOwnerCommit(): void
    {
        $delivered = [];
        $this->transactions->run($this->connection, function () use (&$delivered): void {
            $this->repository->bumpMany(['global/i18n/content', 'global/i18n/en_US'], [
                'i18n' => function (int $clock, array $changes) use (&$delivered): void {
                    $delivered[] = [$clock, $changes, $this->connector->getWrappedConnection()->inTransaction()];
                },
            ]);
            $this->repository->bumpMany(['global/i18n/content', 'global/i18n/fr_FR'], [
                'i18n' => static function (): void { self::fail('The same publication key must be deduplicated.'); },
            ]);
            $this->repository->bumpMany(['global/i18n/en_US']);
            self::assertSame([], $delivered);
        });

        self::assertSame([[1, ['global/i18n/content' => 1, 'global/i18n/en_US' => 1, 'global/i18n/fr_FR' => 1], false]], $delivered);
        self::assertSame(1, $this->repository->processSnapshot()['authority_clock']);
    }

    public function testRollbackDiscardsCallbacksAndChangesBeforeNextTransaction(): void
    {
        $delivered = [];
        $callback = static function (int $clock, array $changes) use (&$delivered): void { $delivered[] = [$clock, $changes]; };
        try {
            $this->transactions->run($this->connection, function () use ($callback): void {
                $this->repository->bumpMany(['global/i18n/en_US'], ['i18n' => $callback]);
                throw new \RuntimeException('rollback_probe');
            });
            self::fail('The owner transaction must fail.');
        } catch (\RuntimeException $failure) {
            self::assertSame('rollback_probe', $failure->getMessage());
        }
        self::assertSame([], $delivered);
        self::assertSame([], $this->connector->query('SELECT * FROM namespace_versions')->fetch());

        $this->repository->bumpMany(['global/i18n/fr_FR'], ['i18n' => $callback]);
        self::assertSame([[1, ['global/i18n/fr_FR' => 1]]], $delivered);
    }

    public function testSingleArgumentBumpStillCommitsNormally(): void
    {
        self::assertSame(['authority_clock' => 1, 'changes' => ['global/i18n' => 1]], $this->repository->bumpMany(['global/i18n']));
        self::assertSame(['authority_clock' => 2, 'changes' => ['global/i18n' => 2]], $this->repository->bump('global/i18n'));
        self::assertFalse($this->connector->getWrappedConnection()->inTransaction());
    }
}

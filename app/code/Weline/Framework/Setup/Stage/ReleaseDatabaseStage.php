<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Stage;

use Throwable;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\Release\Api\DatabaseReleaseCoordinatorInterface;

/**
 * RSSE database stage: delegates schema/data upgrade to the Database release
 * coordinator (snapshot -> plan -> expand/transform/contract -> commit).
 *
 * This stage carries no legacy migration/backup domain. It is intentionally
 * compatible with the existing StageInterface so it can replace SchemaDiffStage
 * once the cutover is approved.
 */
final class ReleaseDatabaseStage extends AbstractStage
{
    /** @var array<string, mixed> */
    private array $context = [];

    /** @var array<string, mixed> */
    private array $lastResult = [];

    public const STAGE_NAME = 'release_database';

    public function __construct(
        private readonly DatabaseReleaseCoordinatorInterface $coordinator,
    ) {
    }

    public function getName(): string
    {
        return self::STAGE_NAME;
    }

    public function prepare(array $context = []): void
    {
        if ($this->prepared) {
            return;
        }
        $this->context = $context;
        $this->prepared = true;
        $this->clearErrors();
    }

    public function commit(): void
    {
        if (!$this->prepared) {
            throw new Exception(__('阶段 %{1} 尚未准备，无法提交', [$this->getName()]));
        }
        if ($this->committed) {
            return;
        }

        $operationId = trim((string)($this->context['operation_id'] ?? ''));
        if ($operationId === '') {
            throw new Exception(__('阶段 %{1} 缺少 operation_id', [$this->getName()]));
        }
        $releaseId = 'setup-' . $operationId;

        try {
            $this->lastResult = $this->coordinator->apply($releaseId, [
                'operation_id' => $operationId,
                'signature' => 'local-' . hash('sha256', $releaseId),
                'source' => 'setup',
            ]);
        } catch (Throwable $exception) {
            $this->addError($exception->getMessage());
            $this->rollback();
            throw new Exception(__('RSSE 数据库升级失败：%{1}', [$exception->getMessage()]), 0, $exception);
        }

        $this->committed = true;
        $this->clearErrors();

        // Release the connector lease so later stages (route registration fibers,
        // etc.) can check out a fresh lease under their own owner key. Holding the
        // lease across stages triggers database_connector_owner_mismatch.
        try {
            \Weline\Framework\Database\ConnectionFactory::getInstance()->getConnector()->close();
        } catch (\Throwable) {
            // Connection may already be closed by the coordinator; non-fatal.
        }
    }

    public function rollback(): void
    {
        $this->prepared = false;
        $this->committed = false;
        $this->lastResult = [];
    }

    /** Keep the SchemaDiffStage contract used by Upgrade.php. */
    public function wasSourceFingerprintSkipped(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function getLastResult(): array
    {
        return $this->lastResult;
    }
}

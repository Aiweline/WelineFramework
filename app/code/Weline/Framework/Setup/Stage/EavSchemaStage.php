<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Stage;

use Weline\Framework\App\Exception;
use Weline\Framework\Setup\Model\Migration;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * Eav 表创建阶段（order=2，可选）：若 Eav 模块存在则调用 SchemaRegistry::createAllTables，
 * 每表创建后派发 table_ddl_after，与 ModuleManager 等观察者对齐。
 */
class EavSchemaStage extends AbstractStage
{
    public const EVENT_TABLE_DDL_AFTER = 'Weline_Framework_Schema::table_ddl_after';

    public function __construct(
        private readonly EventsManager $eventsManager,
        private readonly Migration $migrationModel,
        private readonly Printing $printing,
    ) {
    }

    public function getName(): string
    {
        return 'eav_schema';
    }

    public function prepare(array $context = []): void
    {
        if ($this->prepared) {
            return;
        }
        $this->prepared = true;
        $this->clearErrors();
    }

    public function validate(): bool
    {
        return parent::validate();
    }

    public function commit(): void
    {
        if (!$this->prepared) {
            throw new Exception(__('阶段 %{1} 尚未准备，无法提交', [$this->getName()]));
        }
        if ($this->committed) {
            return;
        }

        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(EavSchemaProviderInterface::class);
        if (!$provider instanceof EavSchemaProviderInterface) {
            $this->committed = true;
            return;
        }

        /** @var ModelSetup $setup */
        $setup = ObjectManager::getInstance(ModelSetup::class, ['printing' => $this->printing]);
        $setup->putModel($this->migrationModel);

        $connection = $this->migrationModel->getConnection();
        $prefix = $connection->getConfigProvider()->getPrefix();
        $ownerModuleName = $provider->ownerModuleName();

        foreach ($provider->createTables($setup) as $tableName) {
            $fullTableName = $prefix . $tableName;
            $eventData = new DataObject([
                'module_name' => $ownerModuleName,
                'table_name' => $fullTableName,
                'model_class' => null,
            ]);
            $this->eventsManager->dispatch(self::EVENT_TABLE_DDL_AFTER, $eventData);
        }

        $this->committed = true;
        $this->clearErrors();
    }

    public function rollback(): void
    {
        if (!$this->prepared) {
            return;
        }
        $this->prepared = false;
        $this->committed = false;
    }
}

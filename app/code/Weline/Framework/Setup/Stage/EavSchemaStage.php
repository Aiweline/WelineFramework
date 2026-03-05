<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Stage;

use Weline\Framework\App\Exception;
use Weline\Framework\Setup\Model\Migration;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * Eav 表创建阶段（order=2，可选）：若 Eav 模块存在则调用 SchemaRegistry::createAllTables，
 * 每表创建后派发 table_ddl_after，与 ModuleManager 等观察者对齐。
 */
class EavSchemaStage extends AbstractStage
{
    public const EVENT_TABLE_DDL_AFTER = 'Weline_Framework_Schema::table_ddl_after';

    private const EAV_REGISTRY_CLASS = \Weline\Eav\Schema\SchemaRegistry::class;
    private const EAV_MODULE_NAME = 'Weline_Eav';

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

        if (!class_exists(self::EAV_REGISTRY_CLASS)) {
            $this->committed = true;
            return;
        }

        /** @var \Weline\Eav\Schema\SchemaRegistry $registry */
        $registry = ObjectManager::getInstance(self::EAV_REGISTRY_CLASS);
        $registry->registerClasses($registry::getDefaultSchemas());

        /** @var ModelSetup $setup */
        $setup = ObjectManager::getInstance(ModelSetup::class, ['printing' => $this->printing]);
        $setup->putModel($this->migrationModel);

        $sortedSchemas = $registry->getSortedSchemas();
        $connection = $this->migrationModel->getConnection();
        $prefix = $connection->getConfigProvider()->getPrefix();

        foreach ($sortedSchemas as $schema) {
            $registry->createTable($setup, $schema);
            $tableName = $schema->getTableName();
            $fullTableName = $prefix . $tableName;
            $this->eventsManager->dispatch(self::EVENT_TABLE_DDL_AFTER, new DataObject([
                'module_name' => self::EAV_MODULE_NAME,
                'table_name' => $fullTableName,
                'model_class' => null,
            ]));
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

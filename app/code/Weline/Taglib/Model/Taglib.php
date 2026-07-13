<?php

declare(strict_types=1);

namespace Weline\Taglib\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\ModuleManager\Api\ModuleCatalogEntry;
use Weline\ModuleManager\Api\ModuleCatalogInterface;

#[Table(comment: '标签表')]
#[Index(name: 'idx_name', columns: ['name'], type: 'UNIQUE')]
class Taglib extends Model
{

    public const schema_table = 'taglib';
    public const schema_primary_key = 'taglib_id';
    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: '标签ID')]
    public const schema_fields_ID = 'taglib_id';
    #[Col('int', 11, nullable: false, comment: '标签模型ID')]
    public const schema_fields_MODULE_ID = 'module_id';
    #[Col('varchar', 60, nullable: false, unique: true, comment: '标签')]
    public const schema_fields_NAME = 'name';
    #[Col('text', comment: 'Taglib 详情')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col('text', comment: 'Taglib数据')]
    public const schema_fields_JSON = 'json';
    #[Col('int', 1, default: 0, comment: '是否系统标签')]
    public const schema_fields_is_system = 'is_system';
    private const MODULE_JOIN_FIELDS = [
        'module_module_id' => ModuleCatalogEntry::FIELD_ID,
        'module_name' => ModuleCatalogEntry::FIELD_NAME,
        'status' => ModuleCatalogEntry::FIELD_STATUS,
        'module_description' => ModuleCatalogEntry::FIELD_DESCRIPTION,
        'position' => ModuleCatalogEntry::FIELD_POSITION,
        'namespace_path' => ModuleCatalogEntry::FIELD_NAMESPACE_PATH,
        'base_path' => ModuleCatalogEntry::FIELD_BASE_PATH,
        'path' => ModuleCatalogEntry::FIELD_PATH,
        'version' => ModuleCatalogEntry::FIELD_VERSION,
        'last_version' => ModuleCatalogEntry::FIELD_LAST_VERSION,
        'router' => ModuleCatalogEntry::FIELD_ROUTER,
        'module_create_time' => ModuleCatalogEntry::FIELD_CREATE_TIME,
        'module_update_time' => ModuleCatalogEntry::FIELD_UPDATE_TIME,
    ];

    private ModuleCatalogInterface $moduleCatalog;

    public function __construct(ModuleCatalogInterface $moduleCatalog, array $data = [])
    {
        parent::__construct($data);
        $this->moduleCatalog = $moduleCatalog;
    }

    public function filterByNameOrModule(string $query): static
    {
        $moduleIds = $this->moduleCatalog->idsMatchingName($query);
        $this->where('main_table.name', '%' . $query . '%', 'like', $moduleIds === [] ? 'and' : 'or');
        if ($moduleIds !== []) {
            $this->where('main_table.module_id', $moduleIds, 'in');
        }
        return $this;
    }

    /** @param array<array-key, mixed> $items @return array<array-key, mixed> */
    public function hydrateModuleMetadata(array $items): array
    {
        $moduleIds = [];
        foreach ($items as $item) {
            if (!$item instanceof self) {
                continue;
            }
            $moduleId = (int) $item->getData(self::schema_fields_MODULE_ID);
            if ($moduleId > 0) {
                $moduleIds[$moduleId] = $moduleId;
            }
        }
        $entries = $this->moduleCatalog->byIds(array_values($moduleIds));

        foreach ($items as $item) {
            if (!$item instanceof self) {
                continue;
            }
            $entry = $entries[(int) $item->getData(self::schema_fields_MODULE_ID)] ?? null;
            foreach (self::MODULE_JOIN_FIELDS as $targetField => $sourceField) {
                $item->setData($targetField, $entry?->get($sourceField));
            }
        }

        return $items;
    }

    /** 标签数据同步，由 Setup/Install 在安装时调用 */
    public function syncTaglibData(): void
    {
        $tagLibs = \Weline\Taglib\Helper\Taglib::getTagLibs();
        foreach ($tagLibs as $tagName => $tagLib) {
            unset($tagLib['callback']);
            $module_id = $tagLib['module_name'] ?? 0;
            if ($module_id) {
                $module_id = $this->moduleCatalog->idByName((string) $module_id);
            }
            $doc = $tagLib['doc'] ?? '系统标签，请查看：' . htmlentities('<a href="https://gitee.com/aiweline/WelineFramework/wikis/%E5%BC%80%E5%8F%91%E6%96%87%E6%A1%A3/%E5%89%8D%E7%AB%AF%E5%BC%80%E5%8F%91/%E6%A0%87%E7%AD%BE/var">标签文档</a>');
            $this->clearData();
            $this->setData(self::schema_fields_NAME, $tagName, true)
                ->setData(self::schema_fields_is_system, $tagLib['is_custom'] ?? 1)
                ->setData(self::schema_fields_MODULE_ID, $module_id)
                ->setData(self::schema_fields_DESCRIPTION, $doc)
                ->setData(self::schema_fields_JSON, json_encode($tagLib, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))
                ->save(true);
        }
    }
}

<?php

declare(strict_types=1);

namespace Weline\Taglib\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\ModuleManager\Model\Module;

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
    private Module $module;

    public function __construct(Module $module, array $data = [])
    {
        parent::__construct($data);
        $this->module = $module;
    }

    /** 标签数据同步，由 Setup/Install 在安装时调用 */
    public function syncTaglibData(): void
    {
        $tagLibs = \Weline\Taglib\Helper\Taglib::getTagLibs();
        foreach ($tagLibs as $tagName => $tagLib) {
            unset($tagLib['callback']);
            $module_id = $tagLib['module_name'] ?? 0;
            if ($module_id) {
                $module = $this->module->clearData()
                    ->where('name', $module_id)
                    ->find()
                    ->fetch();
                $module_id = $module->getId();
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


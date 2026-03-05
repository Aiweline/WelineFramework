<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\DeveloperWorkspace\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Database\Exception\DbException;
use Weline\Framework\Database\Helper\Importer\SqlFile;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
#[Table(comment: '开发文章')]
#[Index(name: 'idx_module_file_unique', columns: ['module_name', 'file_path'], type: 'UNIQUE')]
class Document extends Model
{
    public const schema_table = 'developer_workspace_document';
    public const schema_primary_key = 'id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('varchar', 500, nullable: false, comment: '标题')]
    public const schema_fields_TITLE = 'title';
    #[Col('varchar', 1000, nullable: false, comment: '摘要')]
    public const schema_fields_summary = 'summary';
    #[Col('int', default: 0, comment: '作者ID')]
    public const schema_fields_AUTHOR_ID = 'author_id';
    #[Col('int', nullable: false, comment: '分类ID')]
    public const schema_fields_CATEGORY_ID = 'category_id';
    #[Col('longtext', nullable: false, comment: '内容')]
    public const schema_fields_CONTEND = 'content';
    #[Col('varchar', 100, comment: '所属模块')]
    public const schema_fields_MODULE_NAME = 'module_name';
    #[Col('varchar', 500, comment: '文件路径')]
    public const schema_fields_FILE_PATH = 'file_path';
    #[Col('varchar', 200, comment: '文件名')]
    public const schema_fields_FILE_NAME = 'file_name';
    #[Col('int', 1, default: 0, comment: '是否自动导入')]
    public const schema_fields_IS_AUTO_IMPORTED = 'is_auto_imported';
    #[Col('int', default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('int', 11, default: 0, comment: '源文件修改时间戳')]
    public const schema_fields_FILE_MTIME = 'file_mtime';
    #[Col('datetime', comment: '记录更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
public function getTitle()
    {
        return $this->getData(self::schema_fields_TITLE);
    }
    public function setTitle(string $title): Document
    {
        return $this->setData(self::schema_fields_TITLE, $title);
    }
    public function getAuthorId()
    {
        return $this->getData(self::schema_fields_AUTHOR_ID);
    }
    public function setAuthorID(string|int $author_id): Document
    {
        return $this->setData(self::schema_fields_AUTHOR_ID, $author_id);
    }
//    public function getTagId()
//    {
//        return $this->getData(self::schema_fields_TAG_ID);
//    }
//
//    public function setTagID(string|int $tag_id): Document
//    {
//        return $this->setData(self::schema_fields_TAG_ID, $tag_id);
//    }
    public function getContent()
    {
        return $this->getData(self::schema_fields_CONTEND);
    }
    public function getDecodeContent()
    {
        return htmlspecialchars_decode($this->getContent());
    }
    public function setContent(string $content): Document
    {
        return $this->setData(self::schema_fields_CONTEND, $content);
    }
    public function setCategoryId(string $category_id): Document
    {
        return $this->setData(self::schema_fields_CATEGORY_ID, $category_id);
    }
    public function getCategoryId()
    {
        return $this->getData(self::schema_fields_CATEGORY_ID);
    }
    public function getUrl()
    {
        /**@var Url $url */
        $url = ObjectManager::getInstance(Url::class);
        // 返回前端文档浏览页面的URL
        return $url->getUrl('/dev/tool/', ['id' => $this->getId()]);
    }
    /**
     * @DESC          # 方法描述
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/4/19 22:36
     * 参数区：
     *
     * @param int $id
     *
     * @return Document[]
     */
    public function loadByCatalogId(int $id): array
    {
        return $this->where(self::schema_fields_CATEGORY_ID, $id)->select()->fetch()->getItems();
    }
    
    public function getModuleName(): string
    {
        return $this->getData(self::schema_fields_MODULE_NAME) ?? '';
    }
    
    public function setModuleName(string $moduleName): static
    {
        return $this->setData(self::schema_fields_MODULE_NAME, $moduleName);
    }
    
    public function getFilePath(): string
    {
        return $this->getData(self::schema_fields_FILE_PATH) ?? '';
    }
    
    public function setFilePath(string $filePath): static
    {
        return $this->setData(self::schema_fields_FILE_PATH, $filePath);
    }
    
    public function getFileName(): string
    {
        return $this->getData(self::schema_fields_FILE_NAME) ?? '';
    }
    
    public function setFileName(string $fileName): static
    {
        return $this->setData(self::schema_fields_FILE_NAME, $fileName);
    }
    
    public function isAutoImported(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_AUTO_IMPORTED);
    }
    
    public function setIsAutoImported(bool $isAutoImported): static
    {
        return $this->setData(self::schema_fields_IS_AUTO_IMPORTED, $isAutoImported ? 1 : 0);
    }
    
    public function getSortOrder(): int
    {
        return (int)($this->getData(self::schema_fields_SORT_ORDER) ?? 0);
    }
    
    public function setSortOrder(int $sortOrder): static
    {
        return $this->setData(self::schema_fields_SORT_ORDER, $sortOrder);
    }
}

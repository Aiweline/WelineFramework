<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/30 16:53:17
 */
namespace Weline\Acl\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '白名单ACL资源表')]
class WhiteAclSource extends Model
{
    public const schema_fields_ID   = 'path';
    #[Col(type: 'varchar', length: 255, primaryKey: true, nullable: false, comment: '白名单链接路径')]
    public const schema_fields_PATH = 'path';
    #[Col(type: 'varchar', length: 10, nullable: false, default: 'pc', comment: '类型：pc或api')]
    public const schema_fields_TYPE = 'type';
    
    public const type_PC = 'pc';
    public const type_API = 'api';
    /**
     * 获取类型
     */
    public function getType(): string
    {
        return (string)($this->getData(self::schema_fields_TYPE) ?? self::type_PC);
    }
    
    /**
     * 设置类型
     */
    public function setType(string $type): self
    {
        return $this->setData(self::schema_fields_TYPE, $type);
    }
}
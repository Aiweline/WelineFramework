<?php

declare(strict_types=1);

namespace Weline\RssEDemo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'RSSE Demo Items')]
#[Index(name: 'idx_rssedemo_item_name', columns: ['name'])]
class DemoItem extends Model
{
    public const schema_table = 'rssedemo_item';
    public const schema_primary_key = 'item_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Item ID')]
    public const schema_fields_ID = 'item_id';

    #[Col('varchar', 255, nullable: false, default: '', comment: 'Name')]
    public const schema_fields_NAME = 'name';

    #[Col('varchar', 64, nullable: false, default: 'draft', comment: 'Status')]
    public const schema_fields_STATUS = 'status';

    #[Col('text', nullable: true, comment: 'Note (v1.1.0)')]
    public const schema_fields_NOTE = 'note';

    public function getNote(): string
    {
        return (string)($this->getData(self::schema_fields_NOTE) ?: '');
    }

    public function getName(): string
    {
        return (string)($this->getData(self::schema_fields_NAME) ?: '');
    }

    public function setName(string $name): self
    {
        return $this->setData(self::schema_fields_NAME, $name);
    }
}

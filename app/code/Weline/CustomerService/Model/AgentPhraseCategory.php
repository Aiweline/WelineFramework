<?php

declare(strict_types=1);

namespace Weline\CustomerService\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '客服个人话术分类')]
#[Index(name: 'idx_agent_id', columns: ['agent_id'])]
#[Index(name: 'uniq_agent_name', columns: ['agent_id', 'name'], type: 'UNIQUE')]
class AgentPhraseCategory extends Model
{
    public const schema_table = 'cs_agent_phrase_category';
    public const schema_primary_key = 'category_id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '分类ID')]
    public const schema_fields_ID = 'category_id';
    #[Col('int', nullable: false, comment: '客服ID')]
    public const schema_fields_AGENT_ID = 'agent_id';
    #[Col('varchar', 64, nullable: false, comment: '分类名')]
    public const schema_fields_NAME = 'name';
    #[Col('int', nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT = 'sort_order';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function _init(): void
    {
        $this->_primary_key = self::schema_fields_ID;
        $this->_table = self::schema_table;
    }

    public function getAgentId(): int
    {
        return (int)$this->getData(self::schema_fields_AGENT_ID);
    }

    public function setAgentId(int $agentId): static
    {
        return $this->setData(self::schema_fields_AGENT_ID, $agentId);
    }

    public function getName(): string
    {
        return (string)$this->getData(self::schema_fields_NAME);
    }

    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_NAME, $name);
    }

    public function getSortOrder(): int
    {
        return (int)$this->getData(self::schema_fields_SORT);
    }

    public function setSortOrder(int $sort): static
    {
        return $this->setData(self::schema_fields_SORT, $sort);
    }
}

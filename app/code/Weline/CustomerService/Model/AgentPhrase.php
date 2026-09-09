<?php

declare(strict_types=1);

namespace Weline\CustomerService\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '客服个人话术库')]
#[Index(name: 'idx_agent_id', columns: ['agent_id'])]
class AgentPhrase extends Model
{
    public const schema_table = 'cs_agent_phrase';
    public const schema_primary_key = 'phrase_id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '话术ID')]
    public const schema_fields_ID = 'phrase_id';
    #[Col('int', nullable: false, comment: '客服ID')]
    public const schema_fields_AGENT_ID = 'agent_id';
    #[Col('varchar', 120, nullable: false, comment: '话术标题')]
    public const schema_fields_TITLE = 'title';
    #[Col('text', nullable: false, comment: '话术正文')]
    public const schema_fields_CONTENT = 'content';
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

    public function getTitle(): string
    {
        return (string)$this->getData(self::schema_fields_TITLE);
    }

    public function setTitle(string $title): static
    {
        return $this->setData(self::schema_fields_TITLE, $title);
    }

    public function getContent(): string
    {
        return (string)$this->getData(self::schema_fields_CONTENT);
    }

    public function setContent(string $content): static
    {
        return $this->setData(self::schema_fields_CONTENT, $content);
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

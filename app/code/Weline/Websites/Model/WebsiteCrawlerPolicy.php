<?php

declare(strict_types=1);

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Website crawler allow/block policy')]
#[Index(name: 'uniq_weline_websites_crawler_policy_website', columns: ['website_id'], type: 'UNIQUE')]
class WebsiteCrawlerPolicy extends Model
{
    public const schema_table = 'weline_websites_crawler_policy';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];

    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_ID = 'id';

    #[Col('int', 0, nullable: false, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 1, nullable: false, default: 1, comment: 'Whether website crawler policy is enabled')]
    public const schema_fields_ENABLED = 'enabled';

    #[Col('int', 0, nullable: false, default: 86400, comment: 'Block duration seconds')]
    public const schema_fields_BLOCK_DURATION = 'block_duration';

    #[Col('mediumtext', comment: 'JSON crawler entries with action allow|block')]
    public const schema_fields_ENTRIES_JSON = 'entries_json';

    #[Col('datetime', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public function loadByWebsiteId(int $websiteId): self
    {
        $this->reset()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->find()
            ->fetch();

        return $this;
    }
}

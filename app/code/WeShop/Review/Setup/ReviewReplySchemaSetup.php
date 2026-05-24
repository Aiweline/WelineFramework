<?php

declare(strict_types=1);

namespace WeShop\Review\Setup;

use WeShop\Review\Model\ReviewReply;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Setup\Data\Setup;

class ReviewReplySchemaSetup
{
    public function ensure(Setup $setup): void
    {
        $db = $setup->getDb();
        if ($db->tableExist(ReviewReply::schema_table)) {
            return;
        }

        $db->createTable(ReviewReply::schema_table, 'WeShop review reply table')
            ->addColumn(ReviewReply::schema_fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'Reply ID')
            ->addColumn(ReviewReply::schema_fields_REVIEW_ID, TableInterface::column_type_INTEGER, null, 'not null', 'Review ID')
            ->addColumn(ReviewReply::schema_fields_PARENT_REPLY_ID, TableInterface::column_type_INTEGER, null, 'not null default 0', 'Parent reply ID')
            ->addColumn(ReviewReply::schema_fields_PRODUCT_ID, TableInterface::column_type_INTEGER, null, 'not null', 'Product ID')
            ->addColumn(ReviewReply::schema_fields_CUSTOMER_ID, TableInterface::column_type_INTEGER, null, 'not null', 'Customer ID')
            ->addColumn(ReviewReply::schema_fields_DISPLAY_NAME, TableInterface::column_type_VARCHAR, 120, '', 'Display name')
            ->addColumn(ReviewReply::schema_fields_CONTENT, TableInterface::column_type_TEXT, null, 'not null', 'Reply content')
            ->addColumn(ReviewReply::schema_fields_MENTIONED_CUSTOMER_IDS, TableInterface::column_type_TEXT, null, '', 'Mentioned customer ID JSON')
            ->addColumn(ReviewReply::schema_fields_STATUS, TableInterface::column_type_VARCHAR, 20, 'not null default "approved"', 'Status')
            ->addColumn(ReviewReply::schema_fields_CREATED_AT, TableInterface::column_type_DATETIME, null, '', 'Created at')
            ->addColumn(ReviewReply::schema_fields_UPDATED_AT, TableInterface::column_type_DATETIME, null, '', 'Updated at')
            ->addIndex(TableInterface::index_type_KEY, 'idx_review_id', [ReviewReply::schema_fields_REVIEW_ID], 'Review ID index')
            ->addIndex(TableInterface::index_type_KEY, 'idx_parent_reply_id', [ReviewReply::schema_fields_PARENT_REPLY_ID], 'Parent reply ID index')
            ->addIndex(TableInterface::index_type_KEY, 'idx_product_id', [ReviewReply::schema_fields_PRODUCT_ID], 'Product ID index')
            ->addIndex(TableInterface::index_type_KEY, 'idx_customer_id', [ReviewReply::schema_fields_CUSTOMER_ID], 'Customer ID index')
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_review_status_created',
                [ReviewReply::schema_fields_REVIEW_ID, ReviewReply::schema_fields_STATUS, ReviewReply::schema_fields_CREATED_AT],
                'Review reply list index'
            )
            ->create();
    }
}

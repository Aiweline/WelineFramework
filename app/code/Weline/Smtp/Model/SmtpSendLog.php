<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/11/1 12:22:25
 */

namespace Weline\Smtp\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class SmtpSendLog extends \Weline\Framework\Database\Model
{

    const fields_FROM        = 'from_email';
    const fields_SENDER_NAME = 'sender_name';
    const fields_TO          = 'to_email';
    const fields_RPOXY       = 'proxy';
    const fields_REPLY_TO    = 'reply_to';
    const fields_SUBJECT     = 'subject';
    const fields_CONTEXT     = 'content';
    const fields_ALT         = 'alt';
    const fields_CC          = 'cc';
    const fields_BCC         = 'bcc';
    const fields_IS_HTML     = 'is_html';
    const fields_ATTACHMENT  = 'attachment';
    const fields_MODULE      = 'module';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
//        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable()
                  ->addColumn(
                      self::fields_ID,
                      TableInterface::column_type_INTEGER,
                      0,
                      'primary key auto_increment',
                      '发送者邮箱')
                  ->addColumn(
                      self::fields_FROM,
                      TableInterface::column_type_VARCHAR,
                      255,
                      'not null',
                      '发送者邮箱')
                  ->addColumn(
                      self::fields_SENDER_NAME,
                      TableInterface::column_type_VARCHAR,
                      30,
                      'not null',
                      '发送者昵称')
                  ->addColumn(
                      self::fields_TO,
                      TableInterface::column_type_MEDIU_TEXT,
                      0,
                      'not null',
                      '接收者邮箱(一个或者多个)')
                  ->addColumn(
                      self::fields_RPOXY,
                      TableInterface::column_type_VARCHAR,
                      255,
                      'not null',
                      '代理发送者')
                  ->addColumn(
                      self::fields_REPLY_TO,
                      TableInterface::column_type_MEDIU_TEXT,
                      0,
                      '',
                      '回复（一个或者多个）')
                  ->addColumn(
                      self::fields_SUBJECT,
                      TableInterface::column_type_VARCHAR,
                      255,
                      'not null',
                      '邮件标题')
                  ->addColumn(
                      self::fields_CONTEXT,
                      TableInterface::column_type_LONG_TEXT,
                      0,
                      'not null',
                      '邮件内容')
                  ->addColumn(
                      self::fields_ALT,
                      TableInterface::column_type_MEDIU_TEXT,
                      0,
                      '',
                      '邮件签名')
                  ->addColumn(
                      self::fields_CC,
                      TableInterface::column_type_MEDIU_TEXT,
                      0,
                      '',
                      '邮件抄送（一个或者多个）')
                  ->addColumn(
                      self::fields_BCC,
                      TableInterface::column_type_MEDIU_TEXT,
                      0,
                      '',
                      '隐蔽邮件抄送（一个或者多个）')
                  ->addColumn(
                      self::fields_IS_HTML,
                      TableInterface::column_type_SMALLINT,
                      1,
                      'default 1',
                      '是否是html')
                  ->addColumn(
                      self::fields_ATTACHMENT,
                      TableInterface::column_type_MEDIU_TEXT,
                      0,
                      '',
                      '附件（一个或者多个）')
                  ->addColumn(
                      self::fields_MODULE,
                      TableInterface::column_type_VARCHAR,
                      128,
                      'not null',
                      '模组')
                  ->addIndex(TableInterface::index_type_FULLTEXT, 'FROM_EMAIL', self::fields_FROM)
                  ->addIndex(TableInterface::index_type_FULLTEXT, 'TO_EMAIL', self::fields_TO)
                  ->addIndex(TableInterface::index_type_FULLTEXT, 'SEND_MODULE', self::fields_MODULE)
                  ->create();
        }
    }
}
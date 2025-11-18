<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Frontend\Model\System;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class FrontendNotification extends \Weline\Framework\Database\Model
{
    public const        fields_ID      = 'notification_id';
    public const        fields_title   = 'title';
    public const        fields_is_read = 'is_read';
    public const        fields_content = 'content';
    public const        fields_is_img  = 'is_img';
    public const        fields_is_icon = 'is_icon';
    public const        fields_avatar  = 'avatar';

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
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable()
                  ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '通知ID')
                  ->addColumn(self::fields_title, TableInterface::column_type_VARCHAR, 120, 'not null', '标题')
                  ->addColumn(self::fields_content, TableInterface::column_type_TEXT, null, 'not null', '内容')
                  ->addColumn(self::fields_is_img, TableInterface::column_type_SMALLINT, 1, 'not null default 0', '图片头像')
                  ->addColumn(self::fields_is_icon, TableInterface::column_type_SMALLINT, 1, 'not null default 0', 'icon图标头像')
                  ->addColumn(self::fields_avatar, TableInterface::column_type_VARCHAR, 255, 'not null', '头像内容')
                  ->addColumn(self::fields_is_read, TableInterface::column_type_SMALLINT, 1, 'not null', '是否已读')
                  ->create();
            $this->setTitle('欢迎来到 WelineFramework 后端！')
                 ->setContent('WelineFramework框架是
一个极度灵活的集多应用的快速的互联网框架。

1、代码可移植性。

2、自定义高可用高灵活性对象ORM。

3、前后端集成到一个module中，做到一个需求一个module。

4、代码模块化，接口以及传统路由分前后台。包括接口，具有后台接口入口，后台url入口。

5、配置文件统一化。文件位置：app/etc/env.php
等等...')
                 ->setIsRead()
                 ->setIsIcon(1)
                 ->setIsImg(0)
                 ->setAvatar('ri-checkbox-circle-line')
                 ->save();
            $this->unsetData(self::fields_ID);
            $this->setTitle('框架开发理念！')
                 ->setContent('灵活适应性强，高性能的基于PHP8的互联网快速开发框架...')
                 ->setIsRead()
                 ->setIsIcon(0)
                 ->setIsImg(1)
                 ->setAvatar('assets/images/users/avatar-3.jpg')
                 ->save();
        }
    }

    public function getTitle(): string
    {
        return $this->getData(self::fields_title) ?? '';
    }

    public function setTitle(string $title): static
    {
        $this->setData(self::fields_title, $title);
        return $this;
    }

    public function getContent(): string
    {
        return $this->getData(self::fields_content) ?? '';
    }

    public function setContent(string $content): static
    {
        $this->setData(self::fields_content, $content);
        return $this;
    }

    public function isRead()
    {
        return $this->getData(self::fields_is_read);
    }

    public function setIsRead(bool $is_read = true): static
    {
        $this->setData(self::fields_is_read, (int)$is_read);
        return $this;
    }
}

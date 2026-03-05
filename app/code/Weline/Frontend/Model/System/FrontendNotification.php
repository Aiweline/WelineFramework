<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Frontend\Model\System;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

#[Table(comment: '前端通知')]
class FrontendNotification extends Model
{
    public const schema_table = 'frontend_notification';
    public const schema_primary_key = 'notification_id';

    // 字段常量
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '通知ID')]
    public const schema_fields_ID = 'notification_id';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '标题')]
    public const schema_fields_title = 'title';
    #[Col(type: 'tinyint', length: 1, nullable: true, default: 0, comment: '是否已读')]
    public const schema_fields_is_read = 'is_read';
    #[Col(type: 'text', nullable: true, comment: '内容')]
    public const schema_fields_content = 'content';
    #[Col(type: 'tinyint', length: 1, nullable: true, default: 0, comment: '是否图片')]
    public const schema_fields_is_img = 'is_img';
    #[Col(type: 'tinyint', length: 1, nullable: true, default: 0, comment: '是否图标')]
    public const schema_fields_is_icon = 'is_icon';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: '头像/图标')]
    public const schema_fields_avatar = 'avatar';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    /** 表结构由 SchemaDiffStage 负责 */
    public function setup(ModelSetup $setup, Context $context): void {}
    public function upgrade(ModelSetup $setup, Context $context): void {}

    /** 种子数据由 Frontend/Setup/Install 安装 */
    public function install(ModelSetup $setup, Context $context): void {}

    /** 种子数据：默认两条欢迎通知（由 Setup/Install 调用） */
    public function seedInitialNotifications(): void
    {
        $exists = $this->clear()->select()->fetch();
        if ($exists->getItems() !== []) {
            return;
        }
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
        $this->unsetData(self::schema_fields_ID);
        $this->setTitle('框架开发理念！')
            ->setContent('灵活适应性强，高性能的基于PHP8的互联网快速开发框架...')
            ->setIsRead()
            ->setIsIcon(0)
            ->setIsImg(1)
            ->setAvatar('assets/images/users/avatar-3.jpg')
            ->save();
    }

    public function getTitle(): string
    {
        return $this->getData(self::schema_fields_title) ?? '';
    }

    public function setTitle(string $title): static
    {
        $this->setData(self::schema_fields_title, $title);
        return $this;
    }

    public function getContent(): string
    {
        return $this->getData(self::schema_fields_content) ?? '';
    }

    public function setContent(string $content): static
    {
        $this->setData(self::schema_fields_content, $content);
        return $this;
    }

    public function isRead()
    {
        return $this->getData(self::schema_fields_is_read);
    }

    public function setIsRead(bool $is_read = true): static
    {
        $this->setData(self::schema_fields_is_read, (int)$is_read);
        return $this;
    }
}


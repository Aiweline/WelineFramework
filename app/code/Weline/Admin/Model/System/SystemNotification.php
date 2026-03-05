<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Admin\Model\System;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '系统通知表')]
class SystemNotification extends Model
{
    public const fields_ID      = 'notification_id';
    public const fields_title   = 'title';
    public const fields_is_read = 'is_read';
    public const fields_content = 'content';
    public const fields_is_img  = 'is_img';
    public const fields_is_icon = 'is_icon';
    public const fields_avatar  = 'avatar';
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
    public function setIsRead(bool $is_read = false): static
    {
        $this->setData(self::schema_fields_is_read, (int)$is_read);
        return $this;
    }
    public function getIsImg(): int
    {
        return (int)($this->getData(self::schema_fields_is_img) ?? 0);
    }
    public function setIsImg(int $is_img = 0): static
    {
        $this->setData(self::schema_fields_is_img, $is_img);
        return $this;
    }
    public function getIsIcon(): int
    {
        return (int)($this->getData(self::schema_fields_is_icon) ?? 0);
    }
    public function setIsIcon(int $is_icon = 0): static
    {
        $this->setData(self::schema_fields_is_icon, $is_icon);
        return $this;
    }
    public function getAvatar(): string
    {
        return $this->getData(self::schema_fields_avatar) ?? '';
    }
    public function setAvatar(string $avatar): static
    {
        $this->setData(self::schema_fields_avatar, $avatar);
        return $this;
    }
}

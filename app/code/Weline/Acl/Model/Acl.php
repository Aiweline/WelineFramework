<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/4 23:51:24
 */

namespace Weline\Acl\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Acl extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'source_id';
    public const fields_ACL_ID = 'acl_id';
    public const fields_SOURCE_ID = 'source_id';
    public const fields_SOURCE_NAME = 'source_name';
    public const fields_DOCUMENT = 'document';
    public const fields_PARENT_SOURCE = 'parent_source';
    public const fields_ROUTER = 'router';
    public const fields_ROUTE = 'route';
    public const fields_METHOD = 'method';
    public const fields_REWRITE = 'rewrite';
    public const fields_MODULE = 'module';
    public const fields_CLASS = 'class';
    public const fields_TYPE = 'type';
    public const fields_ICON = 'icon';
    public const fields_IS_ENABLE = 'is_enable';
    public const fields_IS_BACKEND = 'is_backend';


    public const type_MENUS = 'menus';

    public array $_unit_primary_keys = [self::fields_SOURCE_ID];


    private Url $url;

    public function __init()
    {
        parent::__init();
        if (!isset($this->url)) {
            $this->url = ObjectManager::getInstance(Url::class);
        }
    }

    public function setAclId(string $acl_id): static
    {
        return $this->setData(self::fields_ACL_ID, $acl_id);
    }

    public function setSourceName(string $source_name): static
    {
        return $this->setData(self::fields_SOURCE_NAME, $source_name);
    }

    public function setDocument(string $document): static
    {
        return $this->setData(self::fields_DOCUMENT, $document);
    }

    public function setParentSource(string $parent_source): static
    {
        return $this->setData(self::fields_PARENT_SOURCE, $parent_source);
    }

    public function setRouter(string $router): static
    {
        return $this->setData(self::fields_ROUTER, $router);
    }

    public function setRoute(string $route): static
    {
        return $this->setData(self::fields_ROUTE, $route);
    }

    public function setMethod(string $method): static
    {
        return $this->setData(self::fields_METHOD, $method);
    }

    public function setRewrite(string $rewrite): static
    {
        return $this->setData(self::fields_REWRITE, $rewrite);
    }

    public function setModule(string $module): static
    {
        return $this->setData(self::fields_MODULE, $module);
    }

    public function setClass(string $class): static
    {
        return $this->setData(self::fields_CLASS, $class);
    }

    public function setType(string $type): static
    {
        return $this->setData(self::fields_TYPE, $type);
    }

    public function setIcon(string $icon): static
    {
        return $this->setData(self::fields_ICON, $icon);
    }

    public function setIsEnable(bool $is_enable = true): static
    {
        return $this->setData(self::fields_IS_ENABLE, $is_enable);
    }

    public function setIsBackend(bool $is_backend = true): static
    {
        return $this->setData(self::fields_IS_BACKEND, $is_backend);
    }

    public function getAclId(): int
    {
        return intval($this->getData(self::fields_ACL_ID));
    }

    public function getSourceName(): string
    {
        return $this->getData(self::fields_SOURCE_NAME);
    }

    public function getDocument(): string
    {
        return $this->getData(self::fields_DOCUMENT);
    }

    public function getParentSource(): string
    {
        return $this->getData(self::fields_PARENT_SOURCE) ?: '';
    }

    public function getRouter(): string
    {
        return $this->getData(self::fields_ROUTER);
    }

    public function getRoute(): string
    {
        return $this->getData(self::fields_ROUTE);
    }

    public function getMethod(): string
    {
        return $this->getData(self::fields_METHOD);
    }

    public function getRewrite(): string
    {
        return $this->getData(self::fields_REWRITE);
    }

    public function getModule(): string
    {
        return $this->getData(self::fields_MODULE);
    }

    public function getClass(): string
    {
        return $this->getData(self::fields_CLASS);
    }

    public function getType(): string
    {
        return $this->getData(self::fields_TYPE);
    }

    public function getIcon(): string
    {
        return $this->getData(self::fields_ICON);
    }

    public function getUrl(): string
    {
        if (!$this->isBackend()) {
            $url = '/' . trim($this->getRoute(), '/');
        } else {
            $url = $this->url->getBackendUrl('/' . trim($this->getRoute(), '/'));
        }
        return $url ?? '';
    }

    public function isEnable(): bool
    {
        return (bool)$this->getData(self::fields_IS_ENABLE);
    }


    public function isBackend(): bool
    {
        return (bool)$this->getData(self::fields_IS_BACKEND);
    }


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
        if ($setup->tableExist()) {
            $setup->query('TRUNCATE TABLE ' . $this->getTable());
        }
//        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(
                    self::fields_ACL_ID,
                    TableInterface::column_type_INTEGER,
                    null, 'primary key auto_increment', 'ACL权限ID'
                )
                ->addColumn(
                    self::fields_SOURCE_ID,
                    TableInterface::column_type_VARCHAR,
                    127, 'not null unique', 'ACL资源ID'
                )
                ->addColumn(
                    self::fields_SOURCE_NAME,
                    TableInterface::column_type_VARCHAR,
                    255, 'not null', 'ACL资源名称'
                )
                ->addColumn(
                    self::fields_DOCUMENT,
                    TableInterface::column_type_TEXT,
                    null, 'not null', 'ACL资源描述'
                )
                ->addColumn(
                    self::fields_PARENT_SOURCE,
                    TableInterface::column_type_VARCHAR,
                    255, 'not null', 'ACL父级资源'
                )
                ->addColumn(
                    self::fields_ROUTER,
                    TableInterface::column_type_VARCHAR,
                    60, 'not null', 'ACL路由前缀'
                )
                ->addColumn(
                    self::fields_REWRITE,
                    TableInterface::column_type_VARCHAR,
                    255, 'default ""', 'ACL路由重写'
                )
                ->addColumn(
                    self::fields_ROUTE,
                    TableInterface::column_type_VARCHAR,
                    255, '', 'ACL路由'
                )
                ->addColumn(
                    self::fields_METHOD,
                    TableInterface::column_type_VARCHAR,
                    6, 'default ""', 'ACL路由请求方法'
                )
                ->addColumn(
                    self::fields_MODULE,
                    TableInterface::column_type_VARCHAR,
                    255, 'not null', 'ACL模组'
                )
                ->addColumn(
                    self::fields_CLASS,
                    TableInterface::column_type_VARCHAR,
                    255, 'not null', '控制器类'
                )
                ->addColumn(
                    self::fields_TYPE,
                    TableInterface::column_type_VARCHAR,
                    120, 'not null', '类型'
                )
                ->addColumn(
                    self::fields_IS_BACKEND,
                    TableInterface::column_type_INTEGER,
                    1, 'default 1', '是否后台'
                )
                ->addColumn(
                    self::fields_ICON,
                    TableInterface::column_type_VARCHAR,
                    255, 'not null', '图片，可以是链接'
                )
                ->addColumn(
                    self::fields_IS_ENABLE,
                    TableInterface::column_type_SMALLINT,
                    255, 'default 1', '是否允许'
                )
                ->addAdditional('ENGINE=MyIsam;')
                ->create();
        }
    }
}

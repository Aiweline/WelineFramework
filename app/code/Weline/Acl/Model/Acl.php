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

use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

#[Table(comment: 'ACL权限表')]
#[Index(name: 'idx_source_id', columns: ['source_id'], type: 'UNIQUE', comment: 'ACL资源ID唯一')]
class Acl extends \Weline\Framework\Database\Model
{

    public const schema_primary_key = 'source_id';

    #[Col(type: 'varchar', length: 127, nullable: false, unique: true, comment: 'ACL资源ID')]
    public const schema_fields_ID = 'source_id';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ACL权限ID')]
    public const schema_fields_ACL_ID = 'acl_id';
    #[Col(type: 'int', nullable: true, default: 0, comment: '排序')]
    public const schema_fields_ORDER = 'order';
    #[Col(type: 'varchar', length: 127, nullable: false, unique: true, comment: 'ACL资源ID')]
    public const schema_fields_SOURCE_ID = 'source_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'ACL资源名称')]
    public const schema_fields_SOURCE_NAME = 'source_name';
    #[Col(type: 'text', nullable: false, comment: 'ACL资源描述')]
    public const schema_fields_DOCUMENT = 'document';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'ACL父级资源')]
    public const schema_fields_PARENT_SOURCE = 'parent_source';
    #[Col(type: 'varchar', length: 60, nullable: false, comment: 'ACL路由前缀')]
    public const schema_fields_ROUTER = 'router';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'ACL路由')]
    public const schema_fields_ROUTE = 'route';
    #[Col(type: 'varchar', length: 6, nullable: true, default: '', comment: 'ACL路由请求方法')]
    public const schema_fields_METHOD = 'method';
    #[Col(type: 'varchar', length: 255, nullable: true, default: '', comment: 'ACL路由重写')]
    public const schema_fields_REWRITE = 'rewrite';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'ACL模组')]
    public const schema_fields_MODULE = 'module';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '控制器类')]
    public const schema_fields_CLASS = 'class';
    #[Col(type: 'varchar', length: 120, nullable: false, comment: '类型')]
    public const schema_fields_TYPE = 'type';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '图片，可以是链接')]
    public const schema_fields_ICON = 'icon';
    #[Col(type: 'smallint', nullable: true, default: 1, comment: '是否允许')]
    public const schema_fields_IS_ENABLE = 'is_enable';
    #[Col(type: 'int', nullable: true, default: 1, comment: '是否后台')]
    public const schema_fields_IS_BACKEND = 'is_backend';

    public const type_MENUS = 'menus';

    public array $_unit_primary_keys = [self::schema_fields_SOURCE_ID];


    private ?Url $url = null;

    public function __init()
    {
        parent::__init();
        // 不再在 __init 中创建 Url 实例，改为延迟加载
        // 避免在模型实例化时触发 Url 及其依赖的创建，防止循环依赖
    }
    
    /**
     * 延迟加载 Url 实例
     * @return Url
     */
    private function getUrlInstance(): Url
    {
        if ($this->url === null) {
            $this->url = ObjectManager::getInstance(Url::class);
        }
        return $this->url;
    }

    public function setAclId(string $acl_id): static
    {
        return $this->setData(self::schema_fields_ACL_ID, $acl_id);
    }

    public function setOrder(int $order): static
    {
        return $this->setData(self::schema_fields_ORDER, $order);
    }

    public function getOrder(): int
    {
        return (int)$this->getData(self::schema_fields_ORDER);
    }

    public function setSourceName(string $source_name): static
    {
        return $this->setData(self::schema_fields_SOURCE_NAME, $source_name);
    }

    public function setDocument(string $document): static
    {
        return $this->setData(self::schema_fields_DOCUMENT, $document);
    }

    public function setParentSource(string $parent_source): static
    {
        return $this->setData(self::schema_fields_PARENT_SOURCE, $parent_source);
    }

    public function setRouter(string $router): static
    {
        return $this->setData(self::schema_fields_ROUTER, $router);
    }

    public function setRoute(string $route): static
    {
        return $this->setData(self::schema_fields_ROUTE, $route);
    }

    public function setMethod(string $method): static
    {
        return $this->setData(self::schema_fields_METHOD, $method);
    }

    public function setRewrite(string $rewrite): static
    {
        return $this->setData(self::schema_fields_REWRITE, $rewrite);
    }

    public function setModule(string $module): static
    {
        return $this->setData(self::schema_fields_MODULE, $module);
    }

    public function setClass(string $class): static
    {
        return $this->setData(self::schema_fields_CLASS, $class);
    }

    public function setType(string $type): static
    {
        return $this->setData(self::schema_fields_TYPE, $type);
    }

    public function setIcon(string $icon): static
    {
        return $this->setData(self::schema_fields_ICON, $icon);
    }

    public function setIsEnable(bool $is_enable = true): static
    {
        return $this->setData(self::schema_fields_IS_ENABLE, $is_enable);
    }

    public function setIsBackend(bool $is_backend = true): static
    {
        return $this->setData(self::schema_fields_IS_BACKEND, $is_backend);
    }

    public function getAclId(): int
    {
        return intval($this->getData(self::schema_fields_ACL_ID));
    }

    public function getSourceName(): string
    {
        return $this->getData(self::schema_fields_SOURCE_NAME);
    }

    public function getDocument(): string
    {
        return $this->getData(self::schema_fields_DOCUMENT);
    }

    public function getParentSource(): string
    {
        return $this->getData(self::schema_fields_PARENT_SOURCE) ?: '';
    }

    public function getRouter(): string
    {
        return $this->getData(self::schema_fields_ROUTER);
    }

    public function getRoute(): string
    {
        return $this->getData(self::schema_fields_ROUTE);
    }

    public function getMethod(): string
    {
        return $this->getData(self::schema_fields_METHOD);
    }

    public function getRewrite(): string
    {
        return $this->getData(self::schema_fields_REWRITE);
    }

    public function getModule(): string
    {
        return $this->getData(self::schema_fields_MODULE);
    }

    public function getClass(): string
    {
        return $this->getData(self::schema_fields_CLASS);
    }

    public function getType(): string
    {
        return $this->getData(self::schema_fields_TYPE);
    }

    public function getIcon(): string
    {
        return $this->getData(self::schema_fields_ICON);
    }

    public function getUrl(): string
    {
        if (!$this->isBackend()) {
            $url = '/' . trim($this->getRoute(), '/');
        } else {
            $url = $this->getUrlInstance()->getBackendUrl('/' . trim($this->getRoute(), '/'));
        }
        return $url ?? '';
    }

    public function isEnable(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_ENABLE);
    }


    public function isBackend(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_BACKEND);
    }


}


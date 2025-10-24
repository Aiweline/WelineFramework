<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Model;

use Weline\Acl\Model\Acl;
use Weline\Acl\Model\Role;
use Weline\Acl\Model\RoleAccess;
use Weline\Framework\App\Debug;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Menu extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'menu_id';

    public array $_unit_primary_keys = ['menu_id', 'source'];
    public array $_index_sort_keys = ['menu_id', 'source'];

    public const fields_NAME = 'name';
    public const fields_TITLE = 'title';
    public const fields_PID = 'pid';
    public const fields_SOURCE = 'source';
    public const fields_LEVEL = 'level';
    public const fields_PATH = 'path';
    public const fields_PARENT_SOURCE = 'parent_source';
    public const fields_ACTION = 'action';
    public const fields_MODULE = 'module';
    public const fields_ICON = 'icon';
    public const fields_ORDER = 'order';
    public const fields_IS_SYSTEM = 'is_system';
    public const fields_IS_ENABLE = 'is_enable';
    public const fields_IS_BACKEND = 'is_backend';

    private Url $url;

    public function __init()
    {
        parent::__init();
        if (!isset($this->url)) {
            $this->url = ObjectManager::getInstance(Url::class);
        }
    }

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
//        $setup->dropTable();
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
        $setup->getPrinting()->setup('安装数据表...' . self::table);
//        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable('后端菜单表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 60, 'not null', '菜单名')
                ->addColumn(self::fields_TITLE, TableInterface::column_type_VARCHAR, 60, 'not null', '菜单标题')
                ->addColumn(self::fields_PID, TableInterface::column_type_INTEGER, 0, '', '父级ID')
                ->addColumn(self::fields_SOURCE, TableInterface::column_type_VARCHAR, 128, 'unique', '资源')
                ->addColumn(self::fields_LEVEL, TableInterface::column_type_INTEGER, 0, 'default 0 ', '层级')
                ->addColumn(self::fields_PATH, TableInterface::column_type_VARCHAR, 255, '', '路径')
                ->addColumn(self::fields_PARENT_SOURCE, TableInterface::column_type_VARCHAR, 255, 'not null', '父级资源')
                ->addColumn(self::fields_ACTION, TableInterface::column_type_VARCHAR, 255, 'not null', '动作URL')
                ->addColumn(self::fields_MODULE, TableInterface::column_type_VARCHAR, 255, 'not null', '模块')
                ->addColumn(self::fields_ICON, TableInterface::column_type_VARCHAR, 60, 'not null', 'Icon图标类')
                ->addColumn(self::fields_ORDER, TableInterface::column_type_INTEGER, null, 'not null', '排序')
                ->addColumn(self::fields_IS_BACKEND, TableInterface::column_type_INTEGER, 1, 'default 1', '是否后台菜单')
                ->addColumn(self::fields_IS_SYSTEM, TableInterface::column_type_INTEGER, 1, 'default 0', '是否系统菜单')
                ->addColumn(self::fields_IS_ENABLE, TableInterface::column_type_INTEGER, 1, 'default 1', '是否启用')
                ->addAdditional('ENGINE=MyIsam;')
                ->create();
        } else {
            $setup->getPrinting()->warning('数据表存在，跳过安装数据表...' . self::table);
        }
    }

    public function getName(): string
    {
        return parent::getData(self::fields_NAME) ?? '';
    }

    public function setName(string $name): static
    {
        return parent::setData(self::fields_NAME, $name);
    }

    public function getLevel(): int
    {
        return parent::getData(self::fields_LEVEL) ?? 0;
    }

    public function setLevel(int $level): static
    {
        return parent::setData(self::fields_LEVEL, $level);
    }

    public function getPath(): string
    {
        return parent::getData(self::fields_PATH) ?? '';
    }

    public function setPath(string $path): static
    {
        return parent::setData(self::fields_PATH, $path);
    }

    public function getPid()
    {
        return parent::getData(self::fields_PID);
    }

    public function setPid(string $pid): static
    {
        return parent::setData(self::fields_NAME, $pid);
    }

    public function getSource(): string
    {
        return parent::getData(self::fields_SOURCE) ?? '';
    }

    public function setSource(string $source): static
    {
        return parent::setData(self::fields_SOURCE, $source);
    }

    public function getParentSource(): string
    {
        return parent::getData(self::fields_PARENT_SOURCE) ?? '';
    }

    public function setParentSource(string $source): static
    {
        return parent::setData(self::fields_PARENT_SOURCE, $source);
    }

    public function getAction(): string
    {
        return parent::getData(self::fields_ACTION) ?? '';
    }

    public function setAction(string $url): static
    {
        return parent::setData(self::fields_ACTION, $url);
    }

    public function getIcon(): string
    {
        return parent::getData(self::fields_ICON) ?? '';
    }

    public function setIcon(string $css_icon_class): static
    {
        return parent::setData(self::fields_ICON, $css_icon_class);
    }

    public function getTitle(): string
    {
        return parent::getData(self::fields_TITLE) ?? '';
    }

    public function setTitle(string $title): static
    {
        return parent::setData(self::fields_ICON, $title);
    }

    public function getOrder(): int
    {
        return intval(parent::getData(self::fields_ORDER));
    }

    public function setOrder(int $order): static
    {
        return parent::setData(self::fields_ORDER, $order);
    }

    public function getModule(): string
    {
        return $this->getData(self::fields_MODULE) ?? '';
    }

    public function setModule(string $module_name): static
    {
        return $this->setData(self::fields_MODULE, $module_name);
    }

    public function isSystem(): bool
    {
        return (bool)$this->getData(self::fields_IS_SYSTEM);
    }

    public function setIsSystem(bool $is_system): static
    {
        return $this->setData(self::fields_IS_SYSTEM, $is_system);
    }

    public function isEnable(): bool
    {
        return (bool)$this->getData(self::fields_IS_ENABLE);
    }

    public function setIsEnable(bool $is_enable): static
    {
        return $this->setData(self::fields_IS_ENABLE, $is_enable);
    }

    public function isBackend(): bool
    {
        return (bool)$this->getData(self::fields_IS_BACKEND);
    }

    public function setIsBackend(bool $is_backend): static
    {
        return $this->setData(self::fields_IS_BACKEND, $is_backend);
    }

    /*----------------------助手函数区-------------------------*/
    public function getUrl(): string
    {
        if (!$this->isBackend()) {
            $url = '/' . trim($this->getAction(), '/');
        } else {
            $url = $this->url->getBackendUrl('/' . trim($this->getAction(), '/'));
        }
        return $url ?? '';
    }

    static private function Acl(): Acl
    {
        return ObjectManager::getInstance(Acl::class);
    }

    /**
     * @DESC          # 获取角色菜单树
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/2/9 0:38
     * 参数区：
     */
    public function getMenuTreeByRole(Role $role): array
    {
        if ($role->getId() !== 1) {
            $roleAccessSources = $this->getRoleAccessSources($role);
//            p($roleAccessSources);//Jhll_Center::data_config
            $aclTree = self::Acl()
                ->getTree(
                    Acl::fields_PARENT_SOURCE,
                    '',
                    Acl::fields_ORDER,
                    'asc',
                    Acl::fields_SOURCE_ID,
                    $roleAccessSources,
                    'source_name'
                );
        } else {
            $aclTree = self::Acl()
                ->getTree(
                    Acl::fields_PARENT_SOURCE,
                    '',
                    Acl::fields_ORDER,
                    'asc',
                    Acl::fields_SOURCE_ID,
                    [],
                    'source_name'
                );
        }
        return $aclTree;
    }

    /**
     * @DESC          # 方法描述
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/5/14 11:44
     * 参数区：
     *
     * @param \Weline\Acl\Model\Role $role
     *
     * @return array|mixed
     */
    protected function getRoleAccessSources(Role $role): mixed
    {
        /**@var RoleAccess $roleSourceModel */
        $roleSourceModel = ObjectManager::getInstance(RoleAccess::class);
        $roleAccess = $roleSourceModel->where(RoleAccess::fields_ROLE_ID, $role->getId(0))->select()->fetchArray();
        $roleAccessSources = [];
        foreach ($roleAccess as $roleAccess) {
            $roleAccessSources[] = $roleAccess[RoleAccess::fields_SOURCE_ID];
        }
        return $roleAccessSources;
    }
}
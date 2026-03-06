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
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
#[Table(comment: '后端菜单表')]
#[Index(name: 'uk_source', columns: ['source'], type: 'UNIQUE', comment: '资源唯一')]
class Menu extends Model
{
    public const schema_primary_keys = ['menu_id', 'source'];
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_ID = 'menu_id';
    #[Col('varchar', 60, nullable: false, comment: '菜单名')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 60, nullable: false, comment: '菜单标题')]
    public const schema_fields_TITLE = 'title';
    #[Col('int', 0, comment: '父级ID')]
    public const schema_fields_PID = 'pid';
    #[Col('varchar', 128, nullable: false, comment: '资源')]
    public const schema_fields_SOURCE = 'source';
    #[Col('int', 0, default: 0, comment: '层级')]
    public const schema_fields_LEVEL = 'level';
    #[Col('varchar', 255, comment: '路径')]
    public const schema_fields_PATH = 'path';
    #[Col('varchar', 255, nullable: false, comment: '父级资源')]
    public const schema_fields_PARENT_SOURCE = 'parent_source';
    #[Col('varchar', 255, nullable: false, comment: '动作URL')]
    public const schema_fields_ACTION = 'action';
    #[Col('varchar', 255, nullable: false, comment: '模块')]
    public const schema_fields_MODULE = 'module';
    #[Col('varchar', 60, nullable: false, comment: 'Icon图标类')]
    public const schema_fields_ICON = 'icon';
    #[Col('int', 0, nullable: false, comment: '排序')]
    public const schema_fields_ORDER = 'order';
    #[Col('int', 1, default: 0, comment: '是否系统菜单')]
    public const schema_fields_IS_SYSTEM = 'is_system';
    #[Col('int', 1, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ENABLE = 'is_enable';
    #[Col('int', 1, default: 1, comment: '是否后台菜单')]
    public const schema_fields_IS_BACKEND = 'is_backend';
    public array $_unit_primary_keys = [self::schema_fields_ID, self::schema_fields_SOURCE];
    public array $_index_sort_keys = ['menu_id', 'source'];
    private Url $url;
    public function __init()
    {
        parent::__init();
        if (!isset($this->url)) {
            $this->url = ObjectManager::getInstance(Url::class);
        }
    }
    public function getName(): string
    {
        return parent::getData(self::schema_fields_NAME) ?? '';
    }
    public function setName(string $name): static
    {
        return parent::setData(self::schema_fields_NAME, $name);
    }
    public function getLevel(): int
    {
        return parent::getData(self::schema_fields_LEVEL) ?? 0;
    }
    public function setLevel(int $level): static
    {
        return parent::setData(self::schema_fields_LEVEL, $level);
    }
    public function getPath(): string
    {
        return parent::getData(self::schema_fields_PATH) ?? '';
    }
    public function setPath(string $path): static
    {
        return parent::setData(self::schema_fields_PATH, $path);
    }
    public function getPid()
    {
        return parent::getData(self::schema_fields_PID);
    }
    public function setPid(string $pid): static
    {
        return parent::setData(self::schema_fields_NAME, $pid);
    }
    public function getSource(): string
    {
        return parent::getData(self::schema_fields_SOURCE) ?? '';
    }
    public function setSource(string $source): static
    {
        return parent::setData(self::schema_fields_SOURCE, $source);
    }
    public function getParentSource(): string
    {
        return parent::getData(self::schema_fields_PARENT_SOURCE) ?? '';
    }
    public function setParentSource(string $source): static
    {
        return parent::setData(self::schema_fields_PARENT_SOURCE, $source);
    }
    public function getAction(): string
    {
        return parent::getData(self::schema_fields_ACTION) ?? '';
    }
    public function setAction(string $url): static
    {
        return parent::setData(self::schema_fields_ACTION, $url);
    }
    public function getIcon(): string
    {
        return parent::getData(self::schema_fields_ICON) ?? '';
    }
    public function setIcon(string $css_icon_class): static
    {
        return parent::setData(self::schema_fields_ICON, $css_icon_class);
    }
    public function getTitle(): string
    {
        return parent::getData(self::schema_fields_TITLE) ?? '';
    }
    public function setTitle(string $title): static
    {
        return parent::setData(self::schema_fields_ICON, $title);
    }
    public function getOrder(): int
    {
        return intval(parent::getData(self::schema_fields_ORDER));
    }
    public function setOrder(int $order): static
    {
        return parent::setData(self::schema_fields_ORDER, $order);
    }
    public function getModule(): string
    {
        return $this->getData(self::schema_fields_MODULE) ?? '';
    }
    public function setModule(string $module_name): static
    {
        return $this->setData(self::schema_fields_MODULE, $module_name);
    }
    public function isSystem(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_SYSTEM);
    }
    public function setIsSystem(bool $is_system): static
    {
        return $this->setData(self::schema_fields_IS_SYSTEM, $is_system);
    }
    public function isEnable(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_ENABLE);
    }
    public function setIsEnable(bool $is_enable): static
    {
        return $this->setData(self::schema_fields_IS_ENABLE, $is_enable);
    }
    public function isBackend(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_BACKEND);
    }
    public function setIsBackend(bool $is_backend): static
    {
        return $this->setData(self::schema_fields_IS_BACKEND, $is_backend);
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
        // WLS 兼容：每次使用新 Acl 实例，避免共享实例残留上一请求的 where/select 导致菜单不全
        $aclModel = ObjectManager::getInstance(Acl::class, [], false);
        $menuSources = $aclModel->reset()
            ->where(Acl::schema_fields_TYPE, 'menus')
            ->where(Acl::schema_fields_IS_ENABLE, 1)
            ->select()
            ->fetchArray();
        $menuSourceIds = array_column($menuSources, Acl::schema_fields_SOURCE_ID);
        
        if (empty($menuSourceIds)) {
            return [];
        }
        
        if ($role->getId() !== 1) {
            $roleAccessSources = $this->getRoleAccessSources($role);
            // 只保留角色有权限且是菜单类型的 source
            $allowedSources = array_intersect($roleAccessSources, $menuSourceIds);
            if (empty($allowedSources)) {
                return [];
            }
            // 把菜单树所需祖先 source_id 一并加入，否则 getTree 只查 selected 会导致父节点缺失、只渲染出少量菜单
            $allowedSources = $this->expandAllowedSourcesWithAncestors($menuSources, $allowedSources);
            $aclTree = ObjectManager::getInstance(Acl::class, [], false)
                ->getTree(
                    Acl::schema_fields_PARENT_SOURCE,
                    '',
                    Acl::schema_fields_ORDER,
                    'asc',
                    Acl::schema_fields_SOURCE_ID,
                    $allowedSources,
                    'source_name'
                );
        } else {
            $aclTree = ObjectManager::getInstance(Acl::class, [], false)
                ->getTree(
                    Acl::schema_fields_PARENT_SOURCE,
                    '',
                    Acl::schema_fields_ORDER,
                    'asc',
                    Acl::schema_fields_SOURCE_ID,
                    $menuSourceIds,
                    'source_name'
                );
        }
        
        // 过滤结果，确保只返回菜单类型的记录（双重保险）
        return $this->filterMenuTree($aclTree);
    }
    
    /**
     * 过滤菜单树，只保留 type='menus' 的记录
     * 
     * @param array $tree
     * @return array
     */
    private function filterMenuTree(array $tree): array
    {
        $filtered = [];
        foreach ($tree as $node) {
            $type = $node['type'] ?? $node[Acl::schema_fields_TYPE] ?? null;
            if ($type === 'menus' || $type === Acl::type_MENUS) {
                // 递归过滤子节点
                if (isset($node['nodes']) && is_array($node['nodes'])) {
                    $node['nodes'] = $this->filterMenuTree($node['nodes']);
                }
                $filtered[] = $node;
            }
        }
        return $filtered;
    }
    /**
     * 将 allowedSources 扩展为包含所有祖先 source_id，以便 getTree 能构建完整层级（否则只会有叶子、菜单只显示几条）
     *
     * @param array $menuSources 所有 type=menus 的 ACL 行（含 source_id、parent_source）
     * @param array $allowedSources 角色有权限的菜单 source_id 列表
     * @return array 扩展后的 source_id 列表（含祖先）
     */
    protected function expandAllowedSourcesWithAncestors(array $menuSources, array $allowedSources): array
    {
        $parentMap = [];
        foreach ($menuSources as $row) {
            $sid = $row[Acl::schema_fields_SOURCE_ID] ?? '';
            $pid = $row[Acl::schema_fields_PARENT_SOURCE] ?? '';
            if ($sid !== '' && $pid !== '') {
                $parentMap[$sid] = $pid;
            }
        }
        $expanded = array_flip($allowedSources);
        foreach ($allowedSources as $sid) {
            $current = $parentMap[$sid] ?? '';
            while ($current !== '' && $current !== null) {
                $expanded[$current] = true;
                $current = $parentMap[$current] ?? '';
            }
        }
        return array_keys($expanded);
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
        // WLS 兼容：使用新实例查询，避免共享 RoleAccess 残留上一请求条件导致 demo 等角色菜单不全
        /** @var RoleAccess $roleSourceModel */
        $roleSourceModel = ObjectManager::getInstance(RoleAccess::class, [], false);
        $roleAccess = $roleSourceModel->clear()
            ->where(RoleAccess::schema_fields_ROLE_ID, $role->getId(0))
            ->select()
            ->fetchArray();
        $roleAccessSources = [];
        foreach ($roleAccess as $row) {
            $roleAccessSources[] = $row[RoleAccess::schema_fields_SOURCE_ID];
        }
        return $roleAccessSources;
    }
}
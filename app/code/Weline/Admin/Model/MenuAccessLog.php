<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Model;

use Weline\Acl\Model\Acl;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class MenuAccessLog extends Model
{
    public const fields_ID = 'menu_access_log_id';
    public const fields_menu_access_log_id = 'menu_access_log_id';
    public const fields_user_id = 'user_id';
    public const fields_source_id = 'source_id';
    public const fields_route = 'route';
    public const fields_method = 'method';
    public const fields_access_time = 'access_time';

    public array $_unit_primary_keys = [self::fields_ID];

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
        if (!$setup->tableExist()) {
            $setup->createTable('菜单访问记录表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', '访问记录ID')
                ->addColumn(self::fields_user_id, TableInterface::column_type_INTEGER, 11, 'not null', '用户ID')
                ->addColumn(self::fields_source_id, TableInterface::column_type_VARCHAR, 127, 'not null', '菜单资源ID')
                ->addColumn(self::fields_route, TableInterface::column_type_VARCHAR, 255, 'not null', '路由路径')
                ->addColumn(self::fields_method, TableInterface::column_type_VARCHAR, 6, 'default ""', '请求方法')
                ->addColumn(self::fields_access_time, TableInterface::column_type_INTEGER, 11, 'not null', '访问时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_user_id', self::fields_user_id, '用户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_source_id', self::fields_source_id, '资源ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_access_time', self::fields_access_time, '访问时间索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_user_source', [self::fields_user_id, self::fields_source_id], '用户资源复合索引')
                ->create();
        }
    }

    /**
     * 设置用户ID
     */
    public function setUserId(int $userId): static
    {
        return $this->setData(self::fields_user_id, $userId);
    }

    /**
     * 获取用户ID
     */
    public function getUserId(): int
    {
        return intval($this->getData(self::fields_user_id));
    }

    /**
     * 设置资源ID
     */
    public function setSourceId(string $sourceId): static
    {
        return $this->setData(self::fields_source_id, $sourceId);
    }

    /**
     * 获取资源ID
     */
    public function getSourceId(): string
    {
        return $this->getData(self::fields_source_id) ?? '';
    }

    /**
     * 设置路由
     */
    public function setRoute(string $route): static
    {
        return $this->setData(self::fields_route, $route);
    }

    /**
     * 获取路由
     */
    public function getRoute(): string
    {
        return $this->getData(self::fields_route) ?? '';
    }

    /**
     * 设置请求方法
     */
    public function setMethod(string $method): static
    {
        return $this->setData(self::fields_method, $method);
    }

    /**
     * 获取请求方法
     */
    public function getMethod(): string
    {
        return $this->getData(self::fields_method) ?? '';
    }

    /**
     * 设置访问时间
     */
    public function setAccessTime(int $accessTime): static
    {
        return $this->setData(self::fields_access_time, $accessTime);
    }

    /**
     * 获取访问时间
     */
    public function getAccessTime(): int
    {
        return intval($this->getData(self::fields_access_time));
    }

    /**
     * 获取用户常用菜单
     *
     * @param int $userId 用户ID
     * @param int $limit 返回数量限制
     * @param int $days 统计天数（默认7天）
     * @return array 常用菜单列表，包含source_id和访问次数
     */
    public function getFrequentlyUsedMenus(int $userId, int $limit = 50, int $days = 7): array
    {
        // 检查表是否存在，如果不存在则返回空数组
        try {
            if (!$this->getConnection()->getConnector()->tableExist($this->getTable())) {
                return [];
            }
        } catch (\Exception $e) {
            return [];
        }
        
        // 尝试从缓存中获取（缓存5分钟）
        $cacheKey = 'frequent_menus_' . $userId . '_' . $limit . '_' . $days;
        /** @var \Weline\Admin\Cache\AdminCache $cache */
        $cache = ObjectManager::getInstance(\Weline\Admin\Cache\AdminCache::class);
        $cacheDriver = $cache->create();
        $cachedResult = $cacheDriver->get($cacheKey, false);
        if ($cachedResult !== false) {
            return $cachedResult;
        }
        
        $startTime = time() - ($days * 24 * 60 * 60);
        
        // 查询最近N天的访问记录，按source_id分组统计访问次数
        try {
            $results = $this->clearData()
                ->fields(self::fields_source_id . ', COUNT(*) as access_count')
                ->where(self::fields_user_id, $userId)
                ->where(self::fields_access_time, $startTime, '>=')
                ->group(self::fields_source_id)
                ->order('access_count', 'DESC')
                ->limit($limit * 2) // 多查询一些，因为后续可能会过滤掉一些无权限的
                ->select()
                ->fetchArray();
            
            // 如果查询失败，返回空数组
            if (!is_array($results)) {
                return [];
            }
        } catch (\Exception $e) {
            // 查询失败时返回空数组，避免影响页面加载
            return [];
        }

        // 验证这些source_id对应的ACL资源是否还存在且类型为menus，并检查用户权限
        /** @var Acl $aclModel */
        $aclModel = ObjectManager::getInstance(Acl::class);
        
        // 获取用户角色和权限
        /** @var \Weline\Backend\Model\BackendUser $userModel */
        $userModel = ObjectManager::getInstance(\Weline\Backend\Model\BackendUser::class);
        $user = $userModel->load($userId);
        if (!$user->getId()) {
            return [];
        }
        
        $role = $user->getRoleModel();
        $frequentMenus = [];
        
        // 获取用户有权限访问的source_id列表
        $userAccessSources = [];
        if ($role->getId() !== 1) {
            // 非超级管理员需要检查权限
            /** @var \Weline\Acl\Model\RoleAccess $roleAccessModel */
            $roleAccessModel = ObjectManager::getInstance(\Weline\Acl\Model\RoleAccess::class);
            $roleAccess = $roleAccessModel->where(\Weline\Acl\Model\RoleAccess::fields_ROLE_ID, $role->getId())
                ->select()
                ->fetchArray();
            foreach ($roleAccess as $access) {
                $userAccessSources[] = $access[\Weline\Acl\Model\RoleAccess::fields_SOURCE_ID];
            }
        }
        
        foreach ($results as $result) {
            if (count($frequentMenus) >= $limit) {
                break;
            }
            
            $sourceId = $result[self::fields_source_id];
            
            // 检查权限（超级管理员跳过权限检查）
            if ($role->getId() !== 1 && !in_array($sourceId, $userAccessSources)) {
                continue;
            }
            
            // 验证ACL资源是否存在且类型为menus
            $acl = $aclModel->clearData()
                ->where(Acl::fields_SOURCE_ID, $sourceId)
                ->where(Acl::fields_TYPE, Acl::type_MENUS)
                ->where(Acl::fields_IS_ENABLE, 1)
                ->find()
                ->fetch();
            
            if ($acl->getId()) {
                $frequentMenus[] = [
                    'source_id' => $sourceId,
                    'access_count' => intval($result['access_count']),
                    'acl_data' => $acl->getData()
                ];
            }
        }
        
        // 缓存结果（5分钟）
        $cacheDriver->set($cacheKey, $frequentMenus, 300);
        
        return $frequentMenus;
    }

    /**
     * 获取用户最近访问的菜单
     *
     * @param int $userId 用户ID
     * @param int $limit 返回数量限制
     * @param int $days 统计天数（默认7天）
     * @return array 最近访问的菜单列表，包含source_id和最后访问时间
     */
    public function getRecentMenus(int $userId, int $limit = 20, int $days = 7): array
    {
        // 检查表是否存在，如果不存在则返回空数组
        try {
            if (!$this->getConnection()->getConnector()->tableExist($this->getTable())) {
                return [];
            }
        } catch (\Exception $e) {
            return [];
        }
        
        // 尝试从缓存中获取（缓存5分钟）
        $cacheKey = 'recent_menus_' . $userId . '_' . $limit . '_' . $days;
        /** @var \Weline\Admin\Cache\AdminCache $cache */
        $cache = ObjectManager::getInstance(\Weline\Admin\Cache\AdminCache::class);
        $cacheDriver = $cache->create();
        $cachedResult = $cacheDriver->get($cacheKey, false);
        if ($cachedResult !== false) {
            return $cachedResult;
        }
        
        $startTime = time() - ($days * 24 * 60 * 60);
        
        // 查询最近N天的访问记录，按source_id分组，取最新的访问时间
        try {
            $results = $this->clearData()
                ->fields(self::fields_source_id . ', MAX(' . self::fields_access_time . ') as last_access_time')
                ->where(self::fields_user_id, $userId)
                ->where(self::fields_access_time, $startTime, '>=')
                ->group(self::fields_source_id)
                ->order('last_access_time', 'DESC')
                ->limit($limit * 2) // 多查询一些，因为后续可能会过滤掉一些无权限的
                ->select()
                ->fetchArray();
            
            // 如果查询失败，返回空数组
            if (!is_array($results)) {
                return [];
            }
        } catch (\Exception $e) {
            // 查询失败时返回空数组，避免影响页面加载
            return [];
        }

        // 验证这些source_id对应的ACL资源是否还存在且类型为menus，并检查用户权限
        /** @var Acl $aclModel */
        $aclModel = ObjectManager::getInstance(Acl::class);
        
        // 获取用户角色和权限
        /** @var \Weline\Backend\Model\BackendUser $userModel */
        $userModel = ObjectManager::getInstance(\Weline\Backend\Model\BackendUser::class);
        $user = $userModel->load($userId);
        if (!$user->getId()) {
            return [];
        }
        
        $role = $user->getRoleModel();
        $recentMenus = [];
        
        // 获取用户有权限访问的source_id列表
        $userAccessSources = [];
        if ($role->getId() !== 1) {
            // 非超级管理员需要检查权限
            /** @var \Weline\Acl\Model\RoleAccess $roleAccessModel */
            $roleAccessModel = ObjectManager::getInstance(\Weline\Acl\Model\RoleAccess::class);
            $roleAccess = $roleAccessModel->where(\Weline\Acl\Model\RoleAccess::fields_ROLE_ID, $role->getId())
                ->select()
                ->fetchArray();
            foreach ($roleAccess as $access) {
                $userAccessSources[] = $access[\Weline\Acl\Model\RoleAccess::fields_SOURCE_ID];
            }
        }
        
        foreach ($results as $result) {
            if (count($recentMenus) >= $limit) {
                break;
            }
            
            $sourceId = $result[self::fields_source_id];
            
            // 检查权限（超级管理员跳过权限检查）
            if ($role->getId() !== 1 && !in_array($sourceId, $userAccessSources)) {
                continue;
            }
            
            // 验证ACL资源是否存在且类型为menus
            $acl = $aclModel->clearData()
                ->where(Acl::fields_SOURCE_ID, $sourceId)
                ->where(Acl::fields_TYPE, Acl::type_MENUS)
                ->where(Acl::fields_IS_ENABLE, 1)
                ->find()
                ->fetch();
            
            if ($acl->getId()) {
                $recentMenus[] = [
                    'source_id' => $sourceId,
                    'last_access_time' => intval($result['last_access_time']),
                    'acl_data' => $acl->getData()
                ];
            }
        }
        
        // 缓存结果（5分钟）
        $cacheDriver->set($cacheKey, $recentMenus, 300);
        
        return $recentMenus;
    }
}


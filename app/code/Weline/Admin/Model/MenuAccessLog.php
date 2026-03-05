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
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
#[Table(comment: '菜单访问记录表')]
#[Index(name: 'idx_user_id', columns: ['user_id'], comment: '用户ID索引')]
#[Index(name: 'idx_source_id', columns: ['source_id'], comment: '资源ID索引')]
#[Index(name: 'idx_access_time', columns: ['access_time'], comment: '访问时间索引')]
#[Index(name: 'idx_user_source', columns: ['user_id', 'source_id'], comment: '用户资源复合索引')]
class MenuAccessLog extends Model
{
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '访问记录ID')]
    public const schema_fields_ID = 'menu_access_log_id';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '访问记录ID')]
    public const schema_fields_menu_access_log_id = 'menu_access_log_id';
    #[Col(type: 'int', nullable: false, comment: '用户ID')]
    public const schema_fields_user_id = 'user_id';
    #[Col(type: 'varchar', length: 127, nullable: false, comment: '菜单资源ID')]
    public const schema_fields_source_id = 'source_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '路由路径')]
    public const schema_fields_route = 'route';
    #[Col(type: 'varchar', length: 6, nullable: true, default: '', comment: '请求方法')]
    public const schema_fields_method = 'method';
    #[Col(type: 'int', nullable: false, comment: '访问时间')]
    public const schema_fields_access_time = 'access_time';

    public array $_unit_primary_keys = [self::schema_fields_ID];

    /**
     * 设置用户ID
     */
    public function setUserId(int $userId): static
    {
        return $this->setData(self::schema_fields_user_id, $userId);
    }

    /**
     * 获取用户ID
     */
    public function getUserId(): int
    {
        return intval($this->getData(self::schema_fields_user_id));
    }

    /**
     * 设置资源ID
     */
    public function setSourceId(string $sourceId): static
    {
        return $this->setData(self::schema_fields_source_id, $sourceId);
    }

    /**
     * 获取资源ID
     */
    public function getSourceId(): string
    {
        return $this->getData(self::schema_fields_source_id) ?? '';
    }

    /**
     * 设置路由
     */
    public function setRoute(string $route): static
    {
        return $this->setData(self::schema_fields_route, $route);
    }

    /**
     * 获取路由
     */
    public function getRoute(): string
    {
        return $this->getData(self::schema_fields_route) ?? '';
    }

    /**
     * 设置请求方法
     */
    public function setMethod(string $method): static
    {
        return $this->setData(self::schema_fields_method, $method);
    }

    /**
     * 获取请求方法
     */
    public function getMethod(): string
    {
        return $this->getData(self::schema_fields_method) ?? '';
    }

    /**
     * 设置访问时间
     */
    public function setAccessTime(int $accessTime): static
    {
        return $this->setData(self::schema_fields_access_time, $accessTime);
    }

    /**
     * 获取访问时间
     */
    public function getAccessTime(): int
    {
        return intval($this->getData(self::schema_fields_access_time));
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
        $cache = w_cache('default');
        $cachedResult = $cache->get($cacheKey, false);
        if ($cachedResult !== false && is_array($cachedResult)) {
            return $cachedResult;
        }
        
        $startTime = time() - ($days * 24 * 60 * 60);
        
        // 查询最近N天的访问记录，按source_id分组统计访问次数
        try {
            $results = $this->clearData()
                ->fields(self::schema_fields_source_id . ', COUNT(*) as access_count')
                ->where(self::schema_fields_user_id, $userId)
                ->where(self::schema_fields_access_time, $startTime, '>=')
                ->group(self::schema_fields_source_id)
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
            $roleAccess = $roleAccessModel->where(\Weline\Acl\Model\RoleAccess::schema_fields_ROLE_ID, $role->getId())
                ->select()
                ->fetchArray();
            if (is_array($roleAccess)) {
                foreach ($roleAccess as $access) {
                    $userAccessSources[] = $access[\Weline\Acl\Model\RoleAccess::schema_fields_SOURCE_ID];
                }
            }
        }
        
        foreach ($results as $result) {
            if (count($frequentMenus) >= $limit) {
                break;
            }
            
            $sourceId = $result[self::schema_fields_source_id];
            
            // 检查权限（超级管理员跳过权限检查）
            if ($role->getId() !== 1 && !in_array($sourceId, $userAccessSources)) {
                continue;
            }
            
            // 验证ACL资源是否存在且类型为menus
            $acl = $aclModel->clearData()
                ->where(Acl::schema_fields_SOURCE_ID, $sourceId)
                ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
                ->where(Acl::schema_fields_IS_ENABLE, 1)
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
        $cache->set($cacheKey, $frequentMenus, 300);
        
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
        $cache = w_cache('default');
        $cachedResult = $cache->get($cacheKey, false);
        if ($cachedResult !== false && is_array($cachedResult)) {
            return $cachedResult;
        }
        
        $startTime = time() - ($days * 24 * 60 * 60);
        
        // 查询最近N天的访问记录，按source_id分组，取最新的访问时间
        try {
            $results = $this->clearData()
                ->fields(self::schema_fields_source_id . ', MAX(' . self::schema_fields_access_time . ') as last_access_time')
                ->where(self::schema_fields_user_id, $userId)
                ->where(self::schema_fields_access_time, $startTime, '>=')
                ->group(self::schema_fields_source_id)
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
            $roleAccess = $roleAccessModel->where(\Weline\Acl\Model\RoleAccess::schema_fields_ROLE_ID, $role->getId())
                ->select()
                ->fetchArray();
            if (is_array($roleAccess)) {
                foreach ($roleAccess as $access) {
                    $userAccessSources[] = $access[\Weline\Acl\Model\RoleAccess::schema_fields_SOURCE_ID];
                }
            }
        }
        
        foreach ($results as $result) {
            if (count($recentMenus) >= $limit) {
                break;
            }
            
            $sourceId = $result[self::schema_fields_source_id];
            
            // 检查权限（超级管理员跳过权限检查）
            if ($role->getId() !== 1 && !in_array($sourceId, $userAccessSources)) {
                continue;
            }
            
            // 验证ACL资源是否存在且类型为menus
            $acl = $aclModel->clearData()
                ->where(Acl::schema_fields_SOURCE_ID, $sourceId)
                ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
                ->where(Acl::schema_fields_IS_ENABLE, 1)
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
        $cache->set($cacheKey, $recentMenus, 300);
        
        return $recentMenus;
    }
}


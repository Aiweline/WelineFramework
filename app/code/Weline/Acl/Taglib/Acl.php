<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：19/2/2024 17:09:03
 */

namespace Weline\Acl\Taglib;

use Weline\Acl\Model\Role;
use Weline\Acl\Model\RoleAccess;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;
use Weline\Framework\Taglib\TaglibInterface;

class Acl implements TaglibInterface
{
    /**
     * 防止权限检查重入的标志
     * WLS 注意：请求级状态，已注册 StateManager 重置
     */
    private static bool $checkingPermission = false;
    
    /**
     * WLS 注意：以下静态缓存变量是请求级状态，必须在 StateManager 中注册重置。
     * 这些变量在 WLS 常驻进程模式下会跨请求保留，导致：
     * - $cachedRequest 指向旧请求对象
     * - $cachedSession 指向旧 Session，可能是不同用户
     * - 权限检查使用错误的用户身份
     * 
     * 解决方案：不再使用静态缓存，每次调用时从 ObjectManager 获取最新实例
     */

    /**
     * @inheritDoc
     */
    static public function name(): string
    {
        return 'acl';
    }

    /**
     * @inheritDoc
     */
    static function tag(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    static function attr(): array
    {
        return ['source' => true];
    }

    /**
     * @inheritDoc
     */
    static function tag_start(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    static function tag_end(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $source = $attributes['source'] ?? '';
            if (empty($source)) {
                throw new \Exception(__('acl标签缺少source属性'));
            }
            
            // 获取标签内部内容
            $content = $tag_data[2] ?? '';
            
            // 运行时权限检查（不再递归编译）
            if (!self::hasPermission($source)) {
                return '<!-- 无权限访问: ' . htmlspecialchars($source) . ' -->';
            }
            
            return $content;
        };
    }
    
    /**
     * 请求内权限缓存
     * WLS 注意：这是请求级缓存，已在 StateManager 中注册重置
     */
    private static array $permissionCache = [];
    
    /**
     * 重置请求级状态
     * 由 StateManager 在每次请求结束时调用
     */
    public static function resetRequestState(): void
    {
        self::$checkingPermission = false;
        self::$permissionCache = [];
    }
    
    /**
     * 运行时权限检查方法（返回 bool）
     * @param string $source 权限源标识
     * @return bool
     */
    public static function hasPermission(string $source): bool
    {
        // 请求内缓存：避免同一请求内重复检查同一权限
        if (isset(self::$permissionCache[$source])) {
            return self::$permissionCache[$source];
        }
        
        // WLS 修复：每次从 SessionFactory 获取最新的 Session 实例
        // 不能使用静态缓存，否则会跨请求保留旧用户的 Session
        $request = ObjectManager::getInstance(Request::class);
        $session = $request->isBackend() 
            ? SessionFactory::getInstance()->createBackendSession()
            : SessionFactory::getInstance()->createFrontendSession();
        
        // 获取对应用户和角色
        $user = $session->getUser();
        if (!$user || !\method_exists($user, 'getRole')) {
            self::$permissionCache[$source] = false;
            return false;
        }
        // WLS 兼容：按当前用户的 role_id 重新加载 Role，避免线上/多 Worker 下复用错误角色导致权限不一致
        $roleId = (int) ($user->getRole()->getRoleId() ?: 0);
        if ($roleId <= 0) {
            self::$permissionCache[$source] = false;
            return false;
        }
        $role = ObjectManager::getInstance(Role::class, [], false)->load($roleId);
        
        // 超级管理员直接返回 true
        if ($role->getId() === 1) {
            self::$permissionCache[$source] = true;
            return true;
        }
        
        // 无角色返回 false
        if (empty($role->getId())) {
            $msg = __('该页面部分资源引用了权限设置，但是您当前没有权限:无法访问 %{1} 资源,如有需求请联系管理员！', $source);
            /**@var MessageManager $messageManager */
            $messageManager = ObjectManager::getInstance(MessageManager::class);
            $messageManager->addWarning($msg);
            self::$permissionCache[$source] = false;
            return false;
        }
        
        // 获取权限列表（使用文件缓存，不受 WLS 影响）
        $cache = w_cache('acl');
        $cacheKey = 'acl_' . $role->getId() . '_source';
        $accesses = $cache->get($cacheKey);
        
        if (!$accesses) {
            /**@var RoleAccess $roleAccess */
            $roleAccess = ObjectManager::getInstance(RoleAccess::class);
            $accesses = $roleAccess->getRoleAccessListArray($role);
            foreach ($accesses as &$access) {
                $access = $access['source_id'];
            }
            $cache->set($cacheKey, $accesses);
        }
        
        // 检查权限
        $hasAccess = in_array($source, $accesses);
        if (!$hasAccess) {
            $msg = __('该页面部分资源引用了权限设置，但是您当前没有权限:无法访问 %{1} 资源,如有需求请联系管理员！', $source);
            /**@var MessageManager $messageManager */
            $messageManager = ObjectManager::getInstance(MessageManager::class);
            $messageManager->addWarning($msg);
        }
        
        self::$permissionCache[$source] = $hasAccess;
        return $hasAccess;
    }

    /**
     * @inheritDoc
     */
    static function tag_self_close(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    /**
     * 指定父标签，用于依赖管理
     * @return string|null 父标签名称
     */
    static function parent(): ?string
    {
        return null; // Acl标签没有依赖
    }

    static function document(): string
    {
        $msg = __('这里是重要信息，只允许拥有Weline_Acl::acl权限的用户访问');
        $tag = __('使用示例：') . htmlentities('<acl source="Weline_Acl::acl">
    <div>
        <span>' . $msg . '</span>
    </div>
</acl>');
        return $tag;
    }
}

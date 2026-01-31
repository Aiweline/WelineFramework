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

use Weline\Acl\Cache\AclCache;
use Weline\Acl\Model\RoleAccess;
use Weline\Backend\Model\BackendUser;
use Weline\Backend\Session\BackendSession;
use Weline\Framework\App\Session\FrontendSession;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;
use Weline\Frontend\Model\FrontendUser;
use Weline\Taglib\TaglibInterface;

class Acl implements TaglibInterface
{
    /**
     * 防止权限检查重入的标志
     */
    private static bool $checkingPermission = false;
    
    /**
     * 缓存的 Request 实例，避免重复获取
     */
    private static ?Request $cachedRequest = null;
    
    /**
     * 缓存的 Session 实例
     */
    private static $cachedSession = null;
    
    /**
     * 编译时结果缓存，防止同一个标签被递归编译
     * Key: md5(source + tag_key + tag_data_hash)
     */
    private static array $compileCache = [];
    
    /**
     * 当前正在编译的标签哈希集合，用于检测递归
     */
    private static array $compilingTags = [];

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
     * 运行时权限检查方法（返回 bool）
     * @param string $source 权限源标识
     * @return bool
     */
    public static function hasPermission(string $source): bool
    {
        // 使用静态缓存避免重复检查
        static $permissionCache = [];
        if (isset($permissionCache[$source])) {
            return $permissionCache[$source];
        }
        
        // 使用缓存的 Request 实例
        if (self::$cachedRequest === null) {
            self::$cachedRequest = ObjectManager::getInstance(Request::class);
        }
        $request = self::$cachedRequest;
        
        // 获取 session
        if (self::$cachedSession === null) {
            self::$cachedSession = ObjectManager::getInstance(
                $request->isBackend() ? BackendSession::class : FrontendSession::class
            );
        }
        $session = self::$cachedSession;
        
        // 获取对应用户和角色
        $user = $session->getLoginUser();
        $role = $user->getRoleModel();
        
        // 超级管理员直接返回 true
        if ($role->getId() === 1) {
            $permissionCache[$source] = true;
            return true;
        }
        
        // 无角色返回 false
        if (empty($role->getId())) {
            $msg = __('该页面部分资源引用了权限设置，但是您当前没有权限:无法访问 %{1} 资源,如有需求请联系管理员！', $source);
            /**@var MessageManager $messageManager */
            $messageManager = ObjectManager::getInstance(MessageManager::class);
            $messageManager->addWarning($msg);
            $permissionCache[$source] = false;
            return false;
        }
        
        // 获取权限列表（使用缓存）
        /**@var CacheInterface $cache */
        $cache = ObjectManager::getInstance(AclCache::class . 'Factory');
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
        
        $permissionCache[$source] = $hasAccess;
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
        $msg = __('这里是重要信息，只允许拥有Weline_Backend::setting权限的用户访问');
        $tag = __('使用示例：') . htmlentities('<acl source="Weline_Backend::setting">
    <div>
        <span>' . $msg . '</span>
    </div>
</acl>');
        return $tag;
    }
}
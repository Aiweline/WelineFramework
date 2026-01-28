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
            
            // 生成唯一标签哈希，用于检测递归编译
            $tagHash = md5($source . '|' . $tag_key . '|' . serialize($tag_data));
            
            // #region agent log
            static $aclCallCount = 0;
            $aclCallCount++;
            if ($aclCallCount <= 10) {
                @file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['timestamp'=>microtime(true),'location'=>'Acl.php:callback','message'=>'ACL Taglib ENTRY','data'=>['source'=>$source,'tagHash'=>substr($tagHash,0,8),'callCount'=>$aclCallCount,'inCompiling'=>isset(self::$compilingTags[$tagHash]),'hasCached'=>isset(self::$compileCache[$tagHash])],'hypothesisId'=>'B','sessionId'=>'debug-session'])."\n", FILE_APPEND);
            }
            // #endregion
            
            // 检测递归编译：如果同一个标签正在编译中，直接返回占位符
            if (isset(self::$compilingTags[$tagHash])) {
                return '<!-- acl:' . $source . ' (递归编译保护) -->';
            }
            
            // 检查编译缓存：如果已经编译过，直接返回缓存结果
            if (isset(self::$compileCache[$tagHash])) {
                return self::$compileCache[$tagHash];
            }
            
            // 标记当前标签正在编译
            self::$compilingTags[$tagHash] = true;
            
            try {
                // 使用缓存的 Request 实例，避免重复获取触发循环
                if (self::$cachedRequest === null) {
                    self::$cachedRequest = ObjectManager::getInstance(Request::class);
                }
                $request = self::$cachedRequest;
                
                // 获取 session，使用缓存避免重复实例化
                if (self::$cachedSession === null) {
                    /**@var BackendSession|FrontendSession $session */
                    self::$cachedSession = ObjectManager::getInstance(
                        $request->isBackend() ? BackendSession::class : FrontendSession::class
                    );
                }
                $session = self::$cachedSession;
                
                // 获取对应用户
                $user = $session->getLoginUser();
                // 角色
                $role = $user->getRoleModel();
                if ($role->getId() === 1) {
                    $result = $tag_data[2] ?? '';
                    self::$compileCache[$tagHash] = $result;
                    return $result;
                }
                /**@var CacheInterface $cache */
                $cache = ObjectManager::getInstance(AclCache::class . 'Factory');
                $cacheKey = 'acl_' . $role->getId() . '_source';
                $accesses = $cache->get($cacheKey);
                if (!$accesses) {
                    if (empty($role->getId())) {
                        /**@var MessageManager $messageManager */
                        $messageManager = ObjectManager::getInstance(MessageManager::class);
                        $msg = __('该页面部分资源引用了权限设置，但是您当前没有权限:无法访问 %{1} 资源,如有需求请联系管理员！', $source);
                        $messageManager->addWarning($msg);
                        $result = '<!-- ' . $msg . ' 资源 -->';
                        self::$compileCache[$tagHash] = $result;
                        return $result;
                    }
                    // 检查权限资源
                    /**@var RoleAccess $roleAccess */
                    $roleAccess = ObjectManager::getInstance(RoleAccess::class);
                    $accesses = $roleAccess->getRoleAccessListArray($role);
                    foreach ($accesses as &$access) {
                        $access = $access['source_id'];
                    }
                    $cache->set($cacheKey, $accesses);
                }
                if (!in_array($source, $accesses)) {
                    /**@var MessageManager $messageManager */
                    $messageManager = ObjectManager::getInstance(MessageManager::class);
                    $msg = __('该页面部分资源引用了权限设置，但是您当前没有权限:无法访问 %{1} 资源,如有需求请联系管理员！', $source);
                    $messageManager->addWarning($msg);
                    $result = '<!-- ' . $msg . ' 资源 -->';
                    self::$compileCache[$tagHash] = $result;
                    return $result;
                }
                if (DEV) {
                    $result = '<!-- -----开发环境显示acl标签---------START -->' . ($tag_data[0] ?? '') . PHP_EOL . '<!-- -----开发环境显示acl标签---------END -->';
                } else {
                    $result = $tag_data[2] ?? '';
                }
                self::$compileCache[$tagHash] = $result;
                return $result;
            } finally {
                // 移除编译中标记
                unset(self::$compilingTags[$tagHash]);
            }
        };
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
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
            // 判断当前前后后台环境
            /**@var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            /**@var BackendSession|FrontendSession $session */
            $session = ObjectManager::getInstance($request->isBackend() ? BackendSession::class : FrontendSession::class);
            // 获取对应用户
            $user = $session->getLoginUser();
            // 角色
            $role = $user->getRoleModel();
            if($role->getId()===1){
                return $tag_data[2] ?? '';
            }
            /**@var CacheInterface $cache */
            $cache = ObjectManager::getInstance(AclCache::class.'Factory');
            $cacheKey = 'acl_' . $role->getId().'_source';
            $accesses = $cache->get($cacheKey);
            if(!$accesses){
                if (empty($role->getId())) {
                    /**@var MessageManager $messageManager */
                    $messageManager = ObjectManager::getInstance(MessageManager::class);
                    $msg = __('该页面部分资源引用了权限设置，但是您当前没有权限:无法访问 %1 资源,如有需求请联系管理员！',$source);
                    $messageManager->addWarning($msg);
                    return '<!-- ' . $msg . ' 资源 -->';
                }
                // 检查权限资源
                /**@var RoleAccess $roleAccess */
                $roleAccess = ObjectManager::getInstance(RoleAccess::class);
                $accesses   = $roleAccess->getRoleAccessListArray($role);
                foreach ($accesses as &$access) {
                    $access = $access['source_id'];
                }
                $cache->set($cacheKey, $accesses);
            }

            if (!in_array($source, $accesses)) {
                /**@var MessageManager $messageManager */
                $messageManager = ObjectManager::getInstance(MessageManager::class);
                $msg = __('该页面部分资源引用了权限设置，但是您当前没有权限:无法访问 %1 资源,如有需求请联系管理员！',$source);
                $messageManager->addWarning($msg);
                return '<!-- ' . $msg . ' 资源 -->';
            }
            if (DEV) {
                return '<!-- -----开发环境显示acl标签---------START -->' . ($tag_data[0] ?? '') . PHP_EOL.'<!-- -----开发环境显示acl标签---------END -->';
            }
            return $tag_data[2] ?? '';
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

    static function document(): string
    {
        $msg = __('这里是重要信息，只允许拥有Weline_Backend::setting权限的用户访问');
        $tag = __('使用示例：').htmlentities('<acl source="Weline_Backend::setting">
    <div>
        <span>' . $msg . '</span>
    </div>
</acl>');
        return $tag;
    }
}
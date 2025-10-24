<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CacheManager\Controller\System;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

class Cache extends \Weline\Admin\Controller\BaseController
{
    public function index()
    {
        /**@var \Weline\CacheManager\Model\Cache $cacheModel */
        $cacheModel = ObjectManager::getInstance(\Weline\CacheManager\Model\Cache::class);
        $caches     = $cacheModel->pagination(
            $this->request->getParam('page', 1),
            $this->request->getParam('pageSize', 10),
            $this->request->getParams()
        )->select()->fetch();
        $this->assign('caches', $caches->getItems());
        $this->assign('pagination', $caches->getPagination());
        $this->assign('total', $caches->getPaginationData()['totalSize']);
        return $this->fetch();
    }

    public function postStatus()
    {
        $identity = $this->request->getParam('identity');
        $cache    = ($this->request->getParam('cache') === 'false') ? 0 : 1;
        /**@var \Weline\CacheManager\Model\Cache $cacheModel */
        $cacheModel = ObjectManager::getInstance(\Weline\CacheManager\Model\Cache::class);
        try {
            $cacheModel->where('identity', $identity)->update(['status' => $cache])->fetch();
            $cacheEnv           = Env::getInstance()->getConfig('cache');
            $status             = $cacheEnv['status'] ?? [];
            $status[$identity]  = $cache;
            $cacheEnv['status'] = $status;
            Env::getInstance()->setConfig('cache', $cacheEnv);
        } catch (\Exception $exception) {
            return $this->fetchJson(['code' => 403, 'msg' => $exception->getMessage(), 'data' => $cache]);
        }
        return $this->fetchJson(['code' => 200, 'msg' => __('操作成功！'), 'data' => $cache]);
    }
}

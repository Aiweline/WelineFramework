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
        if ($identity === null || $identity === '') {
            return $this->fetchJson(['code' => 403, 'msg' => __('参数 identity 不能为空'), 'data' => null]);
        }
        $identity = (string) $identity;
        $cache = ($this->request->getParam('cache') === 'false') ? 0 : 1;
        /** @var \Weline\CacheManager\Model\Cache $cacheModel */
        $cacheModel = ObjectManager::getInstance(\Weline\CacheManager\Model\Cache::class);
        try {
            // 只允许数据库中已存在的 identity，防止任意 key 写入 env.php
            $exists = $cacheModel->where('identity', $identity)->find()->fetch();
            if (!$exists || !$exists->getId()) {
                return $this->fetchJson(['code' => 403, 'msg' => __('该缓存项不存在或无权修改'), 'data' => null]);
            }
            $cacheModel->where('identity', $identity)->update(['status' => $cache])->fetch();
            // 以数据库为准合并当前 env 的 cache 配置，再写回 env.php，保持与后台一致
            $cacheEnv           = Env::getInstance()->getConfig('cache');
            $status             = $cacheEnv['status'] ?? [];
            $status[$identity]  = $cache;
            $cacheEnv['status'] = $status;
            if (!Env::getInstance()->setConfig('cache', $cacheEnv)) {
                return $this->fetchJson(['code' => 500, 'msg' => __('env 配置写入失败，请检查 app/etc/env.php 权限'), 'data' => $cache]);
            }
        } catch (\Exception $exception) {
            return $this->fetchJson(['code' => 403, 'msg' => $exception->getMessage(), 'data' => null]);
        }
        return $this->fetchJson(['code' => 200, 'msg' => __('操作成功！'), 'data' => $cache]);
    }
}

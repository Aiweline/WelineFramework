<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Async\Controller\Backend\Api;

use Weline\Framework\App\Controller\BackendRestController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Async\Service\WatcherService;

class Status extends BackendRestController
{
    private WatcherService $watcherService;

    public function __construct(ObjectManager $objectManager)
    {
        parent::__construct();
        $this->watcherService = $objectManager->getInstance(WatcherService::class);
    }

    /**
     * 获取所有watcher状态
     */
    public function getStatus()
    {
        $status = $this->watcherService->getAllWatchersStatus();
        return $this->success('获取成功', $status);
    }

    /**
     * 查看日志
     */
    public function getLog()
    {
        $mappingId = $this->request->getParam('mapping_id');
        $lines = (int)($this->request->getParam('lines') ?: 100);
        
        if (empty($mappingId)) {
            return $this->error('映射ID不能为空');
        }

        $logFile = BP . DS . 'var' . DS . 'async' . DS . 'logs' . DS . "mapping_{$mappingId}.log";
        
        if (!file_exists($logFile)) {
            return $this->success('日志文件不存在', []);
        }

        // 读取最后N行
        $lines = array_slice(file($logFile), -$lines);
        $log = implode('', $lines);

        return $this->success('获取成功', ['log' => $log]);
    }

    /**
     * 启动watcher
     */
    public function start()
    {
        $mappingId = $this->request->getParam('mapping_id');
        
        if (empty($mappingId)) {
            return $this->error('映射ID不能为空');
        }

        $result = $this->watcherService->startWatcher($mappingId);
        
        if ($result['success']) {
            return $this->success($result['message'] ?? '启动成功', $result);
        } else {
            return $this->error($result['message'] ?? '启动失败');
        }
    }

    /**
     * 停止watcher
     */
    public function stop()
    {
        $mappingId = $this->request->getParam('mapping_id');
        
        if (empty($mappingId)) {
            return $this->error('映射ID不能为空');
        }

        $result = $this->watcherService->stopWatcher($mappingId);
        
        if ($result['success']) {
            return $this->success($result['message'] ?? '停止成功', $result);
        } else {
            return $this->error($result['message'] ?? '停止失败');
        }
    }

    /**
     * 重启watcher
     */
    public function restart()
    {
        $mappingId = $this->request->getParam('mapping_id');
        
        if (empty($mappingId)) {
            return $this->error('映射ID不能为空');
        }

        $result = $this->watcherService->restartWatcher($mappingId);
        
        if ($result['success']) {
            return $this->success($result['message'] ?? '重启成功', $result);
        } else {
            return $this->error($result['message'] ?? '重启失败');
        }
    }
}

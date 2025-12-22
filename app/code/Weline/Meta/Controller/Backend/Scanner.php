<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Meta\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Meta\Service\Scanner as ScannerService;

class Scanner extends BackendController
{
    /**
     * 扫描页面
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 执行扫描（通过事件）
     */
    public function scan()
    {
        $scanPath = $this->request->getPost('scan_path'); // 例如：Weline_Theme::view/theme
        $namespace = $this->request->getPost('namespace', 'theme');
        $strictMode = (bool)$this->request->getPost('strict_mode', true);
        
        if (empty($scanPath)) {
            $this->getMessageManager()->addError(__('请指定扫描路径'));
            $this->redirect('*/index');
            return;
        }
        
        try {
            // 触发扫描事件，让其他模块的观察者处理
            // 注意：dispatch 方法的第二个参数需要按引用传递，所以先创建变量
            $eventData = [
                'scan_path' => $scanPath,
                'namespace' => $namespace,
                'strict_mode' => $strictMode
            ];
            $this->getEventManager()->dispatch('Weline_Meta::scan_path', $eventData);
            
            $this->getMessageManager()->addSuccess(__('扫描任务已提交，请查看扫描结果'));
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('扫描失败：%1', $e->getMessage()));
        }
        
        $this->redirect('*/index');
    }

    /**
     * 直接扫描（不通过事件）
     */
    public function scanDirect()
    {
        $scanPath = $this->request->getPost('scan_path');
        $namespace = $this->request->getPost('namespace', 'theme');
        $strictMode = (bool)$this->request->getPost('strict_mode', true);
        
        if (empty($scanPath)) {
            $this->getMessageManager()->addError(__('请指定扫描路径'));
            $this->redirect('*/index');
            return;
        }
        
        try {
            /** @var ScannerService $scanner */
            $scanner = ObjectManager::getInstance(ScannerService::class);
            $results = $scanner->scanPath($scanPath, $namespace, $strictMode);
            
            $successCount = count($results['success']);
            $failedCount = count($results['failed']);
            $skippedCount = count($results['skipped']);
            
            $this->getMessageManager()->addSuccess(__(
                '扫描完成：成功 %1 个，失败 %2 个，跳过 %3 个',
                $successCount,
                $failedCount,
                $skippedCount
            ));
            
            if ($failedCount > 0) {
                foreach ($results['failed'] as $failed) {
                    $this->getMessageManager()->addWarning(__(
                        '文件扫描失败：%1 - %2',
                        basename($failed['file']),
                        $failed['error']
                    ));
                }
            }
            
            $this->assign('results', $results);
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('扫描失败：%1', $e->getMessage()));
        }
        
        return $this->fetch('templates/Backend/Scanner/results.phtml');
    }
}


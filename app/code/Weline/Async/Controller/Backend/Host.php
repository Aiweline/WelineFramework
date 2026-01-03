<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Async\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Async\Model\SyncHost;
use Weline\Async\Service\ConfigService;
use Weline\Async\Service\WatcherService;

class Host extends BackendController
{
    private SyncHost $syncHost;
    private ConfigService $configService;
    private WatcherService $watcherService;

    public function __construct(ObjectManager $objectManager)
    {
        $this->syncHost = $objectManager->getInstance(SyncHost::class);
        $this->configService = $objectManager->getInstance(ConfigService::class);
        $this->watcherService = $objectManager->getInstance(WatcherService::class);
    }

    /**
     * 主机列表页面
     */
    public function index()
    {
        $hosts = $this->syncHost->clear()
            ->order(SyncHost::fields_CREATED_AT, 'DESC')
            ->select()
            ->fetch()
            ->getItems();

        // 检查项目配置文件
        $projectConfig = null;
        $projectStatus = null;
        if ($this->configService->hasProjectConfig()) {
            try {
                $projectConfig = $this->configService->getProjectConfig();
                $projectStatus = [
                    'is_running' => $this->watcherService->isWatcherRunning('project'),
                    'pid' => $this->watcherService->getWatcherPid('project'),
                    'config_file' => BP . DS . 'weline-async.json',
                ];
            } catch (\Exception $e) {
                // 配置读取失败，不显示状态
            }
        }

        $this->assign('hosts', $hosts);
        $this->assign('projectConfig', $projectConfig);
        $this->assign('projectStatus', $projectStatus);
        return $this->fetch();
    }

    /**
     * 新增/编辑主机表单
     */
    public function form()
    {
        try {
            // 检查是否是 AJAX 请求
            $xRequestedWith = strtolower($this->request->getServer('HTTP_X_REQUESTED_WITH') ?? '');
            $isAjaxParam = $this->request->getParam('isAjax');
            $isAjax = $this->request->isAjax() || 
                      $xRequestedWith === 'xmlhttprequest' ||
                      $isAjaxParam === '1' || $isAjaxParam === 1;
            
            $id = $this->request->getParam('id');
            $host = null;
            
            if ($id) {
                $host = $this->syncHost->clear()->load($id);
                if (!$host->getId()) {
                    if ($isAjax) {
                        return $this->fetchJson(['code' => 404, 'msg' => __('主机不存在'), 'data' => null]);
                    }
                    $this->getMessageManager()->addError(__('主机不存在'));
                    return $this->redirect('async/backend/host');
                }
            }

            // 如果是 AJAX 请求，返回 JSON（包含 HTML）
            if ($isAjax) {
                $this->assign('host', $host);
                
                try {
                    $html = $this->fetch('Weline_Async::templates/backend/host/form_offcanvas.phtml');
                } catch (\Exception $templateException) {
                    return $this->fetchJson([
                        'code' => 500, 
                        'msg' => __('模板渲染失败: ') . $templateException->getMessage(), 
                        'data' => null,
                        'trace' => DEV ? $templateException->getTraceAsString() : null
                    ]);
                }
                
                return $this->fetchJson(['code' => 200, 'msg' => __('获取成功'), 'data' => ['html' => $html]]);
            }

            $this->assign('host', $host);
            return $this->fetch();
            
        } catch (\Exception $e) {
            $xRequestedWith = strtolower($this->request->getServer('HTTP_X_REQUESTED_WITH') ?? '');
            $isAjaxParam = $this->request->getParam('isAjax');
            $isAjax = $this->request->isAjax() || 
                      $xRequestedWith === 'xmlhttprequest' ||
                      $isAjaxParam === '1' || $isAjaxParam === 1;
            
            if ($isAjax) {
                return $this->fetchJson([
                    'code' => 500, 
                    'msg' => __('加载失败: ') . $e->getMessage(), 
                    'data' => null,
                    'trace' => DEV ? $e->getTraceAsString() : null
                ]);
            }
            $this->getMessageManager()->addError(__('加载失败: ') . $e->getMessage());
            return $this->redirect('async/backend/host');
        }
    }

    /**
     * 保存主机
     */
    public function save()
    {
        try {
            // 检查是否是 AJAX 请求
            $xRequestedWith = strtolower($this->request->getServer('HTTP_X_REQUESTED_WITH') ?? '');
            $isAjaxParam = $this->request->getParam('isAjax');
            $isAjax = $this->request->isAjax() || 
                      $xRequestedWith === 'xmlhttprequest' ||
                      $isAjaxParam === '1' || $isAjaxParam === 1;
            
            $id = $this->request->getParam('id');
            $name = trim($this->request->getParam('name', ''));
            $host = trim($this->request->getParam('host', ''));
            $port = (int)($this->request->getParam('port') ?: 22);
            $user = trim($this->request->getParam('user', ''));
            $password = $this->request->getParam('password', '');
            $keyPath = trim($this->request->getParam('key_path', '')); // 临时文件路径，用于读取
            $keyContent = trim($this->request->getParam('key_content', '')); // 直接输入的密钥内容
            $description = trim($this->request->getParam('description', ''));

            // 验证必填字段
            if (empty($name)) {
                throw new \RuntimeException(__('主机名称不能为空'));
            }
            if (empty($host)) {
                throw new \RuntimeException(__('主机地址不能为空'));
            }
            if (empty($user)) {
                throw new \RuntimeException(__('SSH用户名不能为空'));
            }
            
            // 处理密钥内容：优先使用直接输入的内容，其次从文件路径读取
            $finalKeyContent = '';
            if (!empty($keyContent)) {
                // 直接输入的密钥内容
                $finalKeyContent = $keyContent;
            } elseif (!empty($keyPath) && file_exists($keyPath) && is_readable($keyPath)) {
                // 从文件路径读取密钥内容
                $finalKeyContent = file_get_contents($keyPath);
                if ($finalKeyContent === false) {
                    throw new \RuntimeException(__('无法读取密钥文件'));
                }
            }
            
            // 验证密码或密钥至少提供一个
            if (empty($password) && empty($finalKeyContent)) {
                // 编辑模式下，如果已有密码或密钥，可以不提供
                if ($id) {
                    $existingHost = $this->syncHost->clear()->load($id);
                    if ($existingHost->getId()) {
                        $existingPassword = $existingHost->getData(SyncHost::fields_PASSWORD);
                        $existingKeyContent = $existingHost->getData(SyncHost::fields_KEY_CONTENT);
                        if (empty($existingPassword) && empty($existingKeyContent)) {
                            throw new \RuntimeException(__('SSH密码或密钥至少需要提供一个'));
                        }
                    } else {
                        throw new \RuntimeException(__('SSH密码或密钥至少需要提供一个'));
                    }
                } else {
                    throw new \RuntimeException(__('SSH密码或密钥至少需要提供一个'));
                }
            }

            $hostModel = $this->syncHost->clear();
            
            if ($id) {
                // 编辑模式
                $hostModel->load($id);
                if (!$hostModel->getId()) {
                    throw new \RuntimeException(__('主机不存在'));
                }
            }

            // 设置数据
            $hostModel->setData(SyncHost::fields_NAME, $name);
            $hostModel->setData(SyncHost::fields_HOST, $host);
            $hostModel->setData(SyncHost::fields_PORT, $port);
            $hostModel->setData(SyncHost::fields_USER, $user);
            
            // 只有提供了新密码才更新
            if (!empty($password)) {
                $hostModel->setData(SyncHost::fields_PASSWORD, $password);
            }
            
            // 保存密钥内容（如果提供了）
            if (!empty($finalKeyContent)) {
                $hostModel->setData(SyncHost::fields_KEY_CONTENT, $finalKeyContent);
            }
            
            // 临时保存密钥路径（用于读取，保存后会被清除）
            if (!empty($keyPath)) {
                $hostModel->setData(SyncHost::fields_KEY_PATH, $keyPath);
            }
            
            if (!empty($description)) {
                $hostModel->setData(SyncHost::fields_DESCRIPTION, $description);
            }

            $hostModel->save();

            if ($isAjax) {
                return $this->fetchJson(['code' => 200, 'msg' => __('保存成功'), 'data' => null]);
            }
            
            $this->getMessageManager()->addSuccess(__('保存成功'));
            return $this->redirect('async/backend/host');
            
        } catch (\Exception $e) {
            $xRequestedWith = strtolower($this->request->getServer('HTTP_X_REQUESTED_WITH') ?? '');
            $isAjaxParam = $this->request->getParam('isAjax');
            $isAjax = $this->request->isAjax() || 
                      $xRequestedWith === 'xmlhttprequest' ||
                      $isAjaxParam === '1' || $isAjaxParam === 1;
            
            if ($isAjax) {
                return $this->fetchJson(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
            }
            
            $this->getMessageManager()->addError($e->getMessage());
            $id = $this->request->getParam('id');
            return $this->redirect('async/backend/host/form' . ($id ? '?id=' . $id : ''));
        }
    }

    /**
     * 删除主机
     */
    public function delete()
    {
        try {
            // 检查是否是 AJAX 请求
            $xRequestedWith = strtolower($this->request->getServer('HTTP_X_REQUESTED_WITH') ?? '');
            $isAjaxParam = $this->request->getParam('isAjax');
            $isAjax = $this->request->isAjax() || 
                      $xRequestedWith === 'xmlhttprequest' ||
                      $isAjaxParam === '1' || $isAjaxParam === 1;
            
            $id = $this->request->getParam('id');
            if (empty($id)) {
                throw new \RuntimeException(__('主机ID不能为空'));
            }

            $host = $this->syncHost->clear()->load($id);
            if (!$host->getId()) {
                throw new \RuntimeException(__('主机不存在'));
            }

            // 检查是否有映射使用该主机
            $mappings = $host->getMappings();
            if (!empty($mappings)) {
                throw new \RuntimeException(__('该主机下还有目录映射，请先删除映射'));
            }

            $host->delete();
            
            if ($isAjax) {
                return $this->fetchJson(['code' => 200, 'msg' => __('删除成功'), 'data' => null]);
            }
            
            $this->getMessageManager()->addSuccess(__('删除成功'));
            
        } catch (\Exception $e) {
            $xRequestedWith = strtolower($this->request->getServer('HTTP_X_REQUESTED_WITH') ?? '');
            $isAjaxParam = $this->request->getParam('isAjax');
            $isAjax = $this->request->isAjax() || 
                      $xRequestedWith === 'xmlhttprequest' ||
                      $isAjaxParam === '1' || $isAjaxParam === 1;
            
            if ($isAjax) {
                return $this->fetchJson(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
            }
            
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        if (!isset($isAjax) || !$isAjax) {
            return $this->redirect('async/backend/host');
        }
    }
}

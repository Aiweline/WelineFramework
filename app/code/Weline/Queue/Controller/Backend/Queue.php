<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：18/7/2023 09:57:55
 */

namespace Weline\Queue\Controller\Backend;

use PHPUnit\Util\Exception;
use Weline\Backend\Model\BackendUserData;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Cron\Helper\Process;
use Weline\Eav\Model\EavAttribute;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Database\Exception\ModelException;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Model\Queue\Type\Attributes;
use Weline\Queue\QueueInterface;

#[Acl('Weline_Queue:listing_manager', '队列管理', 'mdi-human-queue', '管理队列信息', 'Weline_Queue:listing')]
class Queue extends \Weline\Framework\App\Controller\BackendController
{
    private \Weline\Queue\Model\Queue $queue;
    private \Weline\Queue\Model\Queue\Type $type;

    public function __construct(\Weline\Queue\Model\Queue $queue, \Weline\Queue\Model\Queue\Type $type)
    {
        $this->queue = $queue;
        $this->type = $type;
    }

    #[Acl('Weline_Queue::index', '队列首页列表', 'mdi mdi-format-list-numbered', '队列首页列表')]
    public function index()
    {
        $this->assign('title', __('消息队列'));
        
        $module = $this->request->getGet('module');
        $status = $this->request->getGet('status');
        $search = $this->request->getGet('q');
        $id = $this->request->getGet('id');
        
        $this->queue->joinModel(\Weline\Queue\Model\Queue\Type::class, 't', 'main_table.type_id=t.type_id', 'left');
        
        if ($module) {
            $this->queue->where('t.module_name', $module);
        }
        if ($search) {
            $this->queue->where("concat(main_table.name,main_table.content,main_table.result) like '%$search%'");
        }
        if ($id) {
            $this->queue->where('main_table.' . $this->queue::schema_fields_ID, $id);
        }
        if ($status) {
            $this->queue->where('main_table.status', $status);
        }
        
        $this->queue->additional('AND (t.enable = 1 OR t.enable IS NULL)')
            ->order('main_table.queue_id', 'DESC');
        $this->queue->pagination()->select()->fetch();
        
        $stats = $this->getQueueStats();
        
        $this->assign('queues', $this->queue->getItems());
        $this->assign('module', $module);
        $this->assign('status', $status);
        $this->assign('stats', $stats);
        $this->assign('pagination', $this->queue->getPagination());
        return $this->fetch();
    }
    
    private function getQueueStats(): array
    {
        /** @var \Weline\Queue\Model\Queue $queueModel */
        $queueModel = ObjectManager::make(\Weline\Queue\Model\Queue::class);
        
        $allCount = (int)$queueModel->reset()->count('queue_id');
        $pendingCount = (int)$queueModel->reset()->where('status', \Weline\Queue\Model\Queue::status_pending)->count('queue_id');
        $runningCount = (int)$queueModel->reset()->where('status', \Weline\Queue\Model\Queue::status_running)->count('queue_id');
        $doneCount = (int)$queueModel->reset()->where('status', \Weline\Queue\Model\Queue::status_done)->count('queue_id');
        $errorCount = (int)$queueModel->reset()->where('status', \Weline\Queue\Model\Queue::status_error)->count('queue_id');
        $stopCount = (int)$queueModel->reset()->where('status', \Weline\Queue\Model\Queue::status_stop)->count('queue_id');
        
        return [
            'all' => $allCount,
            'pending' => $pendingCount,
            'running' => $runningCount,
            'done' => $doneCount,
            'error' => $errorCount,
            'stop' => $stopCount,
        ];
    }

    #[Acl('Weline_Queue::form', '编辑或者新增', 'mdi mdi-form-textbox', '编辑或者新增')]
    function form()
    {
        if ($this->request->isGet()) {
            $id = $this->request->getGet('id', 0);
            $queue = $this->queue->load($id);
            $module = $this->request->getGet('module');
            $dir = $this->request->getGet('dir');
            # 删除用户的记录数据
//            /** @var BackendUserData $userData */
//            $userData = ObjectManager::getInstance(BackendUserData::class);
//            $userData->deleteScope($this->session->getLoginUserID(), 'queue');
            # 如果队列已经运行则无法修改
            if ($queue->getId() and !$queue->isPending()) {
                $this->redirect('/component/offcanvas/error', ['msg' => __('队列已经运行，无法修改'), 'reload' => 1]);
            }
            if ($queue->getId() and $queue->isFinished()) {
                $this->redirect('/component/offcanvas/error', ['msg' => __('队列已经完成,无法修改'), 'reload' => 1]);
            }
            if (!$queue->getId()) {
                /** @var BackendUserData $userData */
                $userData = ObjectManager::getInstance(BackendUserData::class);
                $userData = $userData->getScope('queue');
                $queue->setData($userData);
            }
            if ($module) {
                $this->type->where('module_name', $module);
            }
            if ($dir) {
                $dir = str_replace('\\', '\\\\', ucfirst($dir));
                $this->type->where('class', '%' . $dir . '%', 'like');
            }
            $types = $this->type->where('enable', 1)->select()->fetchArray();
            foreach ($types as &$type_) {
                $type_['tip'] = $type_['tip'] . '<hr><br><span class="text-primary">' . __('执行类：') . $type_['class'] . '</span>';
            }
            $this->assign('title', __('添加队列'));
            $this->assign('queue_types', $types);
            $this->assign('queueData', $queue->getData());
            $this->assign('module', $module);
            $this->assign('dir', $dir);
            $this->layoutType = 'default.blank';
            return $this->fetch();
        }
        $json = ['code' => 404, 'msg' => ''];
        $module = $this->request->getGet('module') ?: $this->request->getModuleName();
        # 创建队列
        $type_id = (int)$this->request->getPost('type_id', 0);
        # 查询类型
        $type = $this->type->load($type_id);
        if (!$type->getId()) {
            $json['msg'] = __('队列类型不存在');
            return $this->fetchJson($json);
        }
        $name = $this->request->getPost('name', '');
        if (empty($name)) {
            $name = $type->getName();
        }

        # 创建队列 或者 编辑队列 id
        $queue_id = $this->request->getPost('id', 0);
        $edit = 1;
        if ($queue_id) {
            $this->queue->load($queue_id);
            $this->queue->setTypeId($type_id)
                ->setName($name)
                ->setModule($module)
                ->save();
            if (!$this->queue->getId()) {
                $json['msg'] = __('队列不存在');
                return $this->fetchJson($json);
            }
        } else {
            try {
                $queue_id = $this->queue->setTypeId($type_id)
                    ->setName($name)
                    ->setModule($module)
                    ->save();
                $edit = 0;
            } catch (ModelException $e) {
                $json['msg'] = $e->getMessage();
                return $this->fetchJson($json);
            }
        }
        $this->queue->load($queue_id);
        if (!$queue_id) {
            $json['msg'] = __('创建队列失败');
            return $this->fetchJson($json);
        }
        # 队列添加事件
        $data = ['queue' => $this->queue];
        $this->getEventManager()->dispatch('Weline_Queue::' . ($edit ? 'edit' : 'add'), $data);
        $this->queue->setResult($json['msg'])->save();
        # 写入属性
        /**@var Attributes $attributeModel */
        $attributeModel = ObjectManager::getInstance(Attributes::class);
        $attributes = $this->request->getPost('attributes', []);
        # 检查所有属性是否都存在
        $attributesCodes = [];
        $attributesItems = [];
        foreach ($attributes as $key => $value) {
            $msg = (DEV ? __('属性数据：') . w_var_export($value, true) : '');
            if (empty($value['code'])) {
                $json['msg'] = __('队列属性编码不能为空') . $msg;
                $this->queue->setResult($json['msg'])->save();
                return $this->fetchJson($json);
            }
            $attr = $attributeModel->reset()->joinModel(EavAttribute\Type::class, 't', 'main_table.type_id=t.type_id')
                ->where('main_table.code', $value['code'])
                ->find()
                ->fetch();
            if (!$attr->getId()) {
                $json['msg'] = __('队列属性编码不存在,请确保您输入的属性code在Eav属性系统中存在。') . $msg;
                $this->queue->setResult($json['msg'])->save();
                return $this->fetchJson($json);
            }
            if (!isset($value['name'])) {
                $json['msg'] = __('队列属性名称不能为空') . $msg;
                $this->queue->setResult($json['msg'])->save();
                return $this->fetchJson($json);
            }
            if ($attr->isRequest() and !isset($value['value'])) {
                $json['msg'] = __('队列属性值不能为空') . $msg;
                $this->queue->setResult($json['msg'])->save();
                return $this->fetchJson($json);
            }
            if (!isset($value['value_alias'])) {
                $json['msg'] = __('队列属性值别名不能为空') . $msg;
                $this->queue->setResult($json['msg'])->save();
                return $this->fetchJson($json);
            }
            $attributesCodes[] = $value['code'];
            $attributesItems[] = $attr;
        }
        # 有属性时对属性进行处理
        if ($attributes and is_array($attributes)) {
            foreach ($attributes as $attribute) {
                try {
                    $this->queue
                        ->getAttribute($attribute['code'])
                        ->setValue($queue_id, $attribute['value']);
                } catch (\ReflectionException|\Weline\Framework\App\Exception|Core $e) {
                    $json['msg'] = __('设置队列属性失败！请修改重试。%{1}', $e->getMessage());
                    $this->queue->load($queue_id);
                    $this->queue->setResult($json['msg'])->save();
                    return $this->fetchJson($json);
                }
            }
        }
        # 校验一下队列
        /** @var QueueInterface $execute */
        $execute = ObjectManager::getInstance($this->queue->getType()->getClass());
        $result = $execute->validate($this->queue);
        if (!$result) {
            $json['msg'] = __('队列校验失败，校验消息：%{1}', $this->queue->getResult());
            return $this->fetchJson($json);
        }
        # 删除用户的记录数据
        /** @var BackendUserData $userData */
        $userData = ObjectManager::getInstance(BackendUserData::class);
        $userData->deleteScope('queue');
        $json['code'] = 200;
        $json['msg'] = $edit ? __('队列已编辑！等待运行中...') : __('队列已成功创建！等待运行中...');

        return $this->fetchJson($json);
    }

    #[Acl('Weline_Queue::search_type', '获取类型数据', 'mdi mdi-database-arrow-right-outline', '获取类型数据')]
    public function getSearchType(): string
    {
        $json = ['code' => 200, 'msg' => ''];
        $q = $this->request->getGet('q');
        /** @var \Weline\Queue\Model\Queue\Type $typeModel */
        $typeModel = ObjectManager::getInstance(\Weline\Queue\Model\Queue\Type::class);
        $module = $this->request->getGet('module', '');
        $dir = $this->request->getGet('dir', '');
        if ($q) {
            $typeModel->where('name', '%' . $q . '%', 'like');
        }
        if ($module) {
            $typeModel->where('module_name', $module);
        }
        $typeModel->where('enable', 1);
        if ($dir) {
            $dir = str_replace('\\', '\\\\', $dir);
            $typeModel->where('class', '%' . ucfirst($dir) . '%', 'like');
        }
        $types = $typeModel->select()->fetchArray();
        foreach ($types as &$type_) {
            $type_['tip'] = $type_['tip'] . '<hr><br><span class="text-primary">' . __('执行类：') . $type_['class'] . '</span>';
        }
        $json['data'] = $types;
        return $this->fetchJson($json);
    }

    #[Acl('Weline_Queue::get_type_attributes', '获取属性数据', 'mdi mdi-database-arrow-right-outline', '获取属性数据')]
    public function getTypeAttributes(): string
    {
        $json = ['code' => 200, 'msg' => ''];
        $queue_id = $this->request->getGet('id', 0);
        $type_id = $this->request->getGet('type_id', 0);
        if ($queue_id) {
            $this->queue->load($queue_id);
        }
        if (empty($type_id)) {
            $json['code'] = 404;
            $json['msg'] = __('请选择队列类型后再操作！');
            return $this->fetchJson($json);
        }
        $type = $this->type->load($type_id);
        /** @var BackendUserData $userData */
        $userData = ObjectManager::getInstance(BackendUserData::class);
        $userData = $userData->getScope('queue');
        $options_data = [
            'label_class' => 'control-label',
            'attrs' => ['class' => 'form-control w-100', 'scope' => 'queue', 'file-ext' => '*', 'file-size' => '102400000'],
            'need_array' => 1,
            'values' => $userData,
        ];
        if ($this->queue->getId()) {
            $options_data['entity'] = $this->queue;
        } else {
            $options_data['values'] = $userData;
        }
        $type->setData($userData);
        $json['data'] = $type->getAttributes($options_data);
        return $this->fetchJson($json);
    }

    #[Acl('Weline_Queue::get_type_data', '获取类型数据', 'mdi mdi-database-arrow-right-outline', '获取类型数据')]
    public function getTypeData()
    {
        $json = ['code' => 404, 'msg' => ''];
        $id = $this->request->getGet('id');
        if (empty($id)) {
            $json['msg'] = __('请选择要查看的队列');
            return $this->fetchJson($json);
        }
        /** @var \Weline\Queue\Model\Queue\Type $typeModel */
        $typeModel = ObjectManager::getInstance(\Weline\Queue\Model\Queue\Type::class);
        $type = $typeModel->load($id);
        if (!$type->getId()) {
            $json['msg'] = __('队列不存在');
            return $this->fetchJson($json);
        }
        $json['code'] = 200;
        $json['data'] = $type->getData();
        return $this->fetchJson($json);
    }

    #[Acl('Weline_Queue::show', '查看', 'mdi mdi-monitor-eye', '查看')]
    function show()
    {
        $id = $this->request->getGet('id');
        if (empty($id)) {
            $this->getMessageManager()->addWarning(__('请选择要查看的队列'));
            $this->redirect('/component/offcanvas/error', ['msg' => __('请选择要查看的队列'), 'reload' => 1]);
        }
        $res = $this->queue->joinModel(\Weline\Queue\Model\Queue\Type::class, 't', 'main_table.type_id=t.type_id', 'left')
            ->where('main_table.' . $this->queue::schema_fields_ID, $id)->find()->fetch();
        if (!$this->queue->getId()) {
            $this->getMessageManager()->addWarning(__('队列不存在'));
            $this->redirect('/component/offcanvas/error', ['msg' => __('队列不存在'), 'reload' => 0]);
        }
        # 加载属性数据
        $type = $this->queue->getType();
        $options_data = [
            'label_class' => 'control-label',
            'attrs' => ['class' => 'form-control w-100 readonly disabled', 'disabled' => 'disabled'],
            'entity' => $this->queue
        ];
        $attrs = $type->getAttributes($options_data);
        $this->queue->setData('data', $attrs);
        $this->assign('queue', $this->queue);
        # 如果result结果大于1M，就下载
        $result = $this->queue->getData('result');
        if (!empty($result)) {
            $resultSize = mb_strlen($result);
            if ($resultSize > 1024 * 1024) {
                $dowloadUrl = $this->request->getUrlBuilder()->getBackendUrl('*/backend/queue/dowloadResult', ['id' => $id]);
                $sieMb = round($resultSize / 1024 / 1024, 2);
                $this->queue->setData('result', __('队列结果过大:%{1} Mb。 请<a href="%{2}">下载队列结果</a>查看。', [$sieMb, $dowloadUrl]));
            }
        }
        return $this->fetch();
    }

    #[Acl('Weline_Queue::download_result', '下载结果', 'mdi mdi-download', '下载结果')]
    function dowloadResult()
    {
        $id = $this->request->getGet('id');
        if (empty($id)) {
            http_response_code(403);
            exit(__('请选择要下载的队列'));
        }
        $this->queue->load($id);
        if (!$this->queue->getId()) {
            http_response_code(404);
            exit(__('队列不存在'));
        }
        # 自动将结果result生成txt下载
        $dowloadName = 'queue_result_' . $id . '.txt';
        $result = $this->queue->getData('result');
        if (!empty($result)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $dowloadName . '"');
            echo $result;
            exit;
        } else {
            exit(__('队列没有结果'));
        }
    }

    #[Acl('Weline_Queue::result', '删除队列', 'mdi mdi-delete', '删除队列')]
    function getDelete()
    {
        $queue_id = $this->request->getGet('id', 0);
        $isAjax = $this->request->isXmlHttpRequest();
        
        if (empty($queue_id)) {
            $msg = __('请选择要操作的队列');
            if ($isAjax) {
                return $this->fetchJson(['code' => 400, 'msg' => $msg]);
            }
            $this->getMessageManager()->addWarning($msg);
            $this->redirect($this->request->getReferer());
        }
        
        $this->queue->load($queue_id);
        if (!$this->queue->getId()) {
            $msg = __('队列不存在');
            if ($isAjax) {
                return $this->fetchJson(['code' => 404, 'msg' => $msg]);
            }
            $this->getMessageManager()->addWarning($msg);
            $this->redirect($this->request->getReferer());
        }
        
        if ($this->queue->getStatus() === $this->queue::status_running) {
            $msg = __('队列正在运行中，无法删除！请先暂停队列后再删除。');
            if ($isAjax) {
                return $this->fetchJson(['code' => 403, 'msg' => $msg]);
            }
            $this->getMessageManager()->addWarning($msg);
            $this->redirect($this->request->getReferer());
        }
        
        $queueName = $this->queue->getName();
        $this->queue->delete()->fetch();
        
        $data = ['queue' => $this->queue];
        $this->getEventManager()->dispatch('Weline_Queue::delete', $data);
        
        $msg = __('队列 "%1" 已成功删除！', $queueName);
        if ($isAjax) {
            return $this->fetchJson(['code' => 200, 'msg' => $msg]);
        }
        $this->getMessageManager()->addSuccess($msg);
        $this->redirect($this->request->getReferer());
    }

    #[Acl('Weline_Queue::result', '查看结果', 'mdi mdi-table-headers-eye', '查看结果')]
    function getDetailResult()
    {
        $queue_id = $this->request->getParam('id', 0);
        if (empty($queue_id)) {
            $this->getMessageManager()->addWarning(__('请选择要操作的队列'));
            return $this->fetch('content');
        }
        $this->queue->load($queue_id);
        $data = $this->queue->getData($this->queue::schema_fields_result);
        $this->assign('data', $data);
        return $this->fetch('content');
    }

    #[Acl('Weline_Queue::content', '查看详情', 'mdi mdi-information', '查看详情')]
    function getDetailContent()
    {
        $queue_id = $this->request->getParam('id', 0);
        if (empty($queue_id)) {
            $this->getMessageManager()->addWarning(__('请选择要操作的队列'));
            return $this->fetch('content');
        }
        $this->queue->load($queue_id);
        $data = $this->queue->getData($this->queue::schema_fields_content);
        $this->assign('data', $data);
        return $this->fetch('content');
    }

    #[Acl('Weline_Queue::reset', '重置刊登任务', 'mdi mdi-lock-reset', '重置刊登任务')]
    public function reset()
    {
        $queue_id = $this->request->getParam('id', 0);
        $isAjax = $this->request->isXmlHttpRequest();
        
        $this->queue->load($queue_id);
        if (!$this->queue->getId()) {
            $msg = __('队列记录不存在！');
            if ($isAjax) {
                return $this->fetchJson(['code' => 404, 'msg' => $msg]);
            }
            $this->getMessageManager()->addError($msg);
            $this->redirect($this->request->getReferer());
        }
        
        $pid = $this->queue->getPid();
        if ($pid) {
            $running = Process::isProcessRunning($pid);
            if ($running) {
                $msg = __('队列有正在运行的进程（PID: %{1}），请先暂停队列后再重置！', $pid);
                if ($isAjax) {
                    return $this->fetchJson(['code' => 403, 'msg' => $msg]);
                }
                $this->getMessageManager()->addError($msg);
                $this->redirect($this->request->getReferer());
            }
        }
        
        $this->queue->setData($this->queue::schema_fields_status, \Weline\Queue\Model\Queue::status_pending);
        $this->queue->setData($this->queue::schema_fields_finished, 0);
        $this->queue->setData($this->queue::schema_fields_pid, 0);
        $this->queue->save();
        
        $data = ['queue' => $this->queue];
        $this->getEventManager()->dispatch('Weline_Queue::reset', $data);
        
        $msg = __('队列 "%1" 已重置，等待重新执行！', $this->queue->getName());
        if ($isAjax) {
            return $this->fetchJson(['code' => 200, 'msg' => $msg]);
        }
        $this->getMessageManager()->addSuccess($msg);
        $this->redirect($this->request->getReferer());
    }

    #[Acl('Weline_Queue::stop', '完成刊登任务', 'mdi mdi-lock-reset', '完成刊登任务')]
    public function stop()
    {
        $queue_id = $this->request->getParam('id', 0);
        $isAjax = $this->request->isXmlHttpRequest();
        
        $queue = $this->queue->load($queue_id);
        if (!$queue->getId()) {
            $msg = __('队列记录不存在！');
            if ($isAjax) {
                return $this->fetchJson(['code' => 404, 'msg' => $msg]);
            }
            $this->getMessageManager()->addError($msg);
            $this->redirect($this->request->getReferer());
        }
        
        $pid = $queue->getPid();
        $killMsg = '';
        if ($pid) {
            $running = Process::isProcessRunning($pid);
            if ($running) {
                $pname = 'queue-' . $queue->getName() . '-' . $queue->getId();
                $result = Process::killPid($pid, $pname);
                Process::unsetLogProcessFilePath($pname);
                if ($result) {
                    $killMsg = __('（进程 PID: %{1} 已终止）', $pid);
                } else {
                    $msg = __('无法终止队列进程（PID: %{1}），请手动结束进程后重试！', $pid);
                    if ($isAjax) {
                        return $this->fetchJson(['code' => 500, 'msg' => $msg]);
                    }
                    $this->getMessageManager()->addError($msg);
                    $this->redirect($this->request->getReferer());
                }
            }
            $queue->setPid(0);
        }
        
        $queue->setData($queue::schema_fields_status, \Weline\Queue\Model\Queue::status_stop);
        $queue->save();
        
        $data = ['queue' => $this->queue];
        $this->getEventManager()->dispatch('Weline_Queue::stop', $data);
        
        $msg = __('队列 "%1" 已暂停！', $queue->getName()) . $killMsg;
        if ($isAjax) {
            return $this->fetchJson(['code' => 200, 'msg' => $msg]);
        }
        $this->getMessageManager()->addSuccess($msg);
        $this->redirect($this->request->getReferer());
    }

    #[Acl('Weline_Queue::continue', '继续刊登任务', 'mdi mdi-arrow-right-thin-circle-outline', '继续刊登任务')]
    public function continue()
    {
        $queue_id = $this->request->getParam('id', 0);
        $isAjax = $this->request->isXmlHttpRequest();
        
        $queue = $this->queue->load($queue_id);
        if (!$queue->getId()) {
            $msg = __('队列记录不存在！');
            if ($isAjax) {
                return $this->fetchJson(['code' => 404, 'msg' => $msg]);
            }
            $this->getMessageManager()->addError($msg);
            $this->redirect($this->request->getReferer());
        }
        
        $pid = $queue->getPid();
        if ($pid) {
            $running = Process::isProcessRunning($pid);
            if ($running) {
                $msg = __('队列进程（PID: %{1}）仍在运行中，无法继续！请先暂停队列。', $pid);
                if ($isAjax) {
                    return $this->fetchJson(['code' => 403, 'msg' => $msg]);
                }
                $this->getMessageManager()->addError($msg);
                $this->redirect($this->request->getReferer());
            }
            $queue->setData($queue::schema_fields_pid, 0);
        }
        
        $queue->setData($queue::schema_fields_status, \Weline\Queue\Model\Queue::status_pending);
        $queue->setData($queue::schema_fields_finished, 0);
        $queue->setData($queue::schema_fields_pid, 0);
        $queue->save();
        
        $data = ['queue' => $this->queue];
        $this->getEventManager()->dispatch('Weline_Queue::continue', $data);
        
        $msg = __('队列 "%1" 已恢复，等待继续执行！', $queue->getName());
        if ($isAjax) {
            return $this->fetchJson(['code' => 200, 'msg' => $msg]);
        }
        $this->getMessageManager()->addSuccess($msg);
        $this->redirect($this->request->getReferer());
    }

    #[Acl('Weline_Queue::api_action', 'AJAX队列操作', 'mdi mdi-api', 'AJAX队列操作接口')]
    public function postApiAction(): string
    {
        $json = ['success' => false, 'msg' => ''];
        
        $data = $this->request->getBodyParams();
        $action = $data['action'] ?? '';
        $queueId = (int)($data['id'] ?? 0);
        
        if (empty($queueId)) {
            $json['msg'] = __('请选择要操作的队列');
            return $this->fetchJson($json);
        }
        
        $this->queue->load($queueId);
        if (!$this->queue->getId()) {
            $json['msg'] = __('队列记录不存在');
            return $this->fetchJson($json);
        }
        
        try {
            switch ($action) {
                case 'delete':
                    if ($this->queue->getStatus() === \Weline\Queue\Model\Queue::status_running) {
                        $json['msg'] = __('队列正在运行，无法删除！请先暂停队列。');
                        return $this->fetchJson($json);
                    }
                    $this->queue->delete()->fetch();
                    $eventData = ['queue' => $this->queue];
                    $this->getEventManager()->dispatch('Weline_Queue::delete', $eventData);
                    $json['success'] = true;
                    $json['msg'] = __('队列已成功删除');
                    break;
                    
                case 'stop':
                    $pid = $this->queue->getPid();
                    if ($pid) {
                        $running = Process::isProcessRunning($pid);
                        if ($running) {
                            $pname = 'queue-' . $this->queue->getName() . '-' . $this->queue->getId();
                            $result = Process::killPid($pid, $pname);
                            Process::unsetLogProcessFilePath($pname);
                            if (!$result) {
                                $json['msg'] = __('杀死进程失败！进程ID：%{1}', $pid);
                                return $this->fetchJson($json);
                            }
                        }
                        $this->queue->setPid(0);
                    }
                    $this->queue->setData(\Weline\Queue\Model\Queue::schema_fields_status, \Weline\Queue\Model\Queue::status_stop);
                    $this->queue->save();
                    $eventData = ['queue' => $this->queue];
                    $this->getEventManager()->dispatch('Weline_Queue::stop', $eventData);
                    $json['success'] = true;
                    $json['msg'] = __('队列已暂停');
                    break;
                    
                case 'continue':
                case 'retry':
                    $pid = $this->queue->getPid();
                    if ($pid) {
                        $running = Process::isProcessRunning($pid);
                        if ($running) {
                            $json['msg'] = __('队列有进程正在运行，无法继续！进程ID：%{1}', $pid);
                            return $this->fetchJson($json);
                        }
                        $this->queue->setData(\Weline\Queue\Model\Queue::schema_fields_pid, 0);
                    }
                    $this->queue->setData(\Weline\Queue\Model\Queue::schema_fields_status, \Weline\Queue\Model\Queue::status_pending);
                    $this->queue->setData(\Weline\Queue\Model\Queue::schema_fields_finished, 0);
                    $this->queue->setData(\Weline\Queue\Model\Queue::schema_fields_pid, 0);
                    $this->queue->save();
                    $eventData = ['queue' => $this->queue];
                    $this->getEventManager()->dispatch('Weline_Queue::continue', $eventData);
                    $json['success'] = true;
                    $json['msg'] = $action === 'retry' ? __('队列已重试') : __('队列已继续');
                    break;
                    
                case 'reset':
                    $pid = $this->queue->getPid();
                    if ($pid && Process::isProcessRunning($pid)) {
                        $json['msg'] = __('队列有进程正在运行，请先暂停队列！进程ID：%{1}', $pid);
                        return $this->fetchJson($json);
                    }
                    $this->queue->setData(\Weline\Queue\Model\Queue::schema_fields_status, \Weline\Queue\Model\Queue::status_pending);
                    $this->queue->setData(\Weline\Queue\Model\Queue::schema_fields_finished, 0);
                    $this->queue->setData(\Weline\Queue\Model\Queue::schema_fields_pid, 0);
                    $this->queue->save();
                    $eventData = ['queue' => $this->queue];
                    $this->getEventManager()->dispatch('Weline_Queue::reset', $eventData);
                    $json['success'] = true;
                    $json['msg'] = __('队列已重置');
                    break;
                    
                default:
                    $json['msg'] = __('不支持的操作类型：%{1}', $action);
            }
        } catch (\Exception $e) {
            $json['msg'] = __('操作失败：%{1}', $e->getMessage());
        }
        
        return $this->fetchJson($json);
    }

    #[Acl('Weline_Queue::api_batch', '批量队列操作', 'mdi mdi-api', '批量队列操作接口')]
    public function postApiBatch(): string
    {
        $json = ['success' => false, 'msg' => '', 'results' => []];
        
        $data = $this->request->getBodyParams();
        $action = $data['action'] ?? '';
        $ids = $data['ids'] ?? [];
        
        if (empty($ids) || !is_array($ids)) {
            $json['msg'] = __('请选择要操作的队列');
            return $this->fetchJson($json);
        }
        
        $successCount = 0;
        $failCount = 0;
        $results = [];
        
        foreach ($ids as $queueId) {
            $queueId = (int)$queueId;
            $queue = ObjectManager::make(\Weline\Queue\Model\Queue::class);
            $queue->load($queueId);
            
            if (!$queue->getId()) {
                $results[] = ['id' => $queueId, 'success' => false, 'msg' => __('队列不存在')];
                $failCount++;
                continue;
            }
            
            try {
                switch ($action) {
                    case 'delete':
                        if ($queue->getStatus() === \Weline\Queue\Model\Queue::status_running) {
                            $results[] = ['id' => $queueId, 'success' => false, 'msg' => __('正在运行')];
                            $failCount++;
                            continue 2;
                        }
                        $queue->delete()->fetch();
                        $eventData = ['queue' => $queue];
                        $this->getEventManager()->dispatch('Weline_Queue::delete', $eventData);
                        $successCount++;
                        $results[] = ['id' => $queueId, 'success' => true];
                        break;
                        
                    case 'stop':
                        $pid = $queue->getPid();
                        if ($pid && Process::isProcessRunning($pid)) {
                            $pname = 'queue-' . $queue->getName() . '-' . $queue->getId();
                            Process::killPid($pid, $pname);
                            Process::unsetLogProcessFilePath($pname);
                            $queue->setPid(0);
                        }
                        $queue->setData(\Weline\Queue\Model\Queue::schema_fields_status, \Weline\Queue\Model\Queue::status_stop);
                        $queue->save();
                        $eventData = ['queue' => $queue];
                        $this->getEventManager()->dispatch('Weline_Queue::stop', $eventData);
                        $successCount++;
                        $results[] = ['id' => $queueId, 'success' => true];
                        break;
                        
                    case 'continue':
                        $pid = $queue->getPid();
                        if ($pid && Process::isProcessRunning($pid)) {
                            $results[] = ['id' => $queueId, 'success' => false, 'msg' => __('有进程运行')];
                            $failCount++;
                            continue 2;
                        }
                        $queue->setData(\Weline\Queue\Model\Queue::schema_fields_status, \Weline\Queue\Model\Queue::status_pending);
                        $queue->setData(\Weline\Queue\Model\Queue::schema_fields_finished, 0);
                        $queue->setData(\Weline\Queue\Model\Queue::schema_fields_pid, 0);
                        $queue->save();
                        $eventData = ['queue' => $queue];
                        $this->getEventManager()->dispatch('Weline_Queue::continue', $eventData);
                        $successCount++;
                        $results[] = ['id' => $queueId, 'success' => true];
                        break;
                        
                    default:
                        $results[] = ['id' => $queueId, 'success' => false, 'msg' => __('不支持的操作')];
                        $failCount++;
                }
            } catch (\Exception $e) {
                $results[] = ['id' => $queueId, 'success' => false, 'msg' => $e->getMessage()];
                $failCount++;
            }
        }
        
        $json['success'] = $successCount > 0;
        $json['msg'] = __('操作完成。成功：%{1}，失败：%{2}', [$successCount, $failCount]);
        $json['results'] = $results;
        $json['successCount'] = $successCount;
        $json['failCount'] = $failCount;
        
        return $this->fetchJson($json);
    }
}

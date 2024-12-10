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
use Weline\Backend\Session\BackendSession;
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
        $this->type  = $type;
    }

    #[Acl('Weline_Queue::index', '队列首页列表', 'mdi mdi-format-list-numbered', '队列首页列表')]
    public function index()
    {
        $this->assign('title', __('消息队列'));
        $this->queue->joinModel(\Weline\Queue\Model\Queue\Type::class, 't', 'main_table.type_id=t.type_id', 'left');
        if ($module = $this->request->getGet('module')) {
            $this->queue->where('t.module_name', $module);
        }
        if ($search = $this->request->getGet('q')) {
            $this->queue->where("concat(main_table.name,main_table.content,main_table.result) like '%$search%'");
        }
        if ($id = $this->request->getGet('id')) {
            $this->queue->where('main_table.' . $this->queue::fields_ID, $id);
        }
        $this->queue->where('t.enable',1)
            ->order('main_table.queue_id');
//        $this->queue->additional('order by CASE status WHEN \'' . \Weline\Queue\Model\Queue::status_running . '\' THEN 0 WHEN \'' . \Weline\Queue\Model\Queue::status_pending . '\' THEN 1 WHEN \'' . \Weline\Queue\Model\Queue::status_done . '\' THEN 2  WHEN \'' . \Weline\Queue\Model\Queue::status_error . '\' THEN  3 END ASC,main_table.update_time DESC');
        $this->queue->pagination()->select()->fetch();
        $this->assign('queues', $this->queue->getItems());
        $this->assign('module', $module);
        $this->assign('pagination', $this->queue->getPagination());
        return $this->fetch();
    }

    #[Acl('Weline_Queue::form', '编辑或者新增', 'mdi mdi-form-textbox', '编辑或者新增')]
    function form()
    {
        if ($this->request->isGet()) {
            $id     = $this->request->getGet('id', 0);
            $queue  = $this->queue->load($id);
            $module = $this->request->getGet('module');
            $dir    = $this->request->getGet('dir');
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
            return $this->fetch();
        }
        $json   = ['code' => 404, 'msg' => ''];
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
        $edit     = 1;
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
                $edit     = 0;
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
        $this->getEventManager()->dispatch('Weline_Queue::' . ($edit ? 'edit' : 'add'), ['queue' => $this->queue]);
        $this->queue->setResult($json['msg'])->save();
        # 写入属性
        /**@var Attributes $attributeModel */
        $attributeModel = ObjectManager::getInstance(Attributes::class);
        $attributes     = $this->request->getPost('attributes', []);
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
            if(!$attr->getId()){
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
                    $json['msg'] = __('设置队列属性失败！请修改重试。%1', $e->getMessage());
                    $this->queue->load($queue_id);
                    $this->queue->setResult($json['msg'])->save();
                    return $this->fetchJson($json);
                }
            }
        }
        # 校验一下队列
        /** @var QueueInterface $execute */
        $execute = ObjectManager::getInstance($this->queue->getType()->getClass());
        $result  = $execute->validate($this->queue);
        if (!$result) {
            $json['msg'] = __('队列校验失败，校验消息：%1', $this->queue->getResult());
            return $this->fetchJson($json);
        }
        # 删除用户的记录数据
        /** @var BackendUserData $userData */
        $userData = ObjectManager::getInstance(BackendUserData::class);
        $userData->deleteScope('queue');
        $json['code'] = 200;
        $json['msg']  = $edit ? __('队列已编辑！等待运行中...') : __('队列已成功创建！等待运行中...');

        return $this->fetchJson($json);
    }

    #[Acl('Weline_Queue::search_type', '获取类型数据', 'mdi mdi-database-arrow-right-outline', '获取类型数据')]
    public function getSearchType(): string
    {
        $json = ['code' => 200, 'msg' => ''];
        $q    = $this->request->getGet('q');
        /** @var \Weline\Queue\Model\Queue\Type $typeModel */
        $typeModel = ObjectManager::getInstance(\Weline\Queue\Model\Queue\Type::class);
        $module    = $this->request->getGet('module', '');
        $dir       = $this->request->getGet('dir', '');
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
        $json     = ['code' => 200, 'msg' => ''];
        $queue_id = $this->request->getGet('id', 0);
        $type_id  = $this->request->getGet('type_id', 0);
        if ($queue_id) {
            $this->queue->load($queue_id);
        }
        if (empty($type_id)) {
            $json['code'] = 404;
            $json['msg']  = __('请选择队列类型后再操作！');
            return $this->fetchJson($json);
        }
        $type = $this->type->load($type_id);
        /** @var BackendUserData $userData */
        $userData     = ObjectManager::getInstance(BackendUserData::class);
        $userData     = $userData->getScope('queue');
        $options_data = [
            'label_class' => 'control-label',
            'attrs' => ['class' => 'form-control w-100', 'scope' => 'queue','file-ext'=>'*','file-size'=>'102400000'],
            'need_array' => 1,
            'values' => $userData,
        ];
        if($this->queue->getId()){
            $options_data['entity'] = $this->queue;
        }else{
            $options_data['values'] =$userData;
        }
        $type->setData($userData);
        $json['data'] = $type->getAttributes($options_data);
        return $this->fetchJson($json);
    }

    #[Acl('Weline_Queue::get_type_data', '获取类型数据', 'mdi mdi-database-arrow-right-outline', '获取类型数据')]
    public function getTypeData()
    {
        $json = ['code' => 404, 'msg' => ''];
        $id   = $this->request->getGet('id');
        if (empty($id)) {
            $json['msg'] = __('请选择要查看的队列');
            return $this->fetchJson($json);
        }
        /** @var \Weline\Queue\Model\Queue\Type $typeModel */
        $typeModel = ObjectManager::getInstance(\Weline\Queue\Model\Queue\Type::class);
        $type      = $typeModel->load($id);
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
            ->where('main_table.' . $this->queue::fields_ID, $id)->find()->fetch();
        if (!$this->queue->getId()) {
            $this->getMessageManager()->addWarning(__('队列不存在'));
            $this->redirect('/component/offcanvas/error', ['msg' => __('队列不存在'), 'reload' => 0]);
        }
        # 加载属性数据
        $type         = $this->queue->getType();
        $options_data = [
            'label_class' => 'control-label',
            'attrs' => ['class' => 'form-control w-100 readonly disabled', 'disabled' => 'disabled'],
            'entity' => $this->queue
        ];
        $attrs        = $type->getAttributes($options_data);
        $this->queue->setData('data', $attrs);
        $this->assign('queue', $this->queue);
        # 如果result结果大于1M，就下载
        $result = $this->queue->getData('result');
        if (!empty($result)) {
            $resultSize = mb_strlen($result);
            if ($resultSize > 1024 * 1024) {
                $dowloadUrl = $this->request->getUrlBuilder()->getBackendUrl('*/backend/queue/dowloadResult', ['id' => $id]);
                $sieMb      = round($resultSize / 1024 / 1024, 2);
                $this->queue->setData('result', __('队列结果过大:%1 Mb。 请<a href="%2">下载队列结果</a>查看。', [$sieMb, $dowloadUrl]));
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
        $result      = $this->queue->getData('result');
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
        if (empty($queue_id)) {
            $this->getMessageManager()->addWarning(__('请选择要操作的队列'));
            $this->redirect($this->request->getReferer());
        }
        $this->queue->load($queue_id);
        if ($this->queue->getStatus() !== $this->queue::status_pending) {
            $this->getMessageManager()->addWarning(__('队列未处于等待状态无法删除！'));
            $this->redirect($this->request->getReferer());
        }
        $this->queue->delete()->fetch();
        $this->getMessageManager()->addSuccess(__('队列已成功删除！'));
        # 队列添加事件
        $this->getEventManager()->dispatch('Weline_Queue::delete', ['queue' => $this->queue]);
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
        $data = $this->queue->getData($this->queue::fields_result);
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
        $data = $this->queue->getData($this->queue::fields_content);
        $this->assign('data', $data);
        return $this->fetch('content');
    }

    #[Acl('Weline_Queue::reset', '重置刊登任务', 'mdi mdi-lock-reset', '重置刊登任务')]
    public function reset()
    {
        $queue_id = $this->request
            ->getParam('id', 0);
        $this->queue->load($queue_id);
        if (!$this->queue->getId()) {
            $this->getMessageManager()->addError(__('队列记录不存在！'));
            $this->redirect($this->request->getReferer());
        }
        # 如果队列有进程，杀死进程
        $pid = $this->queue->getPid();
        if ($pid) {
            $this->getMessageManager()->addError(__('队列有进程，请先杀死进程！进程：%1', $pid));
            $this->redirect($this->request->getReferer());
        }
        # 重置队列
        $this->queue->setData($this->queue::fields_status, \Weline\Queue\Model\Queue::status_pending);
        $this->queue->setData($this->queue::fields_finished, 0);
        $this->queue->save();
        # 队列添加事件
        $this->getEventManager()->dispatch('Weline_Queue::reset', ['queue' => $this->queue]);
        $this->getMessageManager()->addSuccess(__('重置成功！'));
        $this->redirect($this->request->getReferer());
    }

    #[Acl('Weline_Queue::stop', '完成刊登任务', 'mdi mdi-lock-reset', '完成刊登任务')]
    public function stop()
    {
        $queue_id = $this->request
            ->getParam('id', 0);
        $queue    = $this->queue->load($queue_id);
        if (!$queue->getId()) {
            $this->getMessageManager()->addError(__('队列记录不存在！'));
            $this->redirect($this->request->getReferer());
        }
        # 如果队列有进程，杀死进程
        $pid = $queue->getPid();
        if ($pid) {
            $running = Process::isProcessRunning($pid);
            if ($running) {
                $pname  = 'queue-' . $queue->getName() . '-' . $queue->getId();
                $result = Process::killPid($pid, 'queue-' . $queue->getName() . '-' . $queue->getId());
                Process::unsetLogProcessFilePath($pname);
                if ($result) {
                    $this->getMessageManager()->addSuccess(__('队列有进程，已成功杀死进程！进程：%1', $pid));
                } else {
                    $this->getMessageManager()->addError(__('队列有进程，杀死进程失败！进程：%1', $pid));
                    $this->redirect($this->request->getReferer());
                }
            }
            $queue->setPid(0);
        }
        # 暂停队列
        $queue->setData($queue::fields_status, \Weline\Queue\Model\Queue::status_stop);
        $queue->save();
        # 队列添加事件
        $this->getEventManager()->dispatch('Weline_Queue::stop', ['queue' => $this->queue]);
        $this->getMessageManager()->addSuccess(__('操作成功！'));
        $this->redirect($this->request->getReferer());
    }

    #[Acl('Weline_Queue::continue', '继续刊登任务', 'mdi mdi-arrow-right-thin-circle-outline', '继续刊登任务')]
    public function continue()
    {
        $queue_id = $this->request
            ->getParam('id', 0);
        $queue    = $this->queue->load($queue_id);
        if (!$queue->getId()) {
            $this->getMessageManager()->addError(__('队列记录不存在！'));
            $this->redirect($this->request->getReferer());
        }
        # 如果队列有进程，杀死进程
        $pid = $queue->getPid();
        if ($pid) {
            $running = Process::isProcessRunning($pid);
            if ($running) {
                $this->getMessageManager()->addError(__('队列有进程，请先杀死进程！进程：%1', $pid));
                $this->redirect($this->request->getReferer());
            } else {
                $queue->setData($queue::fields_pid, 0);
            }
        }
        # 继续队列
        $queue->setData($queue::fields_status, \Weline\Queue\Model\Queue::status_pending);
        $queue->setData($queue::fields_finished, 0);
        $queue->setData($queue::fields_pid, 0);
        $queue->save();
        # 队列添加事件
        $this->getEventManager()->dispatch('Weline_Queue::continue', ['queue' => $this->queue]);
        $this->getMessageManager()->addSuccess(__('继续成功！'));
        $this->redirect($this->request->getReferer());
    }
}

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

use Weline\Backend\Api\UserData\BackendCurrentUserDataInterface;
use Weline\Acl\Api\Authorization\AccessMode;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Queue\Service\QueueAdminService;
use Weline\Queue\Service\QueueAdminListingView;

#[Acl('Weline_Queue::listing_manager', '队列管理', 'mdi-human-queue', '管理队列信息', 'Weline_Queue::message_service')]
class Queue extends \Weline\Framework\App\Controller\BackendController
{
    private \Weline\Queue\Model\Queue $queue;
    private ?BackendCurrentUserDataInterface $currentUserData = null;

    public function __init()
    {
        parent::__init();

        if ($this->request->isIframe()) {
            $method = (string)$this->request->getRouterData('class/method');
            if (\in_array($method, ['show', 'form', 'getDetailResult', 'getDetailContent'], true)) {
                $this->layoutType = 'default.blank';
            }
        }
    }

    public function __construct(
        \Weline\Queue\Model\Queue $queue,
        private readonly RuntimeProviderResolver $runtimeProviders,
        private readonly QueueAdminService $queueAdminService,
        private readonly QueueAdminListingView $queueAdminListingView,
    ) {
        $this->queue = $queue;
    }

    #[Acl('Weline_Queue::index', '队列首页列表', 'mdi mdi-format-list-numbered', '队列首页列表')]
    public function index()
    {
        $this->assign('title', __('消息队列'));
        $this->assignQueueListingState($this->queueAdminListingView->state((array)$this->request->getGet()));
        return $this->fetch();
    }

    #[Acl('Weline_Queue::index', '队列列表快照', 'mdi mdi-refresh', '队列列表实时快照')]
    public function getSnapshot(): string
    {
        if (!$this->request->isAjax()) {
            return $this->redirect('*/backend/queue', $this->getQueueSnapshotRedirectParams());
        }

        return $this->fetchJson($this->queueAdminListingView->snapshot((array)$this->request->getGet()));
    }

    /**
     * @return array<string, mixed>
     */
    private function getQueueSnapshotRedirectParams(): array
    {
        $params = (array)$this->request->getGet();
        unset($params['isAjax']);

        return $params;
    }

    /**
     * @param array{
     *   queues: array<int, mixed>,
     *   module: mixed,
     *   status: mixed,
     *   q: mixed,
     *   biz_key: string,
     *   stats: array{all:int,pending:int,running:int,done:int,error:int,stop:int},
     *   pagination: mixed
     * } $state
     */
    private function assignQueueListingState(array $state): void
    {
        $this->assign('queues', $state['queues']);
        $this->assign('module', $state['module']);
        $this->assign('status', $state['status']);
        $this->assign('q', $state['q']);
        $this->assign('biz_key', $state['biz_key']);
        $this->assign('stats', $state['stats']);
        $this->assign('pagination', $state['pagination']);
    }

    /**
     * 统一清洗队列输出文本，避免 ANSI 控制符和编码混杂导致前端显示乱码。
     */
    private function normalizeQueueText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // 去掉终端 ANSI 转义序列（颜色、光标移动等）
        $text = (string)\preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $text);
        $text = (string)\preg_replace('/\x1B\][^\x07]*(\x07|\x1B\\\\)/', '', $text);

        // 尝试将常见本地编码统一转为 UTF-8，避免中文“锟斤拷/乱码”
        if (!\mb_check_encoding($text, 'UTF-8')) {
            $detected = \mb_detect_encoding($text, ['UTF-8', 'GB18030', 'GBK', 'GB2312', 'BIG5'], true);
            if (\is_string($detected) && $detected !== '' && strtoupper($detected) !== 'UTF-8') {
                $converted = @\mb_convert_encoding($text, 'UTF-8', $detected);
                if (\is_string($converted) && $converted !== '') {
                    $text = $converted;
                }
            }
        }

        return $text;
    }

    /**
     * 队列详情页兜底渲染：模板异常或空输出时，确保页面最少可见文本。
     */
    private function renderQueueDetailContent(string $data): string
    {
        $normalized = $this->normalizeQueueText($data);
        $this->assign('data', $normalized);
        try {
            $html = (string)$this->fetch('content');
            if (\trim($html) !== '') {
                return $html;
            }
        } catch (\Throwable) {
            // ignore and use fallback html
        }

        $fallbackText = $normalized !== '' ? $normalized : (string)__('暂无内容数据');
        $escaped = \htmlspecialchars($fallbackText, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        return '<div style="padding:16px;"><pre style="margin:0;white-space:pre-wrap;word-break:break-all;font-family:Consolas,Monaco,monospace;font-size:13px;line-height:1.6;">'
            . $escaped .
            '</pre></div>';
    }

    #[Acl('Weline_Queue::form', '编辑或者新增', 'mdi mdi-form-textbox', '编辑或者新增')]
    function form()
    {
        if (!$this->request->isGet()) {
            return $this->legacyMutationGone();
        }

        $id = $this->request->getGet('id', 0);
        $queue = $this->queue->load($id);
        $module = $this->request->getGet('module');
        $dir = $this->request->getGet('dir');
        # 如果队列已经运行则无法修改
        if ($queue->getId() and !$queue->isPending()) {
            $this->redirect('/component/offcanvas/error', ['msg' => __('队列已经运行，无法修改'), 'reload' => 1]);
        }
        if ($queue->getId() and $queue->isFinished()) {
            $this->redirect('/component/offcanvas/error', ['msg' => __('队列已经完成,无法修改'), 'reload' => 1]);
        }
        if (!$queue->getId()) {
            $userData = $this->currentUserData()->getScope('queue');
            $queue->setData($userData);
        }
        $typesResult = $this->queueAdminService->searchTypes([
            'module' => (string)($module ?? ''),
            'dir' => (string)($dir ?? ''),
        ]);
        $types = (array)($typesResult['data'] ?? []);
        $this->assign('title', __('添加队列'));
        $this->assign('queue_types', $types);
        $this->assign('queueData', $queue->getData());
        $this->assign('module', $module);
        $this->assign('dir', $dir);
        $this->layoutType = 'default.blank';
        return $this->fetch();
    }

    #[Acl('Weline_Queue::search_type', '获取类型数据', 'mdi mdi-database-arrow-right-outline', '获取类型数据')]
    public function getSearchType(): string
    {
        return $this->fetchJson($this->queueAdminService->searchTypes((array)$this->request->getGet()));
    }

    #[Acl('Weline_Queue::get_type_attributes', '获取属性数据', 'mdi mdi-database-arrow-right-outline', '获取属性数据')]
    public function getTypeAttributes(): Response
    {
        return $this->legacyMutationGone();
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
        $this->layoutType = 'default.blank';
        $id = $this->request->getGet('id');
        if (empty($id)) {
            MessageManager::warning(__('请选择要查看的队列'));
            $this->redirect('/component/offcanvas/error', ['msg' => __('请选择要查看的队列'), 'reload' => 1]);
        }
        $res = $this->queue->joinModel(\Weline\Queue\Model\Queue\Type::class, 't', 'main_table.type_id=t.type_id', 'left')
            ->where('main_table.' . $this->queue::schema_fields_ID, $id)->find()->fetch();
        if (!$this->queue->getId()) {
            MessageManager::warning(__('队列不存在'));
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

    #[Acl('Weline_Queue::delete', '删除队列', 'mdi mdi-delete', '删除队列', accessMode: AccessMode::EDIT)]
    function getDelete()
    {
        return $this->legacyMutationGone();
    }

    #[Acl('Weline_Queue::result', '查看结果', 'mdi mdi-table-headers-eye', '查看结果')]
    function getDetailResult()
    {
        $this->layoutType = 'default.blank';
        $queue_id = (int)$this->request->getGet('id', 0) ?: (int)$this->request->getParam('id', 0);
        if ($queue_id <= 0) {
            MessageManager::warning(__('请选择要操作的队列'));
            return $this->renderQueueDetailContent('');
        }
        $this->queue->load($queue_id);
        $data = (string)$this->queue->getData($this->queue::schema_fields_result);
        if ($data === '') {
            $process = (string)$this->queue->getProcess(true, false);
            if ($process !== '') {
                $data = $process;
            }
        }
        if ($data === '') {
            $content = (string)$this->queue->getData($this->queue::schema_fields_content);
            if ($content !== '') {
                $data = __('执行结果为空，以下展示任务内容：') . PHP_EOL . $content;
            }
        }
        return $this->renderQueueDetailContent($data);
    }

    #[Acl('Weline_Queue::content', '查看详情', 'mdi mdi-information', '查看详情')]
    function getDetailContent()
    {
        $this->layoutType = 'default.blank';
        $queue_id = (int)$this->request->getGet('id', 0) ?: (int)$this->request->getParam('id', 0);
        if ($queue_id <= 0) {
            $this->getMessageManager()->addWarning(__('请选择要操作的队列'));
            return $this->renderQueueDetailContent('');
        }
        $this->queue->load($queue_id);
        $data = (string)$this->queue->getData($this->queue::schema_fields_content);
        return $this->renderQueueDetailContent($data);
    }

    #[Acl('Weline_Queue::reset', '重置刊登任务', 'mdi mdi-lock-reset', '重置刊登任务', accessMode: AccessMode::EDIT)]
    public function reset()
    {
        return $this->legacyMutationGone();
    }

    #[Acl('Weline_Queue::stop', '完成刊登任务', 'mdi mdi-lock-reset', '完成刊登任务', accessMode: AccessMode::EDIT)]
    public function stop()
    {
        return $this->legacyMutationGone();
    }

    #[Acl('Weline_Queue::continue', '继续刊登任务', 'mdi mdi-arrow-right-thin-circle-outline', '继续刊登任务', accessMode: AccessMode::EDIT)]
    public function continue()
    {
        return $this->legacyMutationGone();
    }

    #[Acl('Weline_Queue::api_action', '旧队列单条控制接口', 'mdi mdi-alert-octagon-outline', '已停用的队列单条控制接口', accessMode: AccessMode::EDIT)]
    public function postApiAction(): Response
    {
        return $this->legacyMutationGone();
    }

    #[Acl('Weline_Queue::api_batch', '旧队列批量控制接口', 'mdi mdi-alert-octagon-outline', '已停用的队列批量控制接口', accessMode: AccessMode::EDIT)]
    public function postApiBatch(): Response
    {
        return $this->legacyMutationGone();
    }

    private function legacyMutationGone(): Response
    {
        return Response::json([
            'code' => 410,
            'success' => false,
            'msg' => (string)__('旧 Queue 控制接口已停用，请使用后台页面操作。'),
        ], 410);
    }

    private function currentUserData(): BackendCurrentUserDataInterface
    {
        if ($this->currentUserData instanceof BackendCurrentUserDataInterface) {
            return $this->currentUserData;
        }

        $provider = $this->runtimeProviders->resolve(BackendCurrentUserDataInterface::class);
        if (!$provider instanceof BackendCurrentUserDataInterface) {
            throw new \RuntimeException('backend_current_user_data_provider_unavailable');
        }

        return $this->currentUserData = $provider;
    }

}

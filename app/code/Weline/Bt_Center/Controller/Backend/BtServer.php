<?php

declare(strict_types=1);

namespace Weline\Bt\Center\Controller\Backend;

use Weline\Bt_Center\Model\BtServer as BtServerModel;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;

#[AclAttribute('Weline_Bt_Center::bt_server', 'BT服务器管理', 'mdi-server', '管理 BT 服务器')]
class BtServer extends BackendController
{
    private function getBtServerModel(): BtServerModel
    {
        return ObjectManager::getInstance(BtServerModel::class);
    }

    #[AclAttribute('Weline_Bt_Center::bt_server_index', '服务器列表', 'mdi-view-list', '查看 BT 服务器列表')]
    public function index(): string
    {
        $platform = trim((string) $this->request->getGet('platform', ''));
        $status = trim((string) $this->request->getGet('status', ''));
        $keyword = trim((string) $this->request->getGet('keyword', ''));

        $query = $this->getBtServerModel()->clear()->select();

        if ($platform !== '') {
            $query->where(BtServerModel::schema_fields_PLATFORM, $platform);
        }

        if ($status !== '' && isset(BtServerModel::getHealthStatusOptions()[$status])) {
            $query->where(BtServerModel::schema_fields_LAST_CHECK_STATUS, $status);
        }

        if ($keyword !== '') {
            $query->where(
                [
                    [BtServerModel::schema_fields_NAME, 'like', "%{$keyword}%"],
                    [BtServerModel::schema_fields_EXTERNAL_URL, 'like', "%{$keyword}%"],
                    [BtServerModel::schema_fields_USERNAME, 'like', "%{$keyword}%"],
                ],
                'OR'
            );
        }

        $query->order(BtServerModel::schema_fields_IS_ENABLED, 'DESC');
        $query->order(BtServerModel::schema_fields_UPDATED_AT, 'DESC');

        $this->assign('servers', $query->fetchArray());
        $this->assign('platform', $platform);
        $this->assign('status', $status);
        $this->assign('keyword', $keyword);
        $this->assign('platformOptions', BtServerModel::getPlatformOptions());
        $this->assign('healthStatusOptions', BtServerModel::getHealthStatusOptions());
        $this->assign('page_title', __('BT 服务器管理'));
        $this->assign('breadcrumb_parent', __('BT 管理中心'));
        $this->assign('breadcrumb_current', __('服务器管理'));

        return $this->fetch();
    }

    #[AclAttribute('Weline_Bt_Center::bt_server_form', '服务器表单', 'mdi-form-select', '创建或编辑 BT 服务器')]
    public function form(): string
    {
        $id = (int) $this->request->getGet('id');

        $server = $this->getBtServerModel()->reset();
        if ($id > 0) {
            $server->load($id);
            if (!$server->getId()) {
                Message::error(__('服务器不存在'));
                return $this->redirect('*/backend/bt-server') ?? '';
            }
        }

        $this->assign('server', $server);
        $this->assign('platformOptions', BtServerModel::getPlatformOptions());
        $this->assign('healthStatusOptions', BtServerModel::getHealthStatusOptions());
        $this->assign('page_title', $id > 0 ? __('编辑 BT 服务器') : __('新增 BT 服务器'));
        $this->assign('breadcrumb_parent', __('BT 管理中心'));
        $this->assign('breadcrumb_current', __('服务器管理'));

        return $this->fetch();
    }

    #[AclAttribute('Weline_Bt_Center::bt_server_save', '保存服务器', 'mdi-content-save', '保存 BT 服务器')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);
        }

        $id = (int) $this->request->getPost('id');
        $data = $this->request->getPost();

        try {
            $server = $this->getBtServerModel()->reset();
            if ($id > 0) {
                $server->load($id);
                if (!$server->getId()) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('服务器不存在'),
                    ]);
                }
            }

            $name = trim((string) ($data['name'] ?? ''));
            $platform = trim((string) ($data['platform'] ?? ''));
            $externalUrl = trim((string) ($data['external_url'] ?? ''));
            $internalUrl = trim((string) ($data['internal_url'] ?? ''));
            $username = trim((string) ($data['username'] ?? ''));
            $password = trim((string) ($data['password'] ?? ''));
            $portInput = trim((string) ($data['port'] ?? ''));
            $port = $portInput !== '' ? (int) $portInput : (int) (parse_url($externalUrl, PHP_URL_PORT) ?: 8888);
            $description = trim((string) ($data['description'] ?? ''));
            $isEnabled = isset($data['is_enabled']) && (string) $data['is_enabled'] !== '0';

            if ($name === '') {
                return $this->jsonResponse(['success' => false, 'message' => __('服务器名称不能为空')]);
            }
            if ($platform === '') {
                return $this->jsonResponse(['success' => false, 'message' => __('云平台不能为空')]);
            }
            if ($externalUrl === '') {
                return $this->jsonResponse(['success' => false, 'message' => __('外网面板地址不能为空')]);
            }
            if (!filter_var($externalUrl, FILTER_VALIDATE_URL)) {
                return $this->jsonResponse(['success' => false, 'message' => __('外网面板地址格式不正确')]);
            }
            if ($internalUrl !== '' && !filter_var($internalUrl, FILTER_VALIDATE_URL)) {
                return $this->jsonResponse(['success' => false, 'message' => __('内网面板地址格式不正确')]);
            }
            if ($username === '') {
                return $this->jsonResponse(['success' => false, 'message' => __('用户名不能为空')]);
            }
            if ($password === '') {
                return $this->jsonResponse(['success' => false, 'message' => __('密码不能为空')]);
            }
            if ($port <= 0 || $port > 65535) {
                return $this->jsonResponse(['success' => false, 'message' => __('端口范围必须在 1 到 65535 之间')]);
            }

            $server->setData(BtServerModel::schema_fields_NAME, $name)
                ->setData(BtServerModel::schema_fields_PLATFORM, $platform)
                ->setData(BtServerModel::schema_fields_EXTERNAL_URL, $externalUrl)
                ->setData(BtServerModel::schema_fields_INTERNAL_URL, $internalUrl)
                ->setData(BtServerModel::schema_fields_USERNAME, $username)
                ->setData(BtServerModel::schema_fields_PASSWORD, $password)
                ->setData(BtServerModel::schema_fields_PORT, $port)
                ->setData(BtServerModel::schema_fields_DESCRIPTION, $description)
                ->setData(BtServerModel::schema_fields_IS_ENABLED, $isEnabled ? 1 : 0);

            $server->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => __('服务器保存成功'),
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('服务器保存失败：%{1}', $e->getMessage()),
            ]);
        }
    }

    #[AclAttribute('Weline_Bt_Center::bt_server_delete', '删除服务器', 'mdi-delete', '删除 BT 服务器')]
    public function postDelete(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);
        }

        $id = (int) $this->request->getPost('id');
        if ($id <= 0) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('服务器ID不能为空'),
            ]);
        }

        try {
            $server = $this->getBtServerModel()->reset();
            $server->load($id);
            if (!$server->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('服务器不存在'),
                ]);
            }

            $deleted = $server->delete()->fetch();
            if (!$deleted) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('删除失败'),
                ]);
            }

            return $this->jsonResponse([
                'success' => true,
                'message' => __('服务器删除成功'),
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('服务器删除失败：%{1}', $e->getMessage()),
            ]);
        }
    }

    private function jsonResponse(array $data): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}

if (!class_exists('Weline\\Bt_Center\\Controller\\Backend\\BtServer', false)) {
    class_alias(BtServer::class, 'Weline\\Bt_Center\\Controller\\Backend\\BtServer');
}

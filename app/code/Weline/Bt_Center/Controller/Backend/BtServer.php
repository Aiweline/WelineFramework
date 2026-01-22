<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Bt_Center\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\Bt_Center\Model\BtServer as BtServerModel;

/**
 * 宝塔服务器管理后台控制器
 */
#[AclAttribute('Weline_Bt_Center::bt_server', '宝塔服务器管理', 'mdi-server', '宝塔服务器管理', '')]
class BtServer extends BackendController
{
    private function getBtServerModel(): BtServerModel
    {
        return ObjectManager::getInstance(BtServerModel::class);
    }

    #[AclAttribute('Weline_Bt_Center::bt_server_index', '查看宝塔服务器列表', 'mdi-view-list', '查看宝塔服务器列表')]
    public function index(): string
    {
        $platform = trim((string)$this->request->getGet('platform', ''));
        $keyword = trim((string)$this->request->getGet('keyword', ''));

        $query = $this->getBtServerModel()->clear()->select();

        if ($platform !== '') {
            $query->where(BtServerModel::fields_PLATFORM, $platform);
        }

        if ($keyword !== '') {
            $query->where(
                [
                    [BtServerModel::fields_NAME, 'like', "%{$keyword}%"],
                    [BtServerModel::fields_EXTERNAL_URL, 'like', "%{$keyword}%"],
                    [BtServerModel::fields_USERNAME, 'like', "%{$keyword}%"],
                ],
                'OR'
            );
        }

        $query->order(BtServerModel::fields_CREATED_AT, 'DESC');

        $servers = $query->fetchArray();
        $platformOptions = BtServerModel::getPlatformOptions();

        $this->assign('servers', $servers);
        $this->assign('platform', $platform);
        $this->assign('keyword', $keyword);
        $this->assign('platformOptions', $platformOptions);

        return $this->fetch();
    }

    #[AclAttribute('Weline_Bt_Center::bt_server_form', '宝塔服务器表单', 'mdi-form-select', '创建/编辑宝塔服务器表单')]
    public function form(): string
    {
        $id = (int)$this->request->getGet('id');

        $server = $this->getBtServerModel()->reset();
        if ($id) {
            $server->load($id);
            if (!$server->getId()) {
                Message::error(__('服务器不存在'));
                return $this->redirect('*/backend/bt_server/index') ?? '';
            }
        }

        $platformOptions = BtServerModel::getPlatformOptions();

        $this->assign('server', $server);
        $this->assign('platformOptions', $platformOptions);

        return $this->fetch();
    }

    #[AclAttribute('Weline_Bt_Center::bt_server_save', '保存宝塔服务器', 'mdi-content-save', '保存宝塔服务器')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);
        }

        $id = (int)$this->request->getPost('id');
        $data = $this->request->getPost();

        try {
            $server = $this->getBtServerModel()->reset();
            if ($id) {
                $server->load($id);
                if (!$server->getId()) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('服务器不存在'),
                    ]);
                }
            }

            $name = trim((string)($data['name'] ?? ''));
            $platform = trim((string)($data['platform'] ?? ''));
            $externalUrl = trim((string)($data['external_url'] ?? ''));
            $internalUrl = trim((string)($data['internal_url'] ?? ''));
            $username = trim((string)($data['username'] ?? ''));
            $password = trim((string)($data['password'] ?? ''));
            $port = (int)($data['port'] ?? 8888);

            if ($name === '') {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('服务器名称不能为空'),
                ]);
            }
            if ($platform === '') {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('云平台不能为空'),
                ]);
            }
            if ($externalUrl === '') {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('外网面板地址不能为空'),
                ]);
            }
            if ($username === '') {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('用户名不能为空'),
                ]);
            }
            if ($password === '') {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('密码不能为空'),
                ]);
            }

            $server->setData(BtServerModel::fields_NAME, $name)
                ->setData(BtServerModel::fields_PLATFORM, $platform)
                ->setData(BtServerModel::fields_EXTERNAL_URL, $externalUrl)
                ->setData(BtServerModel::fields_INTERNAL_URL, $internalUrl)
                ->setData(BtServerModel::fields_USERNAME, $username)
                ->setData(BtServerModel::fields_PASSWORD, $password)
                ->setData(BtServerModel::fields_PORT, $port)
                ->setData(BtServerModel::fields_DESCRIPTION, (string)($data['description'] ?? ''));

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

    #[AclAttribute('Weline_Bt_Center::bt_server_delete', '删除宝塔服务器', 'mdi-delete', '删除宝塔服务器')]
    public function delete(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);
        }

        $id = (int)$this->request->getPost('id');

        if (!$id) {
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

            $server->delete();

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

    /**
     * JSON 响应工具
     */
    private function jsonResponse(array $data): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}

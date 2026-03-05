<?php

declare(strict_types=1);

/*
 * AWS Domains 管理模块
 * AWS 配置管理控制器
 */

namespace Aws\Domains\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Aws\Domains\Model\AwsConfig;
use Aws\Domains\Service\Route53DomainsService;

/**
 * AWS 配置管理后台控制器
 */
#[AclAttribute('Aws_Domains::config', 'AWS配置管理', 'mdi-cog-outline', 'AWS配置管理', '')]
class Config extends BackendController
{
    private function getConfigModel(): AwsConfig
    {
        return ObjectManager::getInstance(AwsConfig::class);
    }

    private function getDomainsService(): Route53DomainsService
    {
        return ObjectManager::getInstance(Route53DomainsService::class);
    }

    /**
     * 配置列表
     */
    #[AclAttribute('Aws_Domains::config_index', '查看AWS配置列表', 'mdi-view-list', '查看AWS配置列表')]
    public function index(): string
    {
        $configs = $this->getConfigModel()->reset()
            ->order(AwsConfig::schema_fields_IS_DEFAULT, 'DESC')
            ->order(AwsConfig::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetchArray();

        $this->assign('configs', $configs);
        $this->assign('regions', AwsConfig::SUPPORTED_REGIONS);

        return $this->fetch();
    }

    /**
     * 配置表单
     */
    #[AclAttribute('Aws_Domains::config_form', 'AWS配置表单', 'mdi-form-select', '创建/编辑AWS配置表单')]
    public function form(): string
    {
        $id = (int)$this->request->getGet('id');

        $config = $this->getConfigModel()->reset();
        if ($id) {
            $config->load($id);
            if (!$config->getId()) {
                Message::error(__('配置不存在'));
                return $this->redirect('aws/backend/config/index');
            }
        }

        $this->assign('config', $config);
        $this->assign('regions', AwsConfig::SUPPORTED_REGIONS);

        return $this->fetch();
    }

    /**
     * 保存配置
     */
    #[AclAttribute('Aws_Domains::config_save', '保存AWS配置', 'mdi-content-save', '保存AWS配置')]
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
            $config = $this->getConfigModel()->reset();
            if ($id) {
                $config->load($id);
                if (!$config->getId()) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('配置不存在'),
                    ]);
                }
            }

            $name = trim((string)($data['name'] ?? ''));
            $accessKeyId = trim((string)($data['access_key_id'] ?? ''));
            $secretAccessKey = trim((string)($data['secret_access_key'] ?? ''));

            if ($name === '') {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('配置名称不能为空'),
                ]);
            }

            if ($accessKeyId === '') {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('Access Key ID 不能为空'),
                ]);
            }

            // 检查名称是否重复
            $existingConfig = $this->getConfigModel()->reset()
                ->where(AwsConfig::schema_fields_NAME, $name);
            if ($id) {
                $existingConfig->where(AwsConfig::schema_fields_CONFIG_ID, $id, '!=');
            }
            $existingConfig = $existingConfig->find();
            if ($existingConfig->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('配置名称已存在'),
                ]);
            }

            $config->setData(AwsConfig::schema_fields_NAME, $name)
                ->setData(AwsConfig::schema_fields_ACCESS_KEY_ID, $accessKeyId)
                ->setData(AwsConfig::schema_fields_REGION, $data['region'] ?? 'us-east-1')
                ->setData(AwsConfig::schema_fields_DESCRIPTION, $data['description'] ?? '')
                ->setData(AwsConfig::schema_fields_IS_ACTIVE, (int)($data['is_active'] ?? 1));

            // 只有在提供了新密钥时才更新
            if ($secretAccessKey !== '' && $secretAccessKey !== '********') {
                $config->setData(AwsConfig::schema_fields_SECRET_ACCESS_KEY, $secretAccessKey);
            } elseif (!$id) {
                // 新配置必须提供密钥
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('Secret Access Key 不能为空'),
                ]);
            }

            // 处理默认配置
            if (!empty($data['is_default'])) {
                $config->setAsDefault();
            }

            $config->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => __('配置保存成功'),
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('配置保存失败：%1', $e->getMessage()),
            ]);
        }
    }

    /**
     * 删除配置
     */
    #[AclAttribute('Aws_Domains::config_delete', '删除AWS配置', 'mdi-delete', '删除AWS配置')]
    public function delete(): string
    {
        $id = (int)$this->request->getGet('id');

        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的配置ID'),
            ]);
        }

        try {
            $config = $this->getConfigModel()->reset()->load($id);
            if (!$config->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('配置不存在'),
                ]);
            }

            $config->delete();

            return $this->jsonResponse([
                'success' => true,
                'message' => __('配置删除成功'),
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('配置删除失败：%1', $e->getMessage()),
            ]);
        }
    }

    /**
     * 测试配置连接
     */
    #[AclAttribute('Aws_Domains::config_test', '测试AWS配置', 'mdi-connection', '测试AWS配置连接')]
    public function test(): string
    {
        $id = (int)$this->request->getGet('id');

        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的配置ID'),
            ]);
        }

        try {
            $config = $this->getConfigModel()->reset()->load($id);
            if (!$config->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('配置不存在'),
                ]);
            }

            if (!$config->isActive()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('配置未启用'),
                ]);
            }

            $service = $this->getDomainsService();
            $service->setConfig($config);

            // 通过获取域名列表来测试连接
            $result = $service->listDomains(null, 1);

            if ($result['success']) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => __('连接测试成功'),
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('连接测试失败：%1', $result['error'] ?? '未知错误'),
                ]);
            }
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('连接测试失败：%1', $e->getMessage()),
            ]);
        }
    }

    /**
     * 设为默认配置
     */
    #[AclAttribute('Aws_Domains::config_set_default', '设为默认AWS配置', 'mdi-star', '设为默认AWS配置')]
    public function setDefault(): string
    {
        $id = (int)$this->request->getGet('id');

        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的配置ID'),
            ]);
        }

        try {
            $config = $this->getConfigModel()->reset()->load($id);
            if (!$config->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('配置不存在'),
                ]);
            }

            $config->setAsDefault()->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => __('已设为默认配置'),
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('操作失败：%1', $e->getMessage()),
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

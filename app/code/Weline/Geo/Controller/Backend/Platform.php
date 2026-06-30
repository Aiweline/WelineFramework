<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Geo\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\Geo\Model\Platform as PlatformModel;
use Weline\Geo\Model\PlatformAccount;
use Weline\Geo\Service\SecretStoreService;
use Weline\Geo\Service\PlatformAdapterService;

/**
 * 平台管理控制器
 * 
 * @package Weline_Geo
 */
#[Acl('Weline_Geo::platform_list', '平台管理', 'mdi-cloud', '平台管理', 'Weline_Geo::geo_manager')]
class Platform extends BackendController
{
    /**
     * 平台列表
     * 
     * @return string
     */
    #[Acl('Weline_Geo::platform_list_index', '查看平台列表', 'mdi-cloud', '查看平台列表')]
    public function index(): string
    {
        try {
            /** @var Platform $platformModel */
            $platformModel = ObjectManager::getInstance(PlatformModel::class);
            $platforms = $platformModel->select()->fetchArray();
            
            $this->assign('platforms', $platforms);
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载平台列表失败：%{1}', $e->getMessage()));
            $this->assign('platforms', []);
            return $this->fetch();
        }
    }

    /**
     * 编辑平台
     * 
     * @return string
     */
    #[Acl('Weline_Geo::platform_edit', '编辑平台', 'mdi-pencil', '编辑平台')]
    public function edit(): string
    {
        try {
            $id = (int)$this->request->getParam('id', 0);
            
            /** @var Platform $platformModel */
            $platformModel = ObjectManager::getInstance(PlatformModel::class);
            
            if ($id > 0) {
                $platform = $platformModel->load($id);
                if (!$platform->getId()) {
                    Message::error(__('平台不存在'));
                    $this->redirect('geo/backend/platform');
                    return '';
                }
            } else {
                $platform = $platformModel;
            }

            // 获取支持的平台代码
            /** @var PlatformAdapterService $adapterService */
            $adapterService = ObjectManager::getInstance(PlatformAdapterService::class);
            $supportedPlatforms = $adapterService->getSupportedPlatforms();
            
            $this->assign('platform', $platform);
            $this->assign('supported_platforms', $supportedPlatforms);
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载平台失败：%{1}', $e->getMessage()));
            $this->redirect('geo/backend/platform');
            return '';
        }
    }

    /**
     * 保存平台
     * 
     * @return string
     */
    #[Acl('Weline_Geo::platform_save', '保存平台', 'mdi-content-save', '保存平台')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        try {
            $id = (int)$this->request->getPost('id', 0);
            $platformCode = $this->request->getPost('platform_code', '');
            $platformName = $this->request->getPost('platform_name', '');
            $apiEndpoint = $this->request->getPost('api_endpoint', '');
            $feedFormat = $this->request->getPost('feed_format', 'json_feed');
            $isEnabled = (int)$this->request->getPost('is_enabled', 0);
            $config = $this->request->getPost('config', '{}');

            if (empty($platformCode) || empty($platformName)) {
                return $this->jsonResponse(false, __('请填写平台代码和名称'));
            }

            /** @var Platform $platformModel */
            $platformModel = ObjectManager::getInstance(PlatformModel::class);
            
            if ($id > 0) {
                $platform = $platformModel->load($id);
                if (!$platform->getId()) {
                    return $this->jsonResponse(false, __('平台不存在'));
                }
            } else {
                $platform = $platformModel;
            }

            $platform->setData([
                PlatformModel::schema_fields_PLATFORM_CODE => $platformCode,
                PlatformModel::schema_fields_PLATFORM_NAME => $platformName,
                PlatformModel::schema_fields_API_ENDPOINT => $apiEndpoint,
                PlatformModel::schema_fields_FEED_FORMAT => $feedFormat,
                PlatformModel::schema_fields_IS_ENABLED => $isEnabled,
                PlatformModel::schema_fields_CONFIG => $config,
            ]);

            $platform->save();

            return $this->jsonResponse(true, __('保存成功'));
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('保存失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 账户管理
     * 
     * @return string
     */
    #[Acl('Weline_Geo::platform_account', '平台账户管理', 'mdi-account', '平台账户管理')]
    public function account(): string
    {
        try {
            $platformId = (int)$this->request->getParam('platform_id', 0);
            
            if ($platformId <= 0) {
                Message::error(__('请选择平台'));
                $this->redirect('geo/backend/platform');
                return '';
            }

            /** @var Platform $platformModel */
            $platformModel = ObjectManager::getInstance(PlatformModel::class);
            $platform = $platformModel->load($platformId);
            
            if (!$platform->getId()) {
                Message::error(__('平台不存在'));
                $this->redirect('geo/backend/platform');
                return '';
            }

            /** @var PlatformAccount $accountModel */
            $accountModel = ObjectManager::getInstance(PlatformAccount::class);
            $accounts = $accountModel
                ->where('platform_id', $platformId)
                ->select()
                ->fetchArray();

            $this->assign('platform', $platform);
            $this->assign('accounts', $accounts);
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载账户列表失败：%{1}', $e->getMessage()));
            $this->redirect('geo/backend/platform');
            return '';
        }
    }

    /**
     * 保存账户
     * 
     * @return string
     */
    #[Acl('Weline_Geo::platform_account_save', '保存账户', 'mdi-content-save', '保存账户')]
    public function saveAccount(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        try {
            $id = (int)$this->request->getPost('id', 0);
            $platformId = (int)$this->request->getPost('platform_id', 0);
            $accountName = $this->request->getPost('account_name', '');
            $apiKey = $this->request->getPost('api_key', '');
            $apiSecret = $this->request->getPost('api_secret', '');
            $isDefault = (int)$this->request->getPost('is_default', 0);
            $isActive = (int)$this->request->getPost('is_active', 0);
            $config = $this->request->getPost('config', '{}');

            if ($platformId <= 0 || empty($accountName) || empty($apiKey)) {
                return $this->jsonResponse(false, __('请填写必填项'));
            }

            /** @var SecretStoreService $secretStore */
            $secretStore = ObjectManager::getInstance(SecretStoreService::class);
            
            /** @var PlatformAccount $accountModel */
            $accountModel = ObjectManager::getInstance(PlatformAccount::class);
            
            if ($id > 0) {
                $account = $accountModel->load($id);
                if (!$account->getId()) {
                    return $this->jsonResponse(false, __('账户不存在'));
                }
            } else {
                $account = $accountModel;
            }

            // 加密API密钥
            $encryptedApiKey = $secretStore->encryptApiKey($apiKey);
            $encryptedApiSecret = !empty($apiSecret) ? $secretStore->encryptApiKey($apiSecret) : '';

            // 如果设置为默认账户，取消其他默认账户
            if ($isDefault) {
                $accountModel
                    ->where('platform_id', $platformId)
                    ->where('is_default', 1)
                    ->update(['is_default' => 0]);
            }

            $account->setData([
                PlatformAccount::schema_fields_PLATFORM_ID => $platformId,
                PlatformAccount::schema_fields_ACCOUNT_NAME => $accountName,
                PlatformAccount::schema_fields_API_KEY => $encryptedApiKey,
                PlatformAccount::schema_fields_API_SECRET => $encryptedApiSecret,
                PlatformAccount::schema_fields_IS_DEFAULT => $isDefault,
                PlatformAccount::schema_fields_IS_ACTIVE => $isActive,
                PlatformAccount::schema_fields_CONFIG => $config,
                PlatformAccount::schema_fields_STATUS => PlatformAccount::STATUS_PENDING,
            ]);

            $account->save();

            return $this->jsonResponse(true, __('保存成功'));
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('保存失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 测试连接
     * 
     * @return string
     */
    #[Acl('Weline_Geo::platform_test', '测试连接', 'mdi-connection', '测试连接')]
    public function testConnection(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        try {
            $accountId = (int)$this->request->getPost('account_id', 0);
            
            if ($accountId <= 0) {
                return $this->jsonResponse(false, __('请选择账户'));
            }

            /** @var PlatformAccount $accountModel */
            $accountModel = ObjectManager::getInstance(PlatformAccount::class);
            $account = $accountModel->load($accountId);
            
            if (!$account->getId()) {
                return $this->jsonResponse(false, __('账户不存在'));
            }

            /** @var Platform $platformModel */
            $platformModel = ObjectManager::getInstance(PlatformModel::class);
            $platform = $platformModel->load($account->getData(PlatformAccount::schema_fields_PLATFORM_ID));

            /** @var PlatformAdapterService $adapterService */
            $adapterService = ObjectManager::getInstance(PlatformAdapterService::class);
            $adapter = $adapterService->getAdapter($platform);

            if (!$adapter) {
                return $this->jsonResponse(false, __('平台适配器不存在'));
            }

            $result = $adapter->testConnection($account);

            // 更新账户状态
            $account->setData([
                PlatformAccount::schema_fields_STATUS => $result ? PlatformAccount::STATUS_ACTIVE : PlatformAccount::STATUS_FAILED,
                PlatformAccount::schema_fields_LAST_TEST_TIME => time(),
                PlatformAccount::schema_fields_LAST_TEST_MESSAGE => $result ? '连接成功' : '连接失败',
            ]);
            $account->save();

            return $this->jsonResponse($result, $result ? __('连接成功') : __('连接失败'));
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('测试失败：%{1}', $e->getMessage()));
        }
    }
}

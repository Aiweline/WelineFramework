<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Controller\System;

use Weline\Acl\Api\Authorization\AccessMode;
use Weline\Backend\Config\KeysInterface;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Exception;
use Weline\Framework\Cache\Console\Cache\Clear;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Ui\FormKey;
use Weline\SystemConfig\Api\ConfigStore as SystemConfig;

#[Acl('Weline_Backend::system_config_set', '保存系统配置', 'mdi-content-save', '保存后台系统配置', 'Weline_Backend::system_config_group')]
class Config extends \Weline\Framework\App\Controller\BackendController
{
    protected function csrf(): string
    {
        return FormKey::key_name;
    }

    #[Acl('Weline_Backend::system_config_set_save', '保存系统配置', 'mdi-content-save', '保存后台系统配置', 'Weline_Backend::system_config_set', accessMode: AccessMode::EDIT)]
    public function set(): string
    {
        if (!$this->request->isPost()) {
            return $this->fail(__('无效的请求方法'), 405);
        }

        $post = $this->request->getPost();
        $key = trim((string)($post['key'] ?? ''));
        $value = $post['value'] ?? null;
        $moduleParam = trim((string)($post['module'] ?? ''));
        $responseType = $moduleParam === 'json' ? 'json' : '';
        $module = $moduleParam !== '' && $moduleParam !== 'json' ? $moduleParam : KeysInterface::start_module;
        $scope = trim((string)($post['scope'] ?? ''));
        $locale = trim((string)($post['locale'] ?? ''));

        $validationError = $this->validatePayload($key, $value, $module, $scope, $locale);
        if ($validationError !== null) {
            return $this->fail($validationError, 400, $responseType);
        }
        try {
            if ($scope !== '') {
                /** @var SystemConfig $systemConfig */
                $systemConfig = ObjectManager::getInstance(SystemConfig::class);
                $systemConfig->setScopedConfig(
                    key: (string)$key,
                    value: (string)$value,
                    module: $module,
                    area: SystemConfig::area_BACKEND,
                    scope: $scope,
                    locale: $locale !== '' ? $locale : SystemConfig::LOCALE_DEFAULT,
                    options: ['operation' => 'backend_system_config_set']
                );
            } else {
                /**@var \Weline\Backend\Model\Config $config */
                $config = ObjectManager::getInstance(\Weline\Backend\Model\Config::class);
                $config->setConfig($key, (string)$value, 'Weline_Backend');
            }
        } catch (Exception $e) {
            $this->getMessageManager()->addWarning(__('保存失败，请重试！%{1}', $e->getMessage()));
            return $this->redirect($this->request->getReferer());
        }
        $fetchName = 'fetch' . ucfirst($responseType);
        if (!method_exists($this, $fetchName)) {
            $this->getMessageManager()->addWarning(__('保存失败，请重试!不支持的类型：%{1}', $responseType));
            return $this->redirect($this->request->getReferer());
        }
        # 清理缓存
        /**@var Clear $cache */
        $cache = ObjectManager::getInstance(Clear::class);
        $cache->execute(['-f']);
        if (ob_get_level() > 0 && ob_get_length() > 0) {
            ob_clean();
        }
        try {
            if ($responseType === 'json') {
                return $this->$fetchName($this->success('保存成功!'));
            } else {
                $this->getMessageManager()->addSuccess(__('保存成功,缓存清理成功！'));
                return $this->redirect($this->request->getReferer());
            }
        } catch (\Exception $exception) {
            if ($responseType === 'json') {
                return $this->$fetchName($this->error(__('保存失败! %{1}', $exception->getMessage())));
            } else {
                $this->getMessageManager()->addWarning(__('保存失败! %{1}', $exception->getMessage()));
                return $this->redirect($this->request->getReferer());
            }
        }
    }

    private function validatePayload(string $key, mixed $value, string $module, string $scope, string $locale): ?string
    {
        if ($key === '') {
            return __('配置 key 不能为空');
        }
        if (!preg_match('/^[A-Za-z0-9_.:\/-]{1,191}$/', $key)) {
            return __('配置 key 格式无效');
        }
        if (!is_scalar($value) && $value !== null) {
            return __('配置 value 必须是标量值');
        }
        if (strlen((string)$value) > 65535) {
            return __('配置 value 长度不能超过 %{1} 字符', 65535);
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*_[A-Za-z][A-Za-z0-9]*$/', $module)) {
            return __('配置 module 格式无效');
        }
        if ($scope !== '' && !preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){0,2}$/', $scope)) {
            return __('配置 scope 格式无效');
        }
        if ($locale !== '' && !preg_match('/^(default|[a-z]{2,3}(?:_[A-Za-z0-9]{2,8}){1,3})$/', $locale)) {
            return __('配置 locale 格式无效');
        }

        return null;
    }

    private function fail(string $message, int $code = 400, string $responseType = ''): string
    {
        if ($responseType === 'json' || $this->isJsonRequest()) {
            return $this->fetchJson($this->error($message, '', $code));
        }

        MessageManager::error($message);
        return $this->redirect($this->request->getReferer());
    }

    private function isJsonRequest(): bool
    {
        $acceptHeader = $this->request->getHeader('ACCEPT');
        $accept = strtolower(is_array($acceptHeader) ? implode(',', $acceptHeader) : (string)$acceptHeader);

        return $this->request->isAjax()
            || str_contains($accept, 'application/json')
            || $this->isJsonContentType($this->request->getContentType());
    }
}

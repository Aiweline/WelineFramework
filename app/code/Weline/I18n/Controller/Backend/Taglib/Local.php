<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/2 13:39:02
 */

namespace Weline\I18n\Controller\Backend\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\TaglibLocalDescriptionNormalizer;
use Weline\I18n\Service\TaglibLocalFormService;

class Local extends \Weline\Framework\App\Controller\BackendController
{
    /** 兼容独立打开翻译页时使用 blank 布局 */
    protected ?string $layoutType = 'default.blank';

    public function get()
    {
        if ($this->request->isIframe()) {
            $this->suppressEmbedChrome();
        }

        $modelName = (string)$this->request->getGet('model', '');
        $value = (string)$this->request->getGet('value', '');
        $field = (string)$this->request->getGet('field', '');
        $id = (string)$this->request->getGet('id', '');
        $search = (string)$this->request->getGet('search', '');

        /** @var TaglibLocalFormService $formService */
        $formService = ObjectManager::getInstance(TaglibLocalFormService::class);
        $formPayload = $formService->buildFormPayload($modelName, $field, $id, $value, $search);
        if (($formPayload['success'] ?? false) !== true) {
            $this->getMessageManager()->addError((string)($formPayload['message'] ?? __('加载翻译表单失败')));
            $this->redirect(404);
        }

        $data = is_array($formPayload['data'] ?? null) ? $formPayload['data'] : [];
        $localDescriptions = [];
        foreach ((array)($data['locales'] ?? []) as $localeRow) {
            if (!is_array($localeRow)) {
                continue;
            }
            $localDescriptions[] = [
                $data['id_field'] ?? 'id' => $id,
                'local_code' => (string)($localeRow['local_code'] ?? ''),
                $field => (string)($localeRow['value'] ?? ''),
                'local' => is_array($localeRow['local'] ?? null) ? $localeRow['local'] : [],
            ];
        }

        $this->assign('local_descriptions', $localDescriptions);
        $this->assign('translate_field', $field);
        $this->assign('id_field', (string)($data['id_field'] ?? 'id'));
        $this->assign('value', $value);
        $this->assign('id', $id);
        $this->assign('model', $modelName);
        $this->assign('field', $field);
        $this->assign('is_embed', $this->request->isIframe());
        $this->assign('req', [
            'search' => $search,
            'model' => $modelName,
            'id' => $id,
            'value' => $value,
            'field' => $field,
        ]);
        $params = $this->request->getGet();
        $action = $this->request->getUrlBuilder()->getBackendUrl('i18n/backend/taglib/local', $params);
        $this->assign('action', $action);
        return $this->fetch();
    }

    public function post()
    {
        $isAsyncRequest = $this->isAsyncRequest();
        $modelName = $this->resolveTaglibParam('model');
        if ($modelName === '') {
            $this->getMessageManager()->addError(__('请设置local标签model属性！'));
            $this->redirect(404);
        }
        $value = $this->resolveTaglibParam('value');
        if ($value === '') {
            $this->getMessageManager()->addError(__('请传输local标签值！'));
            $this->redirect(404);
        }
        $field = $this->resolveTaglibParam('field');
        if ($field === '') {
            $this->getMessageManager()->addError(__('请选择一个字段！'));
            $this->redirect(404);
        }
        $id = $this->resolveTaglibParam('id');
        if ($id === '') {
            $this->getMessageManager()->addError(__('请设置local标签id属性！'));
            $this->redirect(404);
        }
        
        // 判断字段是否是 config 嵌套字段
        $isConfigField = str_starts_with($field, 'config.');
        $configPath = $isConfigField ? substr($field, 7) : '';
        
        # 更新翻译
        $descriptions = $this->request->getPost('description');
        if (!is_array($descriptions) || $descriptions === []) {
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(false, (string)__('请填写翻译内容'), []);
            }
            $this->getMessageManager()->addError(__('请填写翻译内容'));
            return $this->get();
        }
        /**@var \Weline\I18n\LocalModel $model */
        $model = ObjectManager::getInstance($modelName);
        $descriptions = TaglibLocalDescriptionNormalizer::prepareRows(
            $model,
            $descriptions,
            $id,
            $isConfigField ? [$field] : [],
        );
        if ($descriptions === []) {
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(false, (string)__('请填写翻译内容'), []);
            }
            $this->getMessageManager()->addError(__('请填写翻译内容'));
            return $this->get();
        }

        $insertDesriptions = [];
        
        if ($isConfigField) {
            // 处理 config 嵌套字段：需要更新 JSON 数据
            foreach ($descriptions as $description) {
                // 获取现有的 config 数据
                $existingModel = clone $model;
                $existingModel->clear()
                    ->where($model::schema_fields_ID, $description[$model::schema_fields_ID])
                    ->where($model::schema_fields_local_code, $description[$model::schema_fields_local_code])
                    ->find()
                    ->fetch();
                
                $configData = [];
                if ($existingModel->getId()) {
                    $existingConfig = $existingModel->getData('config');
                    $configData = $existingConfig ? json_decode($existingConfig, true) : [];
                }
                
                // 设置嵌套值
                $configData = $this->setNestedValue($configData, $configPath, $description[$field]);
                
                // 更新描述数据
                $description['config'] = json_encode($configData, JSON_UNESCAPED_UNICODE);
                unset($description[$field]); // 移除虚拟字段
                $insertDesriptions[] = TaglibLocalDescriptionNormalizer::prepareRows($model, [$description], $id)[0] ?? [];
            }

            $insertDesriptions = array_values(array_filter($insertDesriptions));
            if ($insertDesriptions === []) {
                if ($isAsyncRequest) {
                    return $this->asyncJsonResponse(false, (string)__('请填写翻译内容'), []);
                }
                $this->getMessageManager()->addError(__('请填写翻译内容'));
                return $this->get();
            }
            
            // 使用 config 字段更新
            $model->reset()->insert($insertDesriptions, $model::schema_fields_ID . ',local_code', 'config')->fetch();
        } else {
            // 处理普通字段
            $insertDesriptions = $descriptions;
            $model->reset()->insert($insertDesriptions, $model::schema_fields_ID . ',local_code', $field)->fetch();
        }

        if ($isAsyncRequest) {
            return $this->asyncJsonResponse(true, (string)__('翻译完成!'), [
                'model' => $modelName,
                'id' => $id,
                'field' => $field,
            ]);
        }
        
        $this->getMessageManager()->addSuccess(__('翻译完成!'));
        return $this->get();
    }

    private function isAsyncRequest(): bool
    {
        $accept = strtolower((string)($this->request->getHeader('Accept') ?? ''));
        return $this->request->isAjax() || str_contains($accept, 'application/json');
    }

    private function asyncJsonResponse(bool $success, string $message, array $data = []): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
        return json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function suppressEmbedChrome(): void
    {
        $meta = $this->getTemplate()->getData('meta');
        $meta = is_array($meta) ? $meta : [];
        $meta['showPageHeader'] = false;
        $meta['showMessages'] = false;
        $meta['class'] = trim(trim((string)($meta['class'] ?? '')) . ' w-local-translation-embed');
        $this->assign('meta', $meta);
        $this->assign('layoutShowPageHeader', false);
        $this->assign('layoutShowMessages', false);
    }

    private function resolveTaglibParam(string $name): string
    {
        $post = $this->request->getPost($name, '');
        if (is_array($post)) {
            foreach ($post as $candidate) {
                if (is_scalar($candidate) && trim((string)$candidate) !== '') {
                    return trim((string)$candidate);
                }
            }
            return '';
        }
        $post = trim((string)$post);
        if ($post !== '') {
            return $post;
        }

        $get = $this->request->getGet($name, '');
        if (is_array($get)) {
            foreach ($get as $candidate) {
                if (is_scalar($candidate) && trim((string)$candidate) !== '') {
                    return trim((string)$candidate);
                }
            }
            return '';
        }

        return trim((string)$get);
    }
    
    /**
     * 从嵌套数组中获取值
     * 
     * @param array $data
     * @param string $path 点号分隔的路径，如：demo.title
     * @return mixed|null
     */
    private function getNestedValue(array $data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;
        
        foreach ($keys as $key) {
            if (!isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }
        
        return $value;
    }
    
    /**
     * 在嵌套数组中设置值
     * 
     * @param array $data
     * @param string $path 点号分隔的路径，如：demo.title
     * @param mixed $value
     * @return array
     */
    private function setNestedValue(array $data, string $path, $value): array
    {
        $keys = explode('.', $path);
        $current = &$data;
        
        foreach ($keys as $index => $key) {
            if ($index === count($keys) - 1) {
                $current[$key] = $value;
            } else {
                if (!isset($current[$key]) || !is_array($current[$key])) {
                    $current[$key] = [];
                }
                $current = &$current[$key];
            }
        }
        
        return $data;
    }
}

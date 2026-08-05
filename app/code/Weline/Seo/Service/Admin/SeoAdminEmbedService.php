<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Admin;

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoSubject;
use Weline\Seo\Service\SuggestionService;

class SeoAdminEmbedService
{
    public function saveSubject(array $params): array
    {
        $subjectId = (int)($params['subject_id'] ?? 0);
        $title = trim((string)($params['title'] ?? ''));
        $url = trim((string)($params['url'] ?? ''));
        $description = trim((string)($params['description'] ?? ''));
        $scope = (string)($params['scope'] ?? '');
        $module = trim((string)($params['module'] ?? ''));
        $subjectType = (string)($params['subject_type'] ?? SeoSubject::SUBJECT_TYPE_PAGE);
        $subjectEntityId = (int)($params['subject_entity_id'] ?? 0);
        $status = (int)($params['status'] ?? SeoSubject::STATUS_ENABLED);
        $locale = (string)($params['locale'] ?? 'zh-CN');

        if ($title === '') {
            return ['success' => false, 'message' => (string)__('标题不能为空')];
        }

        try {
            /** @var SeoSubject $subjectModel */
            $subjectModel = ObjectManager::getInstance(SeoSubject::class);
            if ($subjectId > 0) {
                $subjectModel->load($subjectId);
                if (!$subjectModel->getId()) {
                    return ['success' => false, 'message' => (string)__('主体不存在')];
                }
            }

            $subjectModel->setData(SeoSubject::schema_fields_TITLE, $title)
                ->setData(SeoSubject::schema_fields_URL, $url)
                ->setData(SeoSubject::schema_fields_DESCRIPTION, $description)
                ->setData(SeoSubject::schema_fields_SCOPE, $scope)
                ->setData(SeoSubject::schema_fields_MODULE, $module)
                ->setData(SeoSubject::schema_fields_SUBJECT_TYPE, $subjectType)
                ->setData(SeoSubject::schema_fields_SUBJECT_ID, $subjectEntityId)
                ->setData(SeoSubject::schema_fields_STATUS, $status)
                ->setData(SeoSubject::schema_fields_LOCALE, $locale)
                ->save();

            return [
                'success' => true,
                'message' => (string)__('保存成功'),
                'data' => ['subject_id' => $subjectModel->getId()],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => (string)__('保存失败：%{1}', $e->getMessage())];
        }
    }

    public function deleteSubject(array $params): array
    {
        $subjectId = (int)($params['subject_id'] ?? 0);
        if ($subjectId <= 0) {
            return ['success' => false, 'message' => (string)__('无效的主体ID')];
        }

        try {
            /** @var SeoSubject $subjectModel */
            $subjectModel = ObjectManager::getInstance(SeoSubject::class);
            $subjectModel->load($subjectId);
            if (!$subjectModel->getId()) {
                return ['success' => false, 'message' => (string)__('主体不存在')];
            }
            $subjectModel->delete();
            return ['success' => true, 'message' => (string)__('删除成功')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => (string)__('删除失败：%{1}', $e->getMessage())];
        }
    }

    public function refreshSuggestion(array $params): array
    {
        $subjectId = (int)($params['subject_id'] ?? 0);
        if ($subjectId <= 0) {
            return ['success' => false, 'message' => (string)__('无效的主体ID')];
        }

        try {
            /** @var SuggestionService $suggestionService */
            $suggestionService = ObjectManager::getInstance(SuggestionService::class);
            $suggestion = $suggestionService->generateSuggestion($subjectId, true);

            return [
                'success' => true,
                'message' => (string)__('已刷新建议'),
                'data' => [
                    'suggestion' => $suggestion->getData(),
                ],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => (string)__('刷新失败：%{1}', $e->getMessage())];
        }
    }
}

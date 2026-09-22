<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Rest\V1;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendRestController;

/**
 * 远程协助翻译 REST（薄壳 → i18n_remote_translation Query）。
 */
#[Acl(
    'Weline_I18n::rest_v1_remote_translation',
    '远程协助翻译',
    'translate',
    '远程取未译、录入与词典收集',
    'Weline_I18n::i18n_dictionaries'
)]
class RemoteTranslation extends BackendRestController
{
    /**
     * @return string JSON
     * @Document(summary='远程取未译词条', description='按网站语种分页返回未译条目。', tags=['I18n','远程翻译'], category='远程协助翻译')
     * @example
     * Method: POST
     * Path: /{api_admin}/weline_i18n/rest/v1/RemoteTranslation/postPending
     * Body: {"website_id":0,"locales":["en_US"],"limit":50,"cursor":null}
     * @example-end
     */
    #[Acl('Weline_I18n::rest_v1_remote_translation_pending', '远程取未译', 'list')]
    public function postPending(): string
    {
        try {
            $body = $this->bodyParams();
            $locales = $body['locales'] ?? [];
            if (!\is_array($locales)) {
                return $this->error((string)__('locales 必须是数组'), '', 422);
            }
            $cursor = $body['cursor'] ?? null;
            $result = $this->executeQuery('remoteTranslationPending', [
                'website_id' => (int)($body['website_id'] ?? -1),
                'locales' => $locales,
                'limit' => (int)($body['limit'] ?? 50),
                'cursor' => \is_string($cursor) ? $cursor : null,
            ]);

            return $this->success((string)__('获取未译成功'), \is_array($result) ? $result : []);
        } catch (\Throwable $e) {
            return $this->mapException($e, (string)__('获取未译失败'));
        }
    }

    /**
     * @return string JSON
     * @Document(summary='远程录入译文', description='冲突跳过；成功写入后 publishLocale。', tags=['I18n','远程翻译'], category='远程协助翻译')
     * @example
     * Method: POST
     * Path: /{api_admin}/weline_i18n/rest/v1/RemoteTranslation/postIngest
     * Body: {"website_id":0,"items":[{"source":"你好","locale":"en_US","translation":"Hello"}]}
     * @example-end
     */
    #[Acl('Weline_I18n::rest_v1_remote_translation_ingest', '远程录入译文', 'upload')]
    public function postIngest(): string
    {
        try {
            $body = $this->bodyParams();
            $items = $body['items'] ?? [];
            if (!\is_array($items)) {
                return $this->error((string)__('items 必须是数组'), '', 422);
            }
            $result = $this->executeQuery('remoteTranslationIngest', [
                'website_id' => (int)($body['website_id'] ?? -1),
                'items' => $items,
            ]);

            return $this->success((string)__('录入完成'), \is_array($result) ? $result : []);
        } catch (\Throwable $e) {
            return $this->mapException($e, (string)__('录入失败'));
        }
    }

    /**
     * @return string JSON
     * @Document(summary='启动远程词典收集', description='返回 task_id；不入队站内 AI。', tags=['I18n','远程翻译'], category='远程协助翻译')
     * @example
     * Method: POST
     * Path: /{api_admin}/weline_i18n/rest/v1/RemoteTranslation/postCollectStart
     * Body: {"website_id":0}
     * @example-end
     */
    #[Acl('Weline_I18n::rest_v1_remote_translation_collect_start', '启动远程收集', 'play')]
    public function postCollectStart(): string
    {
        try {
            $body = $this->bodyParams();
            $result = $this->executeQuery('remoteTranslationCollectStart', [
                'owner_key' => $this->ownerKey(),
                'website_id' => (int)($body['website_id'] ?? 0),
            ]);

            return $this->success((string)__('收集任务已启动'), \is_array($result) ? $result : []);
        } catch (\Throwable $e) {
            return $this->mapException($e, (string)__('启动收集失败'));
        }
    }

    /**
     * @return string JSON
     * @Document(summary='远程收集状态', description='按 task_id 轮询；非所有者统一 404。', tags=['I18n','远程翻译'], category='远程协助翻译')
     * @example
     * Method: GET
     * Path: /{api_admin}/weline_i18n/rest/v1/RemoteTranslation/getCollectStatus?task_id=…
     * @example-end
     */
    #[Acl('Weline_I18n::rest_v1_remote_translation_collect_status', '远程收集状态', 'clock')]
    public function getCollectStatus(): string
    {
        try {
            $taskId = (string)$this->request->getParam('task_id', '');
            $result = $this->executeQuery('remoteTranslationCollectStatus', [
                'task_id' => $taskId,
                'owner_key' => $this->ownerKey(),
            ]);

            return $this->success((string)__('获取状态成功'), \is_array($result) ? $result : []);
        } catch (\Throwable $e) {
            return $this->mapException($e, (string)__('获取状态失败'));
        }
    }

    protected function executeQuery(string $operation, array $params): mixed
    {
        return w_query('i18n_remote_translation', $operation, $params);
    }

    /** @return array<string,mixed> */
    private function bodyParams(): array
    {
        $params = $this->request->getBodyParams();
        if (!\is_array($params)) {
            $params = [];
        }

        return $params;
    }

    private function ownerKey(): string
    {
        $userId = $this->session->getUserId();
        if ($userId !== null && (string)$userId !== '') {
            return 'backend:' . (string)$userId;
        }
        $sessionId = $this->session->getSession()->getId();
        if ($sessionId !== '') {
            return 'session:' . hash('sha256', $sessionId);
        }

        return 'anonymous';
    }

    private function mapException(\Throwable $e, string $fallback): string
    {
        $code = (int)$e->getCode();
        if ($code === 404) {
            return $this->error($e->getMessage() !== '' ? $e->getMessage() : $fallback, '', 404);
        }
        if ($code === 422 || $e instanceof \InvalidArgumentException) {
            return $this->error($e->getMessage() !== '' ? $e->getMessage() : $fallback, '', 422);
        }

        return $this->exception($e, $fallback);
    }
}

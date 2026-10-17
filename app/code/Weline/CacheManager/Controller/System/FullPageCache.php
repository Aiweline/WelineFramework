<?php

declare(strict_types=1);

namespace Weline\CacheManager\Controller\System;

use Weline\CacheManager\Service\FullPageCacheAdminService;
use Weline\Framework\Acl\Acl;

#[Acl('Weline_CacheManager::full_page_cache', '全页缓存', 'settings', 'FPC 开关与开发穿透签章', 'Weline_CacheManager::cache_service')]
class FullPageCache extends \Weline\Framework\App\Controller\BackendPageController
{
    public function __construct(
        private readonly FullPageCacheAdminService $adminService
    ) {
    }

    #[Acl('Weline_CacheManager::full_page_cache_index', '全页缓存设置', 'settings', '查看 FPC 与开发穿透')]
    public function index()
    {
        $notice = \trim((string)$this->request->getParam('notice', ''));
        $this->assign('status', $this->adminService->status());
        $this->assign('notice', $notice);

        return $this->fetch();
    }

    #[Acl('Weline_CacheManager::full_page_cache_save', '保存全页缓存设置', 'save', '保存 FPC 与开发穿透开关')]
    public function postSave()
    {
        $fpcEnabled = !$this->isFalseLike($this->request->getPost('fpc_enabled', true));
        $autoPierce = !$this->isFalseLike($this->request->getPost('auto_pierce_enabled', false));
        $result = $this->adminService->saveToggles($fpcEnabled, $autoPierce);

        if ($this->request->isAjax()) {
            return $this->fetchJson([
                'code' => (bool)($result['ok'] ?? false) ? 200 : 500,
                'msg' => (string)($result['message'] ?? ''),
                'data' => $result,
            ]);
        }

        $this->redirect($this->indexUrl([
            'notice' => (bool)($result['ok'] ?? false) ? 'saved' : 'save_failed',
        ]));

        return '';
    }

    #[Acl('Weline_CacheManager::full_page_cache_rotate', '轮换 FPC 打穿章', 'refresh', '轮换打穿章并刷新 Nginx map')]
    public function postRotatePierce()
    {
        $result = $this->adminService->rotatePierce(true);
        if ($this->request->isAjax()) {
            return $this->fetchJson([
                'code' => (bool)($result['ok'] ?? false) ? 200 : 500,
                'msg' => (string)($result['message'] ?? ''),
                'data' => $result,
            ]);
        }

        $this->redirect($this->indexUrl([
            'notice' => (bool)($result['ok'] ?? false) ? 'pierce_rotated' : 'pierce_rotate_failed',
        ]));

        return '';
    }

    #[Acl('Weline_CacheManager::full_page_cache_issue_cookie', '签发 FPC 打穿章 Cookie', 'cookie', '为本浏览器签发打穿章 Cookie')]
    public function postIssuePierceCookie()
    {
        $result = $this->adminService->issuePierceCookie((int)$this->request->getPost('ttl_sec', 28800));
        $cookie = $result['cookie'] ?? [];
        $opts = \is_array($cookie['options'] ?? null) ? $cookie['options'] : [];
        if ((bool)($result['ok'] ?? false) && isset($cookie['name'], $cookie['value'])) {
            \setcookie(
                (string)$cookie['name'],
                (string)$cookie['value'],
                [
                    'expires' => (int)($opts['expires'] ?? 0),
                    'path' => (string)($opts['path'] ?? '/'),
                    'secure' => (bool)($opts['secure'] ?? true),
                    'httponly' => (bool)($opts['httponly'] ?? true),
                    'samesite' => (string)($opts['samesite'] ?? 'Lax'),
                ]
            );
        }

        if ($this->request->isAjax()) {
            return $this->fetchJson([
                'code' => (bool)($result['ok'] ?? false) ? 200 : 500,
                'msg' => (string)($result['message'] ?? ''),
                'data' => [
                    'name' => $cookie['name'] ?? '',
                    'expires' => (int)($opts['expires'] ?? 0),
                    'status' => $result['status'] ?? [],
                ],
            ]);
        }

        $this->redirect($this->indexUrl([
            'notice' => (bool)($result['ok'] ?? false) ? 'pierce_cookie_issued' : 'pierce_cookie_failed',
        ]));

        return '';
    }

    /**
     * @param array<string, scalar> $params
     */
    private function indexUrl(array $params = []): string
    {
        return $this->request->getUrlBuilder()->getBackendUrl('admin/system/full-page-cache', $params);
    }

    private function isFalseLike(mixed $value): bool
    {
        if (\is_bool($value)) {
            return !$value;
        }
        $s = \strtolower(\trim((string)$value));

        return \in_array($s, ['0', 'false', 'off', 'no', ''], true);
    }
}

<?php

declare(strict_types=1);

namespace Weline\Backend\Controller\Backend;

use Weline\Backend\Service\NotificationService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;

#[Acl('Weline_Backend::notification', '通知中心', 'bell', '查看系统通知', 'Weline_Backend::notification_settings')]
class Notification extends BackendController
{
    private const JUST_MARKED_SESSION_KEY = 'backend_notification_just_marked';
    private const JUST_MARKED_TTL_SECONDS = 45.0;

    private NotificationService $notificationService;

    public function __construct()
    {
        $this->notificationService = ObjectManager::getInstance(NotificationService::class);
    }

    #[Acl('Weline_Backend::notification_index', '通知列表', 'check', '查看通知列表')]
    public function index(): string
    {
        $userId = (int) $this->getLoginUserId();
        $page = (int) $this->request->getGet('page', 1);
        $limit = 15;

        $keyword = $this->request->getGet('keyword', '');
        $type = $this->request->getGet('type', '');
        $read = $this->request->getGet('read', 'all');
        $filters = [
            'keyword' => $keyword,
            'type'    => $type,
            'read'    => in_array($read, ['all', 'read', 'unread'], true) ? $read : 'all',
        ];

        $result = $this->notificationService->getUserNotifications($userId, $page, $limit, false, $filters);

        $this->assign('notifications', $result['items']);
        $this->assign('pagination', [
            'page'  => $result['page'],
            'pages' => $result['pages'],
            'total' => $result['total'],
        ]);
        $this->assign('filter', $filters);
        $this->assign('type_options', \Weline\Backend\Enum\NotificationType::getTypeOptions());
        $this->assign('page_title', __('通知中心'));

        return $this->fetch();
    }

    #[Acl('Weline_Backend::notification_detail', '通知详情', 'file', '查看通知详情')]
    public function detail(): string
    {
        $userId = (int) $this->getLoginUserId();
        $notificationId = (int) $this->request->getGet('id', 0);

        if (!$notificationId) {
            return $this->redirect($this->getBackendUrl('system/backend/notification'));
        }

        $notification = $this->notificationService->getNotificationDetail($userId, $notificationId);

        if (!$notification) {
            $this->assign('error', __('通知不存在或无权查看'));
            $this->assignDetailPageTitle();
            return $this->fetch('Weline_Backend::templates/Backend/Notification/error.phtml');
        }

        // Peek first. Browser may prefetch/prerender「下一条」—those requests must NOT
        // persist read, or the real click only sees「已读」and never「未读→已读」.
        // Duplicate document requests (navigate+prefetch race) also need a short
        // session flash so the second response still renders「未读→已读」.
        $wasUnread = empty($notification['is_read']);
        $serverMarked = false;
        if ($wasUnread && $this->allowsNotificationReadMutation()) {
            $this->notificationService->markAsRead($userId, $notificationId);
            $notification['is_read'] = true;
            $notification['read_at'] = date('Y-m-d H:i:s');
            $serverMarked = true;
            $this->pushJustMarkedReadFlash($notificationId);
            $showTransition = true;
        } else {
            // Follow-up document request after a sibling/prefetch race already marked.
            // Prefer DB read_at freshness (shared across WLS workers); session flash
            // is a same-worker best-effort complement.
            $showTransition = $this->isRecentlyMarkedRead($notification)
                || $this->pullJustMarkedReadFlash($notificationId);
        }
        // Carry flags on the notification bag (layout extract is unreliable for
        // ad-hoc assign keys); template + data-* must see the same peek result.
        $notification['was_unread'] = $wasUnread || $showTransition;
        $notification['just_marked_read'] = $showTransition;
        $notification['server_marked_read'] = $serverMarked || $showTransition;

        $adjacent = $this->notificationService->getAdjacentNotifications($userId, $notificationId);
        $this->assign('notification', $notification);
        $this->assign('prev_id', $adjacent['prev_id']);
        $this->assign('prev_title', $adjacent['prev_title']);
        $this->assign('next_id', $adjacent['next_id']);
        $this->assign('next_title', $adjacent['next_title']);
        $this->assignDetailPageTitle();

        return $this->fetch();
    }

    /**
     * Persist inbox read only for real user document views—not prefetch/prerender.
     */
    private function allowsNotificationReadMutation(): bool
    {
        $purpose = strtolower(trim((string) (
            $this->request->getServer('HTTP_SEC_PURPOSE')
            ?? $this->request->getServer('HTTP_PURPOSE')
            ?? ''
        )));
        if ($purpose !== '' && (
            str_contains($purpose, 'prefetch')
            || str_contains($purpose, 'prerender')
        )) {
            return false;
        }

        $dest = strtolower(trim((string) ($this->request->getServer('HTTP_SEC_FETCH_DEST') ?? '')));
        // Prefetch commonly uses Sec-Fetch-Dest: empty.
        if ($dest === 'empty') {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $notification
     */
    private function isRecentlyMarkedRead(array $notification): bool
    {
        if (empty($notification['is_read'])) {
            return false;
        }
        $readAt = \trim((string) ($notification['read_at'] ?? ''));
        if ($readAt === '') {
            return false;
        }
        $ts = \strtotime($readAt);

        return $ts !== false && (\time() - $ts) <= (int) self::JUST_MARKED_TTL_SECONDS;
    }

    private function pushJustMarkedReadFlash(int $notificationId): void
    {
        if ($notificationId <= 0) {
            return;
        }
        try {
            $session = SessionFactory::getInstance()->createBackendSession();
            $bag = [];
            if (\method_exists($session, 'getData')) {
                $raw = $session->getData(self::JUST_MARKED_SESSION_KEY);
                $bag = \is_array($raw) ? $raw : [];
            }
            $now = \microtime(true);
            $bag[(string) $notificationId] = $now;
            foreach ($bag as $id => $ts) {
                if (!\is_numeric($ts) || ($now - (float) $ts) > self::JUST_MARKED_TTL_SECONDS) {
                    unset($bag[$id]);
                }
            }
            if (\method_exists($session, 'setData')) {
                $session->setData(self::JUST_MARKED_SESSION_KEY, $bag);
            }
        } catch (\Throwable) {
            // Session may be unavailable in CLI / warmup.
        }
    }

    private function pullJustMarkedReadFlash(int $notificationId): bool
    {
        if ($notificationId <= 0) {
            return false;
        }
        try {
            $session = SessionFactory::getInstance()->createBackendSession();
            if (!\method_exists($session, 'getData')) {
                return false;
            }
            $raw = $session->getData(self::JUST_MARKED_SESSION_KEY);
            $bag = \is_array($raw) ? $raw : [];
            $key = (string) $notificationId;
            $ts = $bag[$key] ?? null;
            $now = \microtime(true);
            $hit = \is_numeric($ts) && ($now - (float) $ts) <= self::JUST_MARKED_TTL_SECONDS;
            if (isset($bag[$key])) {
                unset($bag[$key]);
                if (\method_exists($session, 'setData')) {
                    $session->setData(self::JUST_MARKED_SESSION_KEY, $bag);
                }
            }

            return $hit;
        } catch (\Throwable) {
            return false;
        }
    }

    private function assignDetailPageTitle(): void
    {
        $title = __('通知详情');
        $this->assign('title', $title);
        $this->assign('page_title', $title);
    }

    /**
     * 标记单条通知为已读（JSON API，供头部/下拉调用）
     * POST system/backend/notification/markRead
     */
    public function markRead(): void
    {
        $userId = (int) $this->getLoginUserId();
        $notificationId = (int) $this->request->getBodyParam('notification_id', 0);

        if (!$notificationId || !$userId) {
            $this->jsonResponse(400, false, __('参数错误'));
        }

        $success = $this->notificationService->markAsRead($userId, $notificationId);
        $this->jsonResponse(200, $success, $success ? __('已标记为已读') : __('标记失败'));
    }

    /**
     * 标记全部通知为已读（JSON API，供头部「全部已读」调用）
     * POST system/backend/notification/markAllRead
     */
    public function markAllRead(): void
    {
        $userId = (int) $this->getLoginUserId();

        if (!$userId) {
            $this->jsonResponse(401, false, __('未登录'));
        }

        $this->notificationService->markAllAsRead($userId);
        $this->jsonResponse(200, true, __('已标记全部已读'));
    }

    /**
     * 按主题（topic_code）将该类通知全部标记为已读
     * POST system/backend/notification/markTopicRead
     */
    public function markTopicRead(): void
    {
        $userId = (int) $this->getLoginUserId();
        $topicCode = trim((string) $this->request->getBodyParam('topic_code', ''));

        if (!$userId) {
            $this->jsonResponse(401, false, __('未登录'));
        }

        if ($topicCode === '') {
            $this->jsonResponse(400, false, __('请指定主题类型'));
        }

        $count = $this->notificationService->markByTopicAsRead($userId, $topicCode);
        $this->jsonResponse(200, true, __('已将该类 %{count} 条通知标记为已读', ['count' => (string) $count]));
    }

    /**
     * 输出 JSON 并终止请求（与 Api/Notification 返回格式一致）
     */
    private function jsonResponse(int $code, bool $success, string $message): void
    {
        $body = json_encode([
            'code'    => $code,
            'success' => $success,
            'message' => $message,
            'data'    => [],
        ], JSON_UNESCAPED_UNICODE);

        throw new ResponseTerminateException(
            $code,
            $body,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }
}

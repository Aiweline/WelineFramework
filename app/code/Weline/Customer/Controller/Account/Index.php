<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use Weline\Framework\View\Template;
use Weline\Customer\Model\Customer;
use Weline\Framework\Runtime\RequestLifecycleTrace;

/**
 * 个人中心控制器
 */
class Index extends \Weline\Framework\App\Controller\FrontendController
{
    private Template $template;
    protected ?string $layoutType = 'account.dashboard';

    public function __construct(
        Template $template
    ) {
        $this->template = $template;
    }

    /**
     * 个人中心首页
     */
    public function getIndex()
    {
        $requestStartedAt = microtime(true);
        $loginStartedAt = $requestStartedAt;
        // 检查是否登录
        if (!$this->isLoggedIn()) {
            // 保存当前URL作为来源
            $this->setAccountTimingHeaders([
                'total' => $this->elapsedMs($requestStartedAt),
                'login' => $this->elapsedMs($loginStartedAt),
                'redirect' => 1,
            ]);
            $currentUrl = $this->request->getUrlBuilder()->getCurrentUrl();
            $this->redirect('/customer/account/login?referer=' . urlencode($currentUrl));
            return;
        }
        $loginMs = $this->elapsedMs($loginStartedAt);

        // 获取登录用户
        $userStartedAt = microtime(true);
        /** @var Customer $user */
        $user = $this->getLoginUser();
        $userMs = $this->elapsedMs($userStartedAt);
        // 设置用户数据
        $assignStartedAt = microtime(true);
        $this->assign('user', $user);
        $assignMs = $this->elapsedMs($assignStartedAt);

        $sidebarStartedAt = microtime(true);
        $sidebar = $this->template('Weline_Customer::templates/frontend/account/sidebar/side.phtml');
        $sidebarMs = $this->elapsedMs($sidebarStartedAt);
        $this->assign('sidebar', $sidebar);
        $sidebarLength = strlen(trim((string)$sidebar));
        try {
            $this->request->getResponse()->setHeader('X-Weline-Account-Sidebar-Len', (string)$sidebarLength);
            $this->request->getResponse()->setHeader('X-Weline-Account-Sidebar-Ms', $this->formatMs($sidebarMs));
        } catch (\Throwable) {
        }
        if ((string)$this->request->getGet('debug_sidebar', '') === '1') {
            \error_log('[AccountSidebarTrace] controller_sidebar_prepared ' . \json_encode([
                'request_id' => \Weline\Framework\Runtime\RequestContext::getId(),
                'uri' => (string)($this->request->getServer('REQUEST_URI') ?? $this->request->getUri() ?? ''),
                'lang' => \Weline\Framework\App\State::getLang(),
                'lang_local' => \Weline\Framework\App\State::getLangLocal(),
                'sidebar_len' => $sidebarLength,
                'sidebar_ms' => round($sidebarMs, 3),
                'template_id' => \spl_object_id(Template::getInstance()),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        if ($sidebarLength === 0 && function_exists('w_log_warning')) {
            w_log_warning('[AccountSidebar] controller generated empty sidebar', [
                'uri' => (string)($this->request->getServer('REQUEST_URI') ?? $this->request->getUri() ?? ''),
                'lang' => (string)($this->request->getServer('WELINE_USER_LANG') ?? ''),
            ], 'account_sidebar');
        }
        RequestLifecycleTrace::recordSpan('customer::account::sidebarPrepared', $sidebarMs, 'controller', null, [
            'sidebar_len' => $sidebarLength,
            'template_id' => \spl_object_id(Template::getInstance()),
        ]);

        $metaStartedAt = microtime(true);
        $existingMeta = $this->getData('meta');
        if (!is_array($existingMeta)) {
            $existingMeta = [];
        }
        $this->assign('meta', array_merge($existingMeta, [
            'user' => $user,
            'sidebar' => $sidebar,
            'showHeader' => true,
            'showFooter' => true,
        ]));
        $metaMs = $this->elapsedMs($metaStartedAt);

        $fetchStartedAt = microtime(true);
        $html = $this->fetch('Weline_Customer::templates/frontend/account/index.phtml');
        $fetchMs = $this->elapsedMs($fetchStartedAt);
        $totalMs = $this->elapsedMs($requestStartedAt);
        $htmlBytes = \strlen((string)$html);

        $this->setAccountTimingHeaders([
            'total' => $totalMs,
            'login' => $loginMs,
            'user' => $userMs,
            'assign' => $assignMs,
            'sidebar' => $sidebarMs,
            'meta' => $metaMs,
            'fetch' => $fetchMs,
            'bytes' => $htmlBytes,
        ]);

        RequestLifecycleTrace::recordSpan('customer::account::controllerTotal', $totalMs, 'controller', null, [
            'login_ms' => round($loginMs, 3),
            'user_ms' => round($userMs, 3),
            'assign_ms' => round($assignMs, 3),
            'sidebar_ms' => round($sidebarMs, 3),
            'meta_ms' => round($metaMs, 3),
            'fetch_ms' => round($fetchMs, 3),
            'html_bytes' => $htmlBytes,
        ]);
        \Weline\Framework\Runtime\RequestContext::set('account.index.timing', [
            'login_ms' => round($loginMs, 3),
            'user_ms' => round($userMs, 3),
            'assign_ms' => round($assignMs, 3),
            'sidebar_ms' => round($sidebarMs, 3),
            'meta_ms' => round($metaMs, 3),
            'fetch_ms' => round($fetchMs, 3),
            'total_ms' => round($totalMs, 3),
            'html_bytes' => $htmlBytes,
            'sidebar_bytes' => $sidebarLength,
        ]);
        \error_log('[AccountPerf] index ' . \json_encode(\Weline\Framework\Runtime\RequestContext::get('account.index.timing'), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

        if ($totalMs > 150.0 && function_exists('w_log_warning')) {
            w_log_warning('[AccountPerf] slow account index render', [
                'request_id' => \Weline\Framework\Runtime\RequestContext::getId(),
                'uri' => (string)($this->request->getServer('REQUEST_URI') ?? $this->request->getUri() ?? ''),
                'lang' => \Weline\Framework\App\State::getLang(),
                'login_ms' => round($loginMs, 3),
                'user_ms' => round($userMs, 3),
                'assign_ms' => round($assignMs, 3),
                'sidebar_ms' => round($sidebarMs, 3),
                'meta_ms' => round($metaMs, 3),
                'fetch_ms' => round($fetchMs, 3),
                'total_ms' => round($totalMs, 3),
                'html_bytes' => $htmlBytes,
            ], 'account_perf');
        }

        return $html;
    }

    public function getSidebarContent(): string
    {
        $requestStartedAt = microtime(true);
        $loginStartedAt = $requestStartedAt;
        if (!$this->isLoggedIn()) {
            $this->setAccountTimingHeaders([
                'sections_total' => $this->elapsedMs($requestStartedAt),
                'sections_login' => $this->elapsedMs($loginStartedAt),
                'sections_redirect' => 1,
            ]);
            return $this->fetchJson([
                'success' => false,
                'message' => __('请先登录'),
                'redirect' => $this->getUrl('customer/account/login'),
            ]);
        }
        $loginMs = $this->elapsedMs($loginStartedAt);

        $userStartedAt = microtime(true);
        $user = $this->getLoginUser();
        $userMs = $this->elapsedMs($userStartedAt);
        if ($user !== null) {
            $this->assign('user', $user);
        }

        $startedAt = microtime(true);
        $html = (string)$this->getTemplate()->getHook('account.sidebar.content');
        $durationMs = (microtime(true) - $startedAt) * 1000;
        $htmlBytes = strlen($html);

        try {
            $this->request->getResponse()->setHeader('X-Weline-Account-Sections-Len', (string)$htmlBytes);
            $this->request->getResponse()->setHeader('X-Weline-Account-Sections-Hook-Ms', $this->formatMs($durationMs));
        } catch (\Throwable) {
        }

        if ($htmlBytes === 0 && function_exists('w_log_warning')) {
            w_log_warning('[AccountSidebarContent] empty lazy sidebar content', [
                'uri' => (string)($this->request->getServer('REQUEST_URI') ?? $this->request->getUri() ?? ''),
                'lang' => \Weline\Framework\App\State::getLang(),
                'duration_ms' => round($durationMs, 3),
            ], 'account_sidebar');
        }

        RequestLifecycleTrace::recordSpan('customer::account::sidebarContentLazy', $durationMs, 'controller', null, [
            'html_bytes' => $htmlBytes,
        ]);

        $jsonStartedAt = microtime(true);
        $json = $this->fetchJson([
            'success' => true,
            'html' => $html,
            'length' => $htmlBytes,
        ]);
        $jsonMs = $this->elapsedMs($jsonStartedAt);
        $totalMs = $this->elapsedMs($requestStartedAt);
        $this->setAccountTimingHeaders([
            'sections_total' => $totalMs,
            'sections_login' => $loginMs,
            'sections_user' => $userMs,
            'sections_hook' => $durationMs,
            'sections_json' => $jsonMs,
            'sections_bytes' => \strlen((string)$json),
        ]);
        \Weline\Framework\Runtime\RequestContext::set('account.sidebar_content.timing', [
            'login_ms' => round($loginMs, 3),
            'user_ms' => round($userMs, 3),
            'hook_ms' => round($durationMs, 3),
            'json_ms' => round($jsonMs, 3),
            'total_ms' => round($totalMs, 3),
            'html_bytes' => $htmlBytes,
            'json_bytes' => \strlen((string)$json),
        ]);
        \error_log('[AccountPerf] sidebar_content ' . \json_encode(\Weline\Framework\Runtime\RequestContext::get('account.sidebar_content.timing'), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

        if ($totalMs > 150.0 && function_exists('w_log_warning')) {
            w_log_warning('[AccountPerf] slow account sidebar content render', [
                'request_id' => \Weline\Framework\Runtime\RequestContext::getId(),
                'uri' => (string)($this->request->getServer('REQUEST_URI') ?? $this->request->getUri() ?? ''),
                'lang' => \Weline\Framework\App\State::getLang(),
                'login_ms' => round($loginMs, 3),
                'user_ms' => round($userMs, 3),
                'hook_ms' => round($durationMs, 3),
                'json_ms' => round($jsonMs, 3),
                'total_ms' => round($totalMs, 3),
                'html_bytes' => $htmlBytes,
            ], 'account_perf');
        }

        return $json;
    }

    /**
     * 更新个人信息
     */
    // Account render timing helpers.
    private function elapsedMs(float $startedAt): float
    {
        return (microtime(true) - $startedAt) * 1000;
    }

    private function formatMs(float $milliseconds): string
    {
        return (string)round($milliseconds, 3);
    }

    private function setAccountTimingHeaders(array $timings): void
    {
        try {
            $response = $this->request->getResponse();
            foreach ($timings as $name => $value) {
                $header = 'X-Weline-Account-' . str_replace('_', '-', ucwords((string)$name, '_'));
                if (is_float($value)) {
                    $value = $this->formatMs($value);
                }
                $response->setHeader($header, (string)$value);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Update customer profile.
     */
    public function postUpdate()
    {
        if (!$this->isLoggedIn()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请先登录')
            ]);
        }

        /** @var Customer $user */
        $user = $this->getLoginUser();

        $avatar = $this->request->getPost('avatar');
        if ($avatar) {
            $user->setAvatar($avatar);
        }

        // 处理密码修改
        $oldPassword = $this->request->getPost('old_password');
        $newPassword = $this->request->getPost('new_password');
        $confirmPassword = $this->request->getPost('confirm_password');

        if ($newPassword) {
            if (empty($oldPassword)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('请输入原密码')
                ]);
            }

            if (!password_verify($oldPassword, $user->getPassword())) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('原密码错误')
                ]);
            }

            if (strlen($newPassword) < 6) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('新密码长度不能少于6位')
                ]);
            }

            if ($newPassword !== $confirmPassword) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('两次输入的新密码不一致')
                ]);
            }

            $user->setPassword($newPassword);
        }

        try {
            $user->save();
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $this->buildUpdateFailureMessage($throwable)
            ]);
        }

        return $this->fetchJson([
            'success' => true,
            'message' => __('更新成功')
        ]);
    }

    private function buildUpdateFailureMessage(\Throwable $throwable): string
    {
        $message = html_entity_decode((string) $throwable->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $message = trim((string) preg_replace('/^[：:,，\s]+/u', '', $message));

        if ($message === '' || $message === (string) __('请稍后重试')) {
            return (string) __('更新失败，请稍后重试');
        }

        return (string) __('更新失败：%{1}', [$message]);
    }
}

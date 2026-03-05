<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Controller;

use Weline\Admin\Helper\Data;
use Weline\Admin\Helper\MenuUrlValidator;
use Weline\Backend\Model\BackendUserToken;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Text;

class Login extends \Weline\Framework\App\Controller\BackendController
{
    /**
     * 登录页不使用布局系统，使用独立完整的模板
     */
    protected ?string $layoutType = null;
    
    protected BackendUser $adminUser;
    private Data $helper;
    private MessageManager $messageManager;

    public function __construct(
        BackendUser    $adminUser,
        MessageManager $messageManager,
        Data           $helper
    )
    {
        $this->adminUser = $adminUser;
        $this->helper = $helper;
        $this->messageManager = $messageManager;
    }

    public function index()
    {
        if ($this->session->isLoggedIn()) {
            // 有来源网址就跳回来源网址
            $this->redirectReferer();
            $this->redirect($this->_url->getBackendUrl('admin'));
        }
        //        $this->session->delete('backend_disable_login');
        $this->assign('post_url', $this->_url->getBackendUrl('admin/login/post'));
        # 检测验证码
        if ($this->session->get('need_backend_verification_code')) {
            $this->assign('need_backend_verification_code', true);
            // 使用连字符小写路径以与白名单精确匹配，并兼容路由规则
            $this->assign('backend_verification_code_url', $this->_url->getBackendUrl('admin/login/verification-code'));
        }
        // 锁定提示以 Session 为准，但若数据库中该用户 attempt_times 已恢复（管理员改过），则清除 Session 标志避免一直提示
        $s = $this->session;
        $backendDisable = $s->get('backend_disable_login');
        if ($backendDisable) {
            $lockedUsername = $s->get('backend_disable_login_username') ?? $this->request->getParam('username') ?? '';
            $cleared = false;
            if ($lockedUsername !== '') {
                $user = clone $this->adminUser;
                $user->reset()->where('username', $lockedUsername)->find()->fetch();
                $uid = $user->getId();
                $attemptTimes = $user->getAttemptTimes();
                if ($uid && $attemptTimes <= 6) {
                    $s->delete('backend_disable_login');
                    $s->delete('backend_disable_login_username');
                    foreach (MessageManager::keys as $key) {
                        $s->delete($key);
                    }
                    $cleared = true;
                }
            } else {
                // 无用户名时视为老旧 session，清除锁定显示，让用户重试（POST 会重新校验）
                $s->delete('backend_disable_login');
                $s->delete('backend_disable_login_username');
                foreach (MessageManager::keys as $key) {
                    $s->delete($key);
                }
                $cleared = true;
            }
            $afterClear = $s->get('backend_disable_login');
            if (!$cleared && $afterClear) {
                // 用 set 覆盖，避免 append 累积（若 session 持久化/清空异常，刷新会重复 append 导致多条相同提示）
                $html = MessageManager::process_message(__('你的账户因尝试多次登录，已被锁定！请联系其他管理员开通。'), __('错误！'), 'danger');
                $s->set('system-message', $html);
                $s->set('has-error', '1');
            }
        }
        return $this->fetch();
    }

    public function postPost(): void
    {
        # 已经登录直接进入后台
        if ($this->session->isLoggedIn()) {
            // 有来源网址就跳回来源网址
            $this->redirectReferer();
            $this->redirect($this->_url->getBackendUrl('admin'));
        }
        # 验证 form 表单
        // if (empty($this->request->getParam('form_key')) || ($this->session->get('form_key') !== $this->request->getParam('form_key'))) {
        //     MessageManager::error(__('异常的登录操作！'));
        //     $this->redirect($this->_url->getBackendUrl('/admin/login'));
        //     return;
        // }

        $adminUsernameUser = $this->helper->getRequestBackendUser();
        if (!$adminUsernameUser->getId() or $adminUsernameUser->getIsDeleted()) {
            MessageManager::error(__('账户不存在！'));
            $this->redirect($this->_url->getBackendUrl('/admin/login'));
            return;
        }
        if (!$adminUsernameUser->getIsEnabled()) {
            MessageManager::error(__('账户被禁用！'));
            $this->redirect($this->_url->getBackendUrl('/admin/login'));
            return;
        }
        if ($adminUsernameUser->getAttemptTimes() > 6) {
            $adminUsernameUser->setSessionId($this->session->getId())->setAttemptIp($this->request->clientIP())->save();
            $s = $this->session;
            $s->set('backend_disable_login', true);
            $s->set('backend_disable_login_username', $adminUsernameUser->getUsername());
            if ($adminUsernameUser->getAttemptTimes() > 60) {
                # FIXME 将IP封死，为了不占用服务器资源，将封锁过程提前到框架入口处，此处只作为拉入黑名单处理【设置为Security框架函数处理】
                $this->noRouter();
            }
            $this->redirect($this->_url->getBackendUrl('/admin/login'));
            return;
        } else {
            $this->session->set('backend_disable_login', false);
        }
        # 自增尝试登录次数
        try {
            $adminUsernameUser->addAttemptTimes()->save();
        } catch (\Exception $exception) {
            $adminUsernameUser->setSessionId($this->session->getId())
                ->setAttemptIp($this->request->clientIP())
                ->save();
            MessageManager::error(__('登录异常！'));
            $this->redirect($this->_url->getBackendUrl('/admin/login'));
            return;
        }
        # 如果大于2次的尝试登录 验证客户提供的验证码
        if ($adminUsernameUser->getAttemptTimes() > 2) {
            $this->session->set('need_backend_verification_code', 1);
        }
        # 验证验证码
        if ($adminUsernameUser->getAttemptTimes() > 3 && ($this->session->get('backend_verification_code') !== $this->request->getParam('code'))) {
            MessageManager::error(__('验证码错误！'));
            $adminUsernameUser->setSessionId($this->session->getId())
                ->setAttemptIp($this->request->clientIP())
                ->save();
            $this->redirect($this->_url->getBackendUrl('/admin/login'));
            return;
        }
        # 尝试登录
        $password = $this->request->getParam('password');
        $storedPassword = $adminUsernameUser->getPassword();
        $passwordVerifyResult = $storedPassword && password_verify($password, $storedPassword);
        if ($passwordVerifyResult) {
            # SESSION登录用户
            try {
                // 确保session已启动
                $currentSessionId = $this->session->getId();
                if (empty($currentSessionId)) {
                    $this->session->start('');
                }
                // 调用login方法（只传入一个参数）
                $this->session->login($adminUsernameUser);
                // 检查用户是否有角色，如果没有角色，显示友好提示并退出登录（user_id=1 视为超管，无角色记录也允许登录）
                $userRole = $adminUsernameUser->getRole();
                $hasRole = (bool)($userRole && $userRole->getRoleId());
                $isSuperAdminById = (int) $adminUsernameUser->getId() === 1;
                if (!$hasRole && !$isSuperAdminById) {
                    $this->session->logout();
                    MessageManager::error(__('您的账户尚未分配角色，无法登录后台。请联系系统管理员为您分配角色。'));
                    $this->redirect($this->_url->getBackendUrl('/admin/login'));
                    return;
                }
            } catch (\Exception $e) {
                throw $e;
            }
            $adminUsernameUser->setSessionId($this->session->getId())
                ->setLoginIp($this->request->clientIP());
            # 重置 尝试登录次数
            $adminUsernameUser->resetAttemptTimes()->save();
            # 登录成功后清理验证码相关的session数据
            $this->session->delete('need_backend_verification_code');
            $this->session->delete('backend_verification_code');
            $this->syncSandboxCookie($adminUsernameUser->isSandboxAccount());
            # 检测是否记住我
            if ($this->request->getParam('remember')) {
                /**@var BackendUserToken $backendUserToken */
                $backendUserToken = ObjectManager::getInstance(BackendUserToken::class);
                $backendUserToken->load($adminUsernameUser->getId());
                $token = Text::random_string(32);
                $token_expire_time = strtotime('+1 week');
                $backendUserToken
                    ->setData($backendUserToken::schema_fields_ID, $adminUsernameUser->getId())
                    ->setData($backendUserToken::schema_fields_type, 'admin_login_remember_me')
                    ->setData($backendUserToken::schema_fields_token, $token)
                    ->setData($backendUserToken::schema_fields_token_expire_time, $token_expire_time)
                    ->save();
                Cookie::set('w_ut', $token, $token_expire_time, ['path' => '/' . $this->request->getAreaRouter()]);
            }
        } else {
            $adminUsernameUser->setSessionId($this->session->getId())
                ->setAttemptIp($this->request->clientIP())
                ->save();
            MessageManager::error(__('登录凭据错误！'));
            $this->logout();
            $this->redirect($this->_url->getBackendUrl('/admin/login'));
            return;
        }
        // 有来源网址就跳回来源网址
        $this->redirectReferer();
        # 跳转首页
        $this->redirect($this->_url->getBackendUrl('admin'));
    }

    private function redirectReferer(): void
    {
        $backend_login_referer = Url::removeExtraDoubleSlashes($this->session->get('backend_login_referer'));
        if ($backend_login_referer) {
            if ($this->request->getUrlPath($backend_login_referer) !== $this->request->getUrlPath()) {
                // 验证是否可以作为登录后跳转的有效目标
                $parsed = \Weline\Framework\Http\Url::parser($backend_login_referer);
                $refererRoutePath = trim($parsed['uri'] ?? '', '/');
                if ($refererRoutePath && MenuUrlValidator::isValidLoginRedirectTarget($refererRoutePath)) {
                    $this->session->delete('backend_login_referer');
                    $this->redirect($backend_login_referer);
                    return;
                } else {
                    // 不是有效跳转目标，清除
                    $this->session->delete('backend_login_referer');
                }
            }
        }
        $referer = Url::removeExtraDoubleSlashes($this->session->get('referer'));

        if ($referer) {
            if (Url::is_same_site($referer) && $referer !== $this->request->getUrlPath()) {
                // 验证是否可以作为登录后跳转的有效目标
                $parsed = \Weline\Framework\Http\Url::parser($referer);
                $refererRoutePath = trim($parsed['uri'] ?? '', '/');
                if ($refererRoutePath && MenuUrlValidator::isValidLoginRedirectTarget($refererRoutePath)) {
                    $this->redirect($referer);
                } else {
                    // 不是有效跳转目标，清除
                    $this->session->delete('referer');
                }
            }
        }
    }

    public function logout(): void
    {
        // 在退出登录前，保存当前页面URL（如果是菜单链接）
        // 优先从 session 的 referer 获取，如果没有则从 HTTP_REFERER 头获取
        $currentUrl = '';
        
        // 1. 优先从 session 的 referer 获取
        $referer = $this->session->get('referer');
        if ($referer) {
            $referer = Url::removeExtraDoubleSlashes($referer);
            if ($referer && Url::is_same_site($referer)) {
                $currentUrl = $referer;
            }
        }
        
        // 2. 如果 session 中没有，尝试从 HTTP_REFERER 头获取
        if (!$currentUrl) {
            $httpReferer = $this->request->getReferer();
            if ($httpReferer && Url::is_same_site($httpReferer)) {
                $currentUrl = Url::removeExtraDoubleSlashes($httpReferer);
            }
        }
        
        // 验证URL是否可以作为登录后跳转的有效目标，若是则写入 session（logout 只清除认证相关 key，此项会保留）
        if ($currentUrl) {
            $parsed = \Weline\Framework\Http\Url::parser($currentUrl);
            $routePath = trim($parsed['uri'] ?? '', '/');
            if ($routePath && MenuUrlValidator::isValidLoginRedirectTarget($routePath)) {
                $this->session->set('backend_login_referer', $currentUrl);
            }
        }
        
        Cookie::set('w_ut', '', -1, ['path' => '/' . $this->request->getAreaRouter()]);
        Cookie::set('w_sandbox', '', -1, ['path' => '/']);
        Cookie::set('w_sandbox', '', -1, ['path' => '/' . $this->request->getAreaRouter()]);
        $this->session->logout();
        $this->redirect($this->_url->getBackendUrl('admin/login'));
    }

    private function syncSandboxCookie(bool $enabled): void
    {
        $lifetime = $enabled ? 0 : -1;
        Cookie::set('w_sandbox', $enabled ? '1' : '', $lifetime, ['path' => '/']);
        Cookie::set('w_sandbox', $enabled ? '1' : '', $lifetime, ['path' => '/' . $this->request->getAreaRouter()]);
    }

    /**
     * @DESC          # 获取验证码
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/9 23:54
     * 参数区：
     * @return never 通过 send() 抛出 ResponseTerminateException，由 Runtime 发送
     */
    public function verificationCode()
    {
        # --1 设置验证码图片的大小
        $image = imagecreatetruecolor(100, 30);
        # --2 设置验证码颜色 imagecolorallocate(int im, int red, int green, int blue);
        $bgcolor = imagecolorallocate($image, 255, 255, 255); //#ffffff
        # --3 区域填充 int imagefill(int im, int x, int y, int col) (x,y) 所在的区域着色,col 表示欲涂上的颜色
        imagefill($image, 0, 0, $bgcolor);
        # --4 设置变量
        $captcha_code = '';
        # --5 生成随机数字
        for ($i = 0; $i < 6; $i++) {
            # --5-1 设置字体大小
            $fontsize = 6;
            # --5-2 设置字体颜色，随机颜色
            $fontcolor = imagecolorallocate($image, rand(0, 120), rand(0, 120), rand(0, 120));      //0-120深颜色
            # --5-3 设置数字
            $fontcontent = rand(0, 9);
            # --5-4 .=连续定义变量
            $captcha_code .= $fontcontent;
            # --5-5 设置坐标
            $x = intval(($i * 100 / 6) + rand(5, 10));
            $y = rand(5, 10);
            imagestring($image, $fontsize, $x, $y, (string)$fontcontent, $fontcolor);
        }
        $this->session->set('backend_verification_code', $captcha_code);

        # --6 增加干扰元素，设置雪花点
        for ($i = 0; $i < 200; $i++) {
            # --6-1 设置点的颜色，50-200颜色比数字浅，不干扰阅读
            $pointcolor = imagecolorallocate($image, rand(50, 200), rand(50, 200), rand(50, 200));
            # --6-2 imagesetpixel — 画一个单一像素
            imagesetpixel($image, rand(1, 99), rand(1, 29), $pointcolor);
        }
        # --7 增加干扰元素，设置横线
        for ($i = 0; $i < 4; $i++) {
            # --7-1 设置线的颜色
            $linecolor = imagecolorallocate($image, rand(80, 220), rand(80, 220), rand(80, 220));
            # --7-2 设置线，两点一线
            imageline($image, rand(1, 99), rand(1, 29), rand(1, 99), rand(1, 29), $linecolor);
        }

        # --8 通过 Response 输出并发送，兼容 FPM/WLS，由 Runtime 统一处理
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        $response = $this->request->getResponse();
        $response->setHeader('Content-Type', 'image/png');
        $response->setBody($png);
        $response->send();
    }
}

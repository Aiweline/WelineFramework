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
use Weline\Admin\Model\BackendUserToken;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Text;

class Login extends \Weline\Framework\App\Controller\BackendController
{
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
        if ($this->session->isLogin()) {
            // 有来源网址就跳回来源网址
            $this->redirectReferer();
            $this->redirect($this->_url->getBackendUrl('admin'));
        }
        //        $this->session->delete('backend_disable_login');
        $this->assign('post_url', $this->_url->getBackendUrl('admin/login/post'));
        # 检测验证码
        if ($this->session->getData('need_backend_verification_code')) {
            $this->assign('need_backend_verification_code', true);
            $this->assign('backend_verification_code_url', $this->_url->getBackendUrl('admin/login/verificationCode'));
        }
        if ($this->session->getData('backend_disable_login')) {
            $this->messageManager->addError(__('你的账户因尝试多次登录，已被锁定！请联系其他管理员开通。'));
        }
        return $this->fetch();
    }

    public function postPost(): void
    {
        # 已经登录直接进入后台
        if ($this->session->isLogin()) {
            // 有来源网址就跳回来源网址
            $this->redirectReferer();
            $this->redirect($this->_url->getBackendUrl('admin'));
        }
        # 验证 form 表单
        if (empty($this->request->getParam('form_key') || ($this->session->getData('form_key') !== $this->request->getParam('form_key')))) {
            $this->messageManager->addError(__('异常的登录操作！'));
            $this->redirect($this->_url->getBackendUrl('/admin/login'));
        }

        $adminUsernameUser = $this->helper->getRequestBackendUser();
        if (!$adminUsernameUser->getId() or $adminUsernameUser->getIsDeleted()) {
            $this->messageManager->addError(__('账户不存在！'));
            $this->redirect($this->_url->getBackendUrl('/admin/login'));
        }
        if (!$adminUsernameUser->getIsEnabled()) {
            $this->messageManager->addError(__('账户被禁用！'));
            $this->redirect($this->_url->getBackendUrl('/admin/login'));
        }
        if ($adminUsernameUser->getAttemptTimes() > 6) {
            $adminUsernameUser->setSessionId($this->session->getSessionId())->setAttemptIp($this->request->clientIP())->save();
            $this->session->setData('backend_disable_login', true);
            if ($adminUsernameUser->getAttemptTimes() > 60) {
                # FIXME 将IP封死，为了不占用服务器资源，将封锁过程提前到框架入口处，此处只作为拉入黑名单处理【设置为Security框架函数处理】
                $this->noRouter();
            }
            $this->redirect($this->_url->getBackendUrl('/admin/login'));
        } else {
            $this->session->setData('backend_disable_login', false);
        }
        # 自增尝试登录次数
        try {
            $adminUsernameUser->addAttemptTimes()->save();
        } catch (\Exception $exception) {
            $adminUsernameUser->setSessionId($this->session->getSessionId())
                ->setAttemptIp($this->request->clientIP())
                ->save();
            $this->messageManager->addError(__('登录异常！'));
            $this->redirect($this->_url->getBackendUrl('/admin/login'));
        }
        # 如果大于2次的尝试登录 验证客户提供的验证码
        if ($adminUsernameUser->getAttemptTimes() > 2) {
            $this->session->setData('need_backend_verification_code', 1);
        }
        # 验证验证码
        if ($adminUsernameUser->getAttemptTimes() > 3 && ($this->session->getData('backend_verification_code') !== $this->request->getParam('code'))) {
            $this->messageManager->addError(__('验证码错误！'));
            $adminUsernameUser->setSessionId($this->session->getSessionId())
                ->setAttemptIp($this->request->clientIP())
                ->save();
            $this->redirect($this->_url->getBackendUrl('/admin/login'));
        }
        # 尝试登录
        $password = $this->request->getParam('password');
        if ($adminUsernameUser->getPassword() && password_verify($password, $adminUsernameUser->getPassword())) {
            # SESSION登录用户
            $this->session->login($adminUsernameUser, (int)$adminUsernameUser->getId());
            $adminUsernameUser->setSessionId($this->session->getSessionId())
                ->setLoginIp($this->request->clientIP());
            # 重置 尝试登录次数
            $adminUsernameUser->resetAttemptTimes()->save();
            # 检测是否记住我
            if ($this->request->getParam('remember')) {
                /**@var BackendUserToken $backendUserToken */
                $backendUserToken = ObjectManager::getInstance(BackendUserToken::class);
                $backendUserToken->load($adminUsernameUser->getId());
                $token = Text::random_string(32);
                $token_expire_time = strtotime('+1 week');
                $backendUserToken
                    ->setData($backendUserToken::fields_ID, $adminUsernameUser->getId())
                    ->setData($backendUserToken::fields_type, 'admin_login_remember_me')
                    ->setData($backendUserToken::fields_token, $token)
                    ->setData($backendUserToken::fields_token_expire_time, $token_expire_time)
                    ->save();
                Cookie::set('w_urt', $token, $token_expire_time, ['path' => '/' . $this->request->getAreaRouter()]);
            }
        } else {
            $adminUsernameUser->setSessionId($this->session->getSessionId())
                ->setAttemptIp($this->request->clientIP())
                ->save();
            $this->messageManager->addError(__('登录凭据错误！'));
            $this->logout();
        }
        // 有来源网址就跳回来源网址
        $this->redirectReferer();
        # 跳转首页
        $this->redirect($this->_url->getBackendUrl('admin'));
    }

    private function redirectReferer()
    {
        $backend_login_referer = Url::removeExtraDoubleSlashes($this->session->getData('backend_login_referer'));
        if ($backend_login_referer) {
            if ($this->request->getUrlPath($backend_login_referer) !== $this->request->getUrlPath()) {
                $this->session->delete('backend_login_referer');
                $this->redirect($backend_login_referer);
            }
        }
        $referer = Url::removeExtraDoubleSlashes($this->session->getData('referer'));
        if ($referer) {
            if ($referer !== $this->request->getUrlPath()) {
                $this->redirect($referer);
            }
        }
    }

    public function logout(): void
    {
        Cookie::set('w_urt', '', -1, ['path' => '/' . $this->request->getAreaRouter()]);
        $this->session->logout();
        $this->redirect($this->_url->getBackendUrl('admin/login'));
    }

    /**
     * @DESC          # 获取验证码
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/9 23:54
     * 参数区：
     * @return bool
     */
    public function verificationCode(): bool
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
        $this->session->setData('backend_verification_code', $captcha_code);

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

        # --8 >设置头部，image/png
        header('Content-Type: image/png');
        # --9 >imagepng() 建立png图形函数
        imagepng($image);
        # --10 >imagedestroy() 结束图形函数 销毁$image
        imagedestroy($image);
        return $image;
    }
}

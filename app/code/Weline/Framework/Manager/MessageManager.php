<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Manager;

use Weline\Framework\Session\Session;
use Weline\Framework\Session\SessionFactory;

class MessageManager
{
    private Session $session;

    public const keys = [
        'has-error',
        'has-exception',
        'has-success',
        'has-warning',
        'has-notes',
        'system-message',
    ];

    public function __construct(
        Session $session
    )
    {
        $this->session = $session;
    }

    /**
     * 获取当前请求用于存消息的 Session。
     * 后台/rest_backend 请求使用 SessionFactory::createSession()，与后端控制器同源，避免 WLS 下消息丢失；
     * 其他场景使用 ObjectManager 的 Session 实例。
     */
    public static function session(): Session
    {
        $area = $_SERVER['WELINE_AREA'] ?? '';
        if ($area === 'backend' || $area === 'rest_backend') {
            $s = SessionFactory::getInstance()->createSession();
            assert($s instanceof Session);
            return $s;
        }
        return ObjectManager::getInstance(Session::class);
    }

    /**
     * @param string $msg
     * @param string $title
     * @param string $class
     * @return $this
     * @deprecated 弃用函数 使用静态函数 add_error() 代替
     */
    public function addError(string $msg = '', string $title = '', string $class = 'danger'): static
    {
        $title = $title ?: __('错误！');
        self::session()->append('system-message', self::process_message($msg, $title, $class));
        self::session()->set('has-error', '1');
        return $this;
    }

    public static function add_error(string $msg = '', string $title = '', string $class = 'danger'): self
    {
        $title = $title ?: __('错误！');
        self::session()->append('system-message', self::process_message($msg, $title, $class));
        self::session()->set('has-error', '1');
        return new self(self::session());
    }

    public static function error(string $msg = '', string $title = '', string $class = 'danger'): void
    {
        $session = self::session();
        $session->append('system-message', self::process_message($msg, $title, $class));
        $session->set('has-error', '1');
    }

    /**
     * 设置单条错误消息（覆盖旧消息，不追加）。用于登录等场景，避免 WLS 下多次失败时消息累计。
     * 先清空再 set，读取后由 render() 删除 session。
     */
    public static function setSingleError(string $msg = '', string $title = '', string $class = 'danger'): void
    {
        $session = self::session();
        foreach (self::keys as $key) {
            $session->delete($key);
        }
        $session->set('system-message', self::process_message($msg, $title ?: __('错误！'), $class));
        $session->set('has-error', '1');
    }

    /**
     * @return bool
     * @deprecated 弃用函数 使用静态函数 has_error_message() 代替
     */
    public function hasErrorMessage(): bool
    {
        return (bool)self::session()->get('has-error');
    }

    public static function has_error_message(): bool
    {
        return (bool)self::session()->get('has-error');
    }

    /**
     * @param \Exception $exception
     * @param string $title
     * @param string $class
     * @return $this
     * @deprecated   弃用函数 使用静态函数 exception() 代替
     */
    public function addException(\Exception $exception, string $title = '', string $class = 'warning')
    {
        $msg = $exception->getMessage();
        self::session()->append('system-message', self::process_message($msg, __('异常警告！'), $class));
        self::session()->set('has-exception', '1');
        return $this;
    }

    public static function exception(\Exception $exception, string $title = '', string $class = 'warning'): void
    {
        $msg = $exception->getMessage();
        self::session()->append('system-message', self::process_message($msg, $title, $class));
        self::session()->set('has-exception', '1');
    }

    /**@return bool
     * @deprecated   弃用函数 使用静态函数 has_exception() 代替
     */
    public function hasException(): bool
    {
        return (bool)self::session()->get('has-exception');
    }

    public static function has_exception(): bool
    {
        return (bool)self::session()->get('has-exception');
    }

    /**
     * @param string $msg
     * @param string $title
     * @param string $class
     * @return $this
     * @deprecated   弃用函数 使用静态函数 success() 代替
     */
    public function addSuccess(string $msg = '', string $title = '', string $class = 'success')
    {
        $title = $title ?: __('操作成功！');
        self::session()->append('system-message', self::process_message($msg, $title, $class));
        self::session()->set('has-success', '1');
        return $this;
    }

    public static function success(string $msg = '', string $title = '', string $class = 'success'): void
    {
        $title = $title ?: __('操作成功！');
        self::session()->append('system-message', self::process_message($msg, $title, $class));
        self::session()->set('has-success', '1');
    }

    /**
     * @return bool
     * @deprecated 使用静态函数 has_success_message() 代替
     */
    public function hasSuccessMessage(): bool
    {
        return (bool)self::session()->get('has-success');
    }

    public static function has_success_message(): bool
    {
        return (bool)self::session()->get('has-success');
    }


    /**弃用函数
     * @
     * @return bool
     * @throws \Exception
     * @deprecated 使用静态函数 warning() 代替
     */
    public function addWarning(string $msg = '', string $title = '', string $class = 'warning'): self
    {
        $title = $title ?: __('警告！');
        self::session()->append('system-message', self::process_message($msg, $title, $class));
        self::session()->set('has-warning', '1');
        return $this;
    }

    public static function warning(string $msg = '', string $title = '', string $class = 'warning'): void
    {
        $title = $title ?: __('警告！');
        self::session()->append('system-message', self::process_message($msg, $title, $class));
        self::session()->set('has-warning', '1');
    }

    /**
     * @return bool
     * @deprecated 使用静态函数 has_warning_message() 代替
     */
    public function hasWarningMessage(): bool
    {
        return (bool)self::session()->get('has-warning');
    }

    public static function has_warning_message(): bool
    {
        return (bool)self::session()->get('has-warning');
    }

    /**
     * @param string $msg
     * @param string $title
     * @param string $class
     * @return $this
     * @deprecated 使用静态函数 notes() 代替
     */
    public function addNotes(string $msg = '', string $title = '', string $class = 'notes')
    {
        $title = $title ?: __('提示！');
        self::session()->append('system-message', self::process_message($msg, $title, $class));
        self::session()->set('has-notes', '1');
        return $this;
    }

    public static function notes(string $msg = '', string $title = '', string $class = 'notes'): void
    {
        $title = $title ?: __('提示！');
        self::session()->append('system-message', self::process_message($msg, $title, $class));
        self::session()->set('has-notes', '1');
    }

    /**
     * @return bool
     * @deprecated 使用静态函数 has_notes_message() 代替
     */
    public function hasNotesMessage(): bool
    {
        return (bool)self::session()->get('has-notes');
    }

    public static function has_notes_message(): bool
    {
        return (bool)self::session()->get('has-notes');
    }

    /**
     * 输出并消费消息：读取后立即删除并持久化，WLS 下必须「读后即删」否则会累计重复显示。
     */
    public function render(): string
    {
        $session = self::session();
        $content = $session->get('system-message') ?? '';
        // 读取后立即删除并持久化，避免 WLS 跨请求残留导致提示累计
        foreach (self::keys as $key) {
            $session->delete($key);
        }
        if (method_exists($session, 'save')) {
            $session->save();
        }
        return "<div class='system message'>{$content}</div>";
    }

    /**
     * @param string $msg
     * @param string $title
     * @param string $html_class
     * @return string
     * @deprecated 使用静态函数 process_message() 代替
     */
    public function processMessage(string $msg, string $title, string $html_class = 'error'): string
    {
        return '<div class="alert alert-' . $html_class . ' alert-dismissible fade show" role="alert">
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            <strong>' . $title . '</strong> ' . $msg . '
        </div>';
    }

    public static function process_message(string $msg, string $title, string $html_class = 'error'): string
    {
        return '<div class="alert alert-' . $html_class . ' alert-dismissible fade show" role="alert">
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            <strong>' . $title . '</strong> ' . $msg . '
        </div>';
    }

    public function clear()
    {
        // 始终使用 self::session()，保证和静态方法写入的是同一个 session
        $session = self::session();
        foreach (self::keys as $key) {
            $session->delete($key);
        }
    }

    public function __toString(): string
    {
        return $this->render();
    }
}

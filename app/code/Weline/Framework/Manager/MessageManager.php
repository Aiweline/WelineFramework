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
     * 统一使用 SessionFactory，确保与控制器、ACL 同源，避免 WLS/FPM 下读写不同实例导致消息残留。
     */
    public static function session(): Session
    {
        $s = SessionFactory::getInstance()->createSession();
        assert($s instanceof Session);
        return $s;
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
        $title = $title ?: __('错误！');
        self::setSingleMessage($msg, $title, $class, 'has-error');
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
        $title = $title ?: __('异常警告！');
        self::setSingleMessage($msg, $title, $class, 'has-exception');
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
        self::setSingleMessage($msg, $title, $class, 'has-success');
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
        self::setSingleMessage($msg, $title, $class, 'has-warning');
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
        self::setSingleMessage($msg, $title, $class, 'has-notes');
    }

    /**
     * 写入单条消息：清空历史消息后写入当前消息。
     */
    private static function setSingleMessage(string $msg, string $title, string $class, string $flagKey): void
    {
        $session = self::session();
        foreach (self::keys as $key) {
            $session->delete($key);
        }
        $session->set('system-message', self::process_message($msg, $title, $class));
        $session->set($flagKey, '1');
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
     * 输出并消费消息：读取后立即删除并持久化。
     *
     * WLS 下必须「读后即删 + 立即落盘」，否则消息会跨请求残留、在所有界面重复显示。
     * 删除后显式 flush 确保响应发送前 Session 已落盘。
     */
    public function render(): string
    {
        $session = self::session();
        $content = $session->get('system-message') ?? '';
        // 读取后立即删除
        foreach (self::keys as $key) {
            $session->delete($key);
        }
        $session->set('system-message', '');
        $session->save();
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

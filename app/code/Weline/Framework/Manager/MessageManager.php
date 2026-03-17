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

    public const keys = [
        'has-error',
        'has-exception',
        'has-success',
        'has-warning',
        'has-notes',
        'system-message',
    ];

    /**
     * 每次从 SessionFactory 获取当前请求的 Session。
     * WLS 下 MessageManager 为常驻单例，构造函数只执行一次，若在构造内 cache Session 会持首请求的实例，
     * 与 SessionFactory::resetRequestInstances 后新请求创建的 Session 不一致，导致清理/落盘失效。
     */
    public function getSession(): Session
    {
        $session = SessionFactory::getInstance()->createSession();
        if (!$session instanceof Session) {
            throw new \RuntimeException('MessageManager requires Weline\Framework\Session\Session');
        }
        return $session;
    }

    public static function session(): Session
    {
        return ObjectManager::getInstance(self::class)->getSession();
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

    /** 本请求是否已 render（用于 flushRequestSessions 前决定是否清除所有 Session 的消息键） */
    private static bool $messagesRenderedThisRequest = false;

    /** 供 Session::flushRequestSessions 调用，避免耦合 */
    public static function shouldClearMessagesBeforeFlush(): bool
    {
        return self::$messagesRenderedThisRequest;
    }

    /** WLS 下每请求重置 */
    public static function resetRequestState(): void
    {
        self::$messagesRenderedThisRequest = false;
    }

    /**
     * 输出并消费消息：读取后立即删除并持久化。
     * flushRequestSessions 执行前会清除所有 Session 实例的消息键，解决多 Session 导致 message 残留。
     */
    public function render(): string
    {
        $session = $this->getSession();
        $content = $session->get('system-message') ?? '';
        self::$messagesRenderedThisRequest = true;
        $this->clear();
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

    /**
     * 清空系统消息，并立即落盘。
     * 清除当前 Session 及 instancesForShutdown 内所有实例的消息键，解决多实例导致消息残留。
     * flushRequestSessions 前会再次兜底清除。
     */
    public function clear(): void
    {
        Session::clearKeysFromInstances(self::keys);
        $session = $this->getSession();
        $session->save();
    }

    public function __toString(): string
    {
        return $this->render();
    }
}

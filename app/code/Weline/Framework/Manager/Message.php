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

class Message
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

    public static function session(): Session
    {
        return ObjectManager::getInstance(Session::class);
    }

    /**
     * @param string $msg
     * @param string $title
     * @param string $class
     * @return $this
     * @deprecated 弃用函数 使用静态函数 add_error() 代替
     */
    public function addError(string $msg = '', string $title = '', string $class = 'danger')
    {
        $title = $title ?: __('错误！');
        $this->session->addData('system-message', $this->processMessage($msg, $title, $class));
        $this->session->setData('has-error', '1');
        return $this;
    }

    public static function error(string $msg = '', string $title = '', string $class = 'danger'): void
    {
        $session = self::session();
        $session->addData('system-message', self::process_message($msg, $title, $class));
        $session->setData('has-error', '1');
    }

    /**
     * @return bool
     * @deprecated 弃用函数 使用静态函数 has_error_message() 代替
     */
    public function hasErrorMessage(): bool
    {
        return (bool)$this->session->getData('has-error');
    }

    public static function has_error_message(): bool
    {
        return (bool)self::session()->getData('has-error');
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
        $this->session->addData('system-message', $this->processMessage($msg, __('异常警告！'), $class));
        $this->session->setData('has-exception', '1');
        return $this;
    }

    public static function exception(\Exception $exception, string $title = '', string $class = 'warning'): void
    {
        $msg = $exception->getMessage();
        self::session()->addData('system-message', self::process_message($msg, $title, $class));
        self::session()->setData('has-exception', '1');
    }

    /**@return bool
     * @deprecated   弃用函数 使用静态函数 has_exception() 代替
     */
    public function hasException(): bool
    {
        return (bool)$this->session->getData('has-exception');
    }

    public static function has_exception(): bool
    {
        return (bool)self::session()->getData('has-exception');
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
        $this->session->addData('system-message', $this->processMessage($msg, $title, $class));
        $this->session->setData('has-success', '1');
        return $this;
    }

    public static function success(string $msg = '', string $title = '', string $class = 'success'): void
    {
        $title = $title ?: __('操作成功！');
        self::session()->addData('system-message', self::process_message($msg, $title, $class));
        self::session()->setData('has-success', '1');
    }

    /**
     * @return bool
     * @deprecated 使用静态函数 has_success_message() 代替
     */
    public function hasSuccessMessage(): bool
    {
        return (bool)$this->session->getData('has-success');
    }

    public static function has_success_message(): bool
    {
        return (bool)self::session()->getData('has-success');
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
        $this->session->addData('system-message', $this->processMessage($msg, $title, $class));
        $this->session->setData('has-warning', '1');
        return $this;
    }

    public static function warning(string $msg = '', string $title = '', string $class = 'warning'): void
    {
        $title = $title ?: __('警告！');
        self::session()->addData('system-message', self::process_message($msg, $title, $class));
        self::session()->setData('has-warning', '1');
    }

    /**
     * @return bool
     * @deprecated 使用静态函数 has_warning_message() 代替
     */
    public function hasWarningMessage(): bool
    {
        return (bool)$this->session->getData('has-warning');
    }

    public static function has_warning_message(): bool
    {
        return (bool)self::session()->getData('has-warning');
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
        $this->session->addData('system-message', $this->processMessage($msg, $title, $class));
        $this->session->setData('has-notes', '1');
        return $this;
    }

    public static function notes(string $msg = '', string $title = '', string $class = 'notes'): void
    {
        $title = $title ?: __('提示！');
        self::session()->addData('system-message', self::process_message($msg, $title, $class));
        self::session()->setData('has-notes', '1');
    }

    /**
     * @return bool
     * @deprecated 使用静态函数 has_notes_message() 代替
     */
    public function hasNotesMessage(): bool
    {
        return (bool)$this->session->getData('has-notes');
    }

    public static function has_notes_message(): bool
    {
        return (bool)self::session()->getData('has-notes');
    }

    public function render(): string
    {
        $html = "<div class='system message'>{$this->session->getData('system-message')}</div>";
        $this->clear();
        return $html;
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
        foreach (self::keys as $key) {
            $this->session->delete($key);
        }
    }

    public function __toString(): string
    {
        return $this->render();
    }
}

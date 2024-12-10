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
    ) {
        $this->session = $session;
    }

    public function addError(string $msg = '', string $title = '', string $class = 'danger')
    {
        $title = $title ?: __('错误！');
        $this->session->addData('system-message', $this->processMessage($msg, $title, $class));
        $this->session->setData('has-error', '1');
        return $this;
    }

    public function hasErrorMessage(): bool
    {
        return (bool)$this->session->getData('has-error');
    }

    public function addException(\Exception $exception, string $title = '', string $class = 'warning')
    {
        $msg = $exception->getMessage();
        $this->session->addData('system-message', $this->processMessage($msg, __('异常警告！'), $class));
        $this->session->setData('has-exception', '1');
        return $this;
    }

    public function hasException(): bool
    {
        return (bool)$this->session->getData('has-exception');
    }

    public function addSuccess(string $msg = '', string $title = '', string $class = 'success')
    {
        $title = $title ?: __('操作成功！');
        $this->session->addData('system-message', $this->processMessage($msg, $title, $class));
        $this->session->setData('has-success', '1');
        return $this;
    }

    public function hasSuccessMessage(): bool
    {
        return (bool)$this->session->getData('has-success');
    }

    public function addWarning(string $msg = '', string $title = '', string $class = 'warning')
    {
        $title = $title ?: __('警告！');
        $this->session->addData('system-message', $this->processMessage($msg, $title, $class));
        $this->session->setData('has-warning', '1');
        return $this;
    }

    public function hasWarningMessage(): bool
    {
        return (bool)$this->session->getData('has-warning');
    }

    public function addNotes(string $msg = '', string $title = '', string $class = 'notes')
    {
        $title = $title ?: __('提示！');
        $this->session->addData('system-message', $this->processMessage($msg, $title, $class));
        $this->session->setData('has-notes', '1');
        return $this;
    }

    public function hasNotesMessage(): bool
    {
        return (bool)$this->session->getData('has-notes');
    }

    public function render(): string
    {
        $html = "<div class='system message'>{$this->session->getData('system-message')}</div>";
        $this->clear();
        return $html;
    }

    public function processMessage(string $msg, string $title, string $html_class = 'error'): string
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

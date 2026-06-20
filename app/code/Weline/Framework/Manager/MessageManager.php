<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Manager;

use Weline\Framework\Http\Cookie;
use Weline\Framework\Session\Session;
use Weline\Framework\Session\SessionFactory;

class MessageManager
{
    /** Flash 消息 Cookie 名，独立于 Session，不受 session destroy 影响 */
    private const FLASH_COOKIE = 'w_flash';

    /** Flash Cookie 存活秒数（重定向+渲染一次请求内有效） */
    private const FLASH_TTL = 120;

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
        self::flashAppend(self::process_message($msg, $title ?: __('错误！'), $class), 'has-error');
        return $this;
    }

    public static function add_error(string $msg = '', string $title = '', string $class = 'danger'): self
    {
        self::flashAppend(self::process_message($msg, $title ?: __('错误！'), $class), 'has-error');
        return ObjectManager::getInstance(self::class);
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
        return self::has_error_message();
    }

    public static function has_error_message(): bool
    {
        $flash = self::flashRead();
        if ($flash !== null) {
            return $flash['f'] === 'has-error';
        }
        return (bool)self::session()->get('has-error');
    }

    /**
     * 获取并消费错误消息内容
     */
    public static function get_error_message(): ?string
    {
        $flash = self::flashRead();
        if ($flash !== null && $flash['f'] === 'has-error') {
            self::flashDelete();
            return self::flashContentToPlainText($flash['c']);
        }
        return null;
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
        self::flashAppend(self::process_message($exception->getMessage(), $title ?: __('异常警告！'), $class), 'has-exception');
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
        return self::has_exception();
    }

    public static function has_exception(): bool
    {
        $flash = self::flashRead();
        if ($flash !== null) {
            return $flash['f'] === 'has-exception';
        }
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
        self::flashAppend(self::process_message($msg, $title ?: __('操作成功！'), $class), 'has-success');
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
        return self::has_success_message();
    }

    public static function has_success_message(): bool
    {
        $flash = self::flashRead();
        if ($flash !== null) {
            return $flash['f'] === 'has-success';
        }
        return (bool)self::session()->get('has-success');
    }

    /**
     * 获取并消费成功消息内容
     */
    public static function get_success_message(): ?string
    {
        $flash = self::flashRead();
        if ($flash !== null && $flash['f'] === 'has-success') {
            self::flashDelete();
            return self::flashContentToPlainText($flash['c']);
        }
        return null;
    }


    /**弃用函数
     * @
     * @return bool
     * @throws \Exception
     * @deprecated 使用静态函数 warning() 代替
     */
    public function addWarning(string $msg = '', string $title = '', string $class = 'warning'): self
    {
        self::flashAppend(self::process_message($msg, $title ?: __('警告！'), $class), 'has-warning');
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
        return self::has_warning_message();
    }

    public static function has_warning_message(): bool
    {
        $flash = self::flashRead();
        if ($flash !== null) {
            return $flash['f'] === 'has-warning';
        }
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
        self::flashAppend(self::process_message($msg, $title ?: __('提示！'), $class), 'has-notes');
        return $this;
    }

    public static function notes(string $msg = '', string $title = '', string $class = 'notes'): void
    {
        $title = $title ?: __('提示！');
        self::setSingleMessage($msg, $title, $class, 'has-notes');
    }

    /**
     * 写入单条消息：清空历史消息后写入当前消息。
     * 存于 Cookie，与 Session 解耦，不受 session destroy 影响。
     */
    private static function setSingleMessage(string $msg, string $title, string $class, string $flagKey): void
    {
        $content = self::process_message($msg, $title, $class);
        self::flashWrite($content, $flagKey);
    }

    /** 写入 Flash 到 Cookie */
    private static function flashWrite(string $content, string $flagKey): void
    {
        $payload = base64_encode(json_encode(['c' => $content, 'f' => $flagKey], \JSON_THROW_ON_ERROR));
        Cookie::set(self::FLASH_COOKIE, $payload, self::FLASH_TTL, ['path' => '/', 'httponly' => true]);
    }

    /** 读取 Flash 从 Cookie，返回 ['c' => content, 'f' => flag] 或 null */
    private static function flashRead(): ?array
    {
        $raw = Cookie::get(self::FLASH_COOKIE);
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = @base64_decode($raw, true);
        if ($decoded === false) {
            return null;
        }
        try {
            $data = json_decode($decoded, true, 2, \JSON_THROW_ON_ERROR);
            return \is_array($data) && isset($data['c'], $data['f']) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** 删除 Flash Cookie */
    private static function flashDelete(): void
    {
        Cookie::delete(self::FLASH_COOKIE, ['path' => '/']);
    }

    /** 追加到 Flash（deprecated append 方法用） */
    private static function flashAppend(string $html, string $flagKey): void
    {
        $existing = self::flashRead();
        $content = ($existing['c'] ?? '') . $html;
        self::flashWrite($content, $flagKey);
    }

    /**
     * @return bool
     * @deprecated 使用静态函数 has_notes_message() 代替
     */
    public function hasNotesMessage(): bool
    {
        return self::has_notes_message();
    }

    public static function has_notes_message(): bool
    {
        $flash = self::flashRead();
        if ($flash !== null) {
            return $flash['f'] === 'has-notes';
        }
        return (bool)self::session()->get('has-notes');
    }

    /** 本请求是否已 render（用于 flushRequestSessions 前决定是否清除所有 Session 的消息键） */
    private static bool $messagesRenderedThisRequest = false;

    /** 本请求是否已输出 flash 关闭按钮代理脚本 */
    private static bool $flashDismissScriptRenderedThisRequest = false;

    /** 供 Session::flushRequestSessions 调用，避免耦合 */
    public static function shouldClearMessagesBeforeFlush(): bool
    {
        return self::$messagesRenderedThisRequest;
    }

    /** WLS 下每请求重置 */
    public static function resetRequestState(): void
    {
        self::$messagesRenderedThisRequest = false;
        self::$flashDismissScriptRenderedThisRequest = false;
    }

    /**
     * 输出并消费消息：优先读 Cookie Flash，再回退 Session，读取后立即删除。
     * Cookie Flash 与 Session 解耦，不受 session destroy 影响。
     */
    public function render(): string
    {
        $flash = self::flashRead();
        if ($flash !== null) {
            $content = $flash['c'];
        } else {
            $session = $this->getSession();
            $content = $session->get('system-message') ?? '';
        }
        self::$messagesRenderedThisRequest = true;
        $this->clear();
        return "<div class='system message'>{$content}</div>";
    }

    /**
     * Flash 展示用 modifier（仅允许 [a-z0-9_-]+），与 storefront `.wflash--{modifier}` 对应；非 Bootstrap / 第三方 UI 库类名。
     */
    private static function normalizeFlashModifier(string $html_class): string
    {
        $c = strtolower(trim($html_class));
        if ($c === 'error') {
            $c = 'danger';
        }
        $slug = preg_replace('/[^a-z0-9_-]+/', '', $c) ?? '';
        return $slug !== '' ? $slug : 'info';
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
        return self::process_message($msg, $title, $html_class);
    }

    public static function process_message(string $msg, string $title, string $html_class = 'error'): string
    {
        $mod = self::normalizeFlashModifier($html_class);
        $closeLabel = htmlspecialchars(__('Close'), ENT_QUOTES, 'UTF-8');
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeMsg = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');

        return self::flashDismissScript()
            . '<div class="wflash wflash--' . $mod . ' wflash--dismissible" role="alert">'
            . '<button type="button" class="wflash__close" aria-label="' . $closeLabel . '" data-wflash-dismiss="1"></button>'
            . '<strong class="wflash__title">' . $safeTitle . '</strong> '
            . '<span class="wflash__body">' . $safeMsg . '</span>'
            . '</div>';
    }

    private static function flashDismissScript(): string
    {
        if (self::$flashDismissScriptRenderedThisRequest) {
            return '';
        }

        self::$flashDismissScriptRenderedThisRequest = true;

        return '<script data-wflash-dismiss-proxy="1">(function(){var root=document.documentElement;if(root.dataset.wflashDismissProxyBound==="1")return;root.dataset.wflashDismissProxyBound="1";document.addEventListener("click",function(event){var target=event.target;if(!(target instanceof Element))return;var trigger=target.closest("[data-wflash-dismiss]");if(!trigger)return;var alert=trigger.closest("[role=alert]");if(alert)alert.remove();});})();</script>';
    }

    /**
     * 清空系统消息：删除 Flash Cookie 并清除 Session 消息键。
     */
    public function clear(): void
    {
        self::flashDelete();
        Session::clearKeysFromInstances(self::keys);
        $session = $this->getSession();
        $session->save();
    }

    public function __toString(): string
    {
        return $this->render();
    }

    /**
     * Cookie Flash 中 historically 存的是 `.wflash` / 旧版 alert HTML；供控制器/模板当作纯文案展示时须去掉全部标签，避免 htmlspecialchars 后用户看到原始标签串。
     */
    private static function flashContentToPlainText(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}

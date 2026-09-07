<?php

declare(strict_types=1);

namespace Weline\Mail\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Template;

/**
 * Global enterprise-mail composer overlay (FileManager-style).
 * Usage: &lt;w:mail-composer/&gt; then WelineMailComposer.open({...}).
 */
final class MailComposer implements TaglibInterface
{
    public static function name(): string
    {
        return 'mail-composer';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'id' => false,
            'area' => false,
        ];
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function tag_self_close(): bool
    {
        return true;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    public static function parent(): ?string
    {
        return null;
    }

    public static function document(): string
    {
        return 'doc/mail-composer-taglib.md';
    }

    public static function callback(): callable
    {
        return static function ($tag_key, $config, $tag_data, $attributes) {
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($attributes['id'] ?? 'weline-mail-composer')) ?: 'weline-mail-composer';
            $area = strtolower(trim((string)($attributes['area'] ?? 'auto')));
            if (!in_array($area, ['backend', 'frontend', 'auto'], true)) {
                $area = 'auto';
            }

            $cssUrl = htmlspecialchars(self::resolveStatic('Weline_Mail::css/mail-composer.css') . '?v=20260906-1', ENT_QUOTES);
            $jsUrl = htmlspecialchars(self::resolveStatic('Weline_Mail::js/mail-composer.js') . '?v=20260906-1', ENT_QUOTES);

            $tTitle = htmlspecialchars((string)__('企业邮箱沟通'), ENT_QUOTES);
            $tClose = htmlspecialchars((string)__('关闭'), ENT_QUOTES);
            $tTo = htmlspecialchars((string)__('收件人'), ENT_QUOTES);
            $tSubject = htmlspecialchars((string)__('主题'), ENT_QUOTES);
            $tBody = htmlspecialchars((string)__('正文'), ENT_QUOTES);
            $tAccount = htmlspecialchars((string)__('发件账号'), ENT_QUOTES);
            $tSend = htmlspecialchars((string)__('发送'), ENT_QUOTES);
            $tHistory = htmlspecialchars((string)__('沟通历史'), ENT_QUOTES);
            $tEmpty = htmlspecialchars((string)__('暂无沟通记录'), ENT_QUOTES);
            $tLoading = htmlspecialchars((string)__('加载中…'), ENT_QUOTES);

            $html = [];
            $html[] = '<link rel="stylesheet" href="' . $cssUrl . '" data-no-extract="true">';
            $html[] = '<div class="w-mail-composer" id="' . htmlspecialchars($id, ENT_QUOTES) . '"'
                . ' data-w-component="mail-composer"'
                . ' data-mail-composer-area="' . htmlspecialchars($area, ENT_QUOTES) . '"'
                . ' hidden aria-hidden="true">';
            $html[] = '  <div class="w-mail-composer__panel" role="dialog" aria-modal="true" aria-labelledby="' . htmlspecialchars($id, ENT_QUOTES) . '-title">';
            $html[] = '    <header class="w-mail-composer__header">';
            $html[] = '      <h2 class="w-mail-composer__title" id="' . htmlspecialchars($id, ENT_QUOTES) . '-title">' . $tTitle . '</h2>';
            $html[] = '      <button type="button" class="w-button" data-size="sm" data-variant="outline" data-mail-composer-close aria-label="' . $tClose . '">' . $tClose . '</button>';
            $html[] = '    </header>';
            $html[] = '    <div class="w-mail-composer__body">';
            $html[] = '      <section class="w-mail-composer__thread" aria-label="' . $tHistory . '">';
            $html[] = '        <h3 class="w-mail-composer__section-title">' . $tHistory . '</h3>';
            $html[] = '        <div class="w-mail-composer__thread-list" data-mail-composer-thread data-empty="' . $tEmpty . '" data-loading="' . $tLoading . '"></div>';
            $html[] = '      </section>';
            $html[] = '      <section class="w-mail-composer__form-wrap" data-mail-composer-compose>';
            $html[] = '        <label class="w-field"><span class="w-field__label">' . $tAccount . '</span>';
            $html[] = '          <select class="w-select" data-mail-composer-account></select>';
            $html[] = '        </label>';
            $html[] = '        <label class="w-field"><span class="w-field__label">' . $tTo . '</span>';
            $html[] = '          <input class="w-input" type="email" data-mail-composer-to required>';
            $html[] = '        </label>';
            $html[] = '        <label class="w-field"><span class="w-field__label">' . $tSubject . '</span>';
            $html[] = '          <input class="w-input" type="text" data-mail-composer-subject maxlength="180" required>';
            $html[] = '        </label>';
            $html[] = '        <label class="w-field"><span class="w-field__label">' . $tBody . '</span>';
            $html[] = '          <textarea class="w-textarea" rows="7" data-mail-composer-body required></textarea>';
            $html[] = '        </label>';
            $html[] = '        <div class="w-mail-composer__actions">';
            $html[] = '          <button type="button" class="w-button" data-tone="primary" data-mail-composer-send>' . $tSend . '</button>';
            $html[] = '        </div>';
            $html[] = '        <p class="w-text" data-tone="danger" data-size="sm" data-mail-composer-error hidden></p>';
            $html[] = '      </section>';
            $html[] = '    </div>';
            $html[] = '  </div>';
            $html[] = '</div>';
            $html[] = '<script src="' . $jsUrl . '" defer data-no-extract="true"></script>';

            return implode("\n", $html);
        };
    }

    private static function resolveStatic(string $source): string
    {
        $template = ObjectManager::getInstance(Template::class);

        return (string)$template->fetchTagSource(DataInterface::dir_type_STATICS, $source);
    }
}

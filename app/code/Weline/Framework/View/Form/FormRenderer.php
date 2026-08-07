<?php

declare(strict_types=1);

namespace Weline\Framework\View\Form;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\View\Block\Csrf;
use Weline\Framework\View\Template;

/**
 * Runtime renderer for the explicit <w:form> Taglib.
 *
 * Context is kept in RequestContext rather than process statics, so concurrent
 * WLS requests and template fragments cannot leak form state into one another.
 */
final class FormRenderer
{
    public const EVENT_PREPARE = 'Weline_Framework::view::form::prepare';
    public const EVENT_BEFORE_CLOSE = 'Weline_Framework::view::form::before_close';

    private const CONTEXT_KEY = 'framework.view.form.stack';
    private const ALLOWED_METHODS = ['get', 'post'];
    private const ALLOWED_ENCTYPES = [
        'application/x-www-form-urlencoded',
        'multipart/form-data',
        'text/plain',
    ];
    private const ALLOWED_AUTOCOMPLETE = ['on', 'off'];
    private const STRING_ATTRIBUTES = [
        'id',
        'method',
        'action',
        'class',
        'enctype',
        'autocomplete',
        'name',
        'target',
        'rel',
        'accept-charset',
        'role',
        'style',
    ];
    private const BOOLEAN_ATTRIBUTES = ['novalidate'];
    private const RESERVED_DATA_ATTRIBUTES = [
        'data-weline-form',
        'data-weline-form-intent',
        'data-weline-form-captcha',
        'data-weline-form-mounted',
    ];

    /** @param array<string, mixed> $attributes */
    public static function open(array $attributes): string
    {
        $normalized = self::normalizeAttributes($attributes);
        $eventData = new DataObject([
            'attributes' => $normalized,
            'intent' => $normalized['intent'],
            'form_id' => $normalized['id'],
        ]);
        ObjectManager::getInstance(EventsManager::class)->dispatch(self::EVENT_PREPARE, $eventData);
        $prepared = $eventData->getData('attributes');
        if (\is_array($prepared)) {
            $normalized = self::normalizeAttributes($prepared + $normalized);
        }

        $stack = RequestContext::get(self::CONTEXT_KEY, []);
        if (!\is_array($stack)) {
            $stack = [];
        }
        $stack[] = $normalized;
        RequestContext::set(self::CONTEXT_KEY, $stack);

        $htmlAttributes = $normalized['html_attributes'];
        $htmlAttributes['data-weline-form'] = '1';
        $htmlAttributes['data-weline-form-intent'] = $normalized['intent'];
        $htmlAttributes['data-weline-form-captcha'] = $normalized['captcha'];

        $parts = [];
        foreach ($htmlAttributes as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            if ($value === true) {
                $parts[] = $name;
                continue;
            }
            $parts[] = $name . '="' . \htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return '<form ' . \implode(' ', $parts) . '>';
    }

    /**
     * Render a paired form that reached the Taglib runtime branch.
     *
     * AttributeCodeCompiler normally resolves bare template-variable names at
     * compile time. Runtime tags receive their literal attribute tokens, so
     * resolve only exact template-data keys and leave ordinary literals intact.
     *
     * @param array<string, mixed> $attributes
     */
    public static function renderRuntime(Template $template, array $attributes, string $content): string
    {
        $scope = $template->getData();
        if (!\is_array($scope)) {
            $scope = [];
        }
        foreach ($attributes as $name => $value) {
            if (!\is_string($value) || !\array_key_exists($value, $scope)) {
                continue;
            }
            $attributes[$name] = $scope[$value];
        }

        return self::open($attributes) . $content . self::close();
    }

    public static function close(): string
    {
        $stack = RequestContext::get(self::CONTEXT_KEY, []);
        if (!\is_array($stack) || $stack === []) {
            throw new \LogicException((string)__('w:form 结束标签缺少对应开始标签'));
        }
        $context = $stack[\array_key_last($stack)];
        if (!\is_array($context)) {
            throw new \LogicException((string)__('w:form 运行时上下文无效'));
        }

        $html = '';
        if ($context['csrf'] === 'on' || ($context['csrf'] === 'auto' && $context['method'] === 'post')) {
            $html .= ObjectManager::getInstance(Csrf::class)->render('csrf');
        }

        $eventData = new DataObject([
            'attributes' => $context,
            'intent' => $context['intent'],
            'form_id' => $context['id'],
            'html' => '',
        ]);
        ObjectManager::getInstance(EventsManager::class)->dispatch(self::EVENT_BEFORE_CLOSE, $eventData);
        $extensionHtml = $eventData->getData('html');
        if (\is_string($extensionHtml) && \strlen($extensionHtml) <= 262144) {
            $html .= $extensionHtml;
        }
        \array_pop($stack);
        RequestContext::set(self::CONTEXT_KEY, $stack);

        $html .= '</form>' . self::runtimeBootstrap();

        return $html;
    }

    /** @return array<string, string>|null */
    public static function current(): ?array
    {
        $stack = RequestContext::get(self::CONTEXT_KEY, []);
        if (!\is_array($stack) || $stack === []) {
            return null;
        }
        $context = $stack[\array_key_last($stack)];
        return \is_array($context) ? $context : null;
    }

    /** @param array<string, mixed> $attributes
     *  @return array<string, mixed>
     */
    private static function normalizeAttributes(array $attributes): array
    {
        $nested = $attributes['attributes'] ?? null;
        $preparedHtmlAttributes = $attributes['html_attributes'] ?? null;
        unset($attributes['attributes']);
        if (\is_array($nested)) {
            $attributes = \array_replace($nested, $attributes);
        }
        unset($attributes['html_attributes']);
        if (\is_array($preparedHtmlAttributes)) {
            $attributes = \array_replace($preparedHtmlAttributes, $attributes);
        }

        $method = \strtolower(\trim((string)($attributes['method'] ?? 'get')));
        if (!\in_array($method, self::ALLOWED_METHODS, true)) {
            $method = 'get';
        }

        $id = \trim((string)($attributes['id'] ?? ''));
        if ($id === '' || \preg_match('/\A[A-Za-z][A-Za-z0-9_:.-]{0,127}\z/D', $id) !== 1) {
            $id = 'weline-form-' . \bin2hex(\random_bytes(8));
        }

        $action = \trim((string)($attributes['action'] ?? ''));
        if ($action !== '' && !self::isSafeAction($action)) {
            throw new \InvalidArgumentException((string)__('w:form action 不允许使用危险协议'));
        }

        $class = self::stripControls((string)($attributes['class'] ?? ''));

        $enctype = \strtolower(\trim((string)($attributes['enctype'] ?? '')));
        if ($enctype !== '' && !\in_array($enctype, self::ALLOWED_ENCTYPES, true)) {
            $enctype = '';
        }

        $autocomplete = \strtolower(\trim((string)($attributes['autocomplete'] ?? '')));
        if ($autocomplete !== '' && !\in_array($autocomplete, self::ALLOWED_AUTOCOMPLETE, true)) {
            $autocomplete = '';
        }

        $csrf = self::normalizeSwitch((string)($attributes['csrf'] ?? 'auto'), ['auto', 'on', 'off'], 'auto');
        // Captcha is opt-in: ordinary POST forms (including async admin actions) stay clean
        // unless a template explicitly sets captcha="auto|required".
        $captcha = self::normalizeSwitch((string)($attributes['captcha'] ?? 'off'), ['auto', 'required', 'off'], 'off');
        // Async form posts are handled by XHR/bin-query and never go through the
        // synchronous captcha challenge path, so keep the widget out of the markup.
        if ($captcha !== 'off' && isset($attributes['data-async-action'])) {
            $captcha = 'off';
        }
        $intent = \trim((string)($attributes['intent'] ?? 'generic'));
        if (\preg_match('/\A[A-Za-z0-9_.:-]{1,80}\z/D', $intent) !== 1) {
            $intent = 'generic';
        }

        $htmlAttributes = [];
        foreach (self::STRING_ATTRIBUTES as $name) {
            if (!\array_key_exists($name, $attributes)) {
                continue;
            }
            $value = match ($name) {
                'id' => $id,
                'method' => $method,
                'action' => $action,
                'class' => $class,
                'enctype' => $enctype,
                'autocomplete' => $autocomplete,
                'target' => self::normalizeTarget((string)$attributes[$name]),
                'rel' => self::normalizeTokens((string)$attributes[$name], 20),
                'accept-charset' => self::normalizeAcceptCharset((string)$attributes[$name]),
                'role' => self::normalizeTokens((string)$attributes[$name], 4),
                'style' => self::normalizeStyle((string)$attributes[$name]),
                default => self::stripControls((string)$attributes[$name]),
            };
            if ($value !== '') {
                $htmlAttributes[$name] = $value;
            }
        }
        $htmlAttributes['id'] = $id;
        $htmlAttributes['method'] = $method;

        foreach (self::BOOLEAN_ATTRIBUTES as $name) {
            if (\array_key_exists($name, $attributes) && self::normalizeBoolean($attributes[$name])) {
                $htmlAttributes[$name] = true;
            }
        }

        foreach ($attributes as $name => $value) {
            $name = \strtolower((string)$name);
            if (
                \in_array($name, self::RESERVED_DATA_ATTRIBUTES, true)
                || \preg_match('/\A(?:data|aria)-[a-z0-9_.:-]{1,96}\z/D', $name) !== 1
                || $value === null
                || $value === false
                || \is_array($value)
                || \is_object($value)
                || \is_resource($value)
            ) {
                continue;
            }
            $htmlAttributes[$name] = ($value === '' || $value === true)
                ? true
                : self::stripControls((string)$value);
        }

        return [
            'id' => $id,
            'method' => $method,
            'action' => $action,
            'class' => $class,
            'enctype' => $enctype,
            'autocomplete' => $autocomplete,
            'intent' => $intent,
            'csrf' => $csrf,
            'captcha' => $captcha,
            'html_attributes' => $htmlAttributes,
        ];
    }

    public static function runtimeBootstrap(): string
    {
        return <<<'HTML'
<script>(function(w,d){
w.Weline=w.Weline||{};
if(!w.WelineFormRuntime){
var mount=function(form){
if(!form||form.nodeName!=="FORM"||form.dataset.welineFormMounted==="1"){return form;}
form.dataset.welineFormMounted="1";
form.addEventListener("submit",function(event){
var detail={form:form,originalEvent:event,intent:form.dataset.welineFormIntent||""};
var prepare=new CustomEvent("weline:form:prepare-submit",{bubbles:true,cancelable:true,detail:detail});
if(!form.dispatchEvent(prepare)){event.preventDefault();}
});
queueMicrotask(function(){
form.dispatchEvent(new CustomEvent("weline:form:mounted",{bubbles:true,detail:{form:form,intent:form.dataset.welineFormIntent||""}}));
});
return form;
};
var mountAll=function(root){
if(root&&root.matches&&root.matches("form[data-weline-form]")){mount(root);}
if(root&&root.querySelectorAll){root.querySelectorAll("form[data-weline-form]").forEach(mount);}
};
var api={mount:mount,mountAll:mountAll};
var expose=function(){w.Weline=w.Weline||{};w.Weline.Form=api;};
w.WelineFormRuntime=api;
expose();
mountAll(d);
new MutationObserver(function(records){
expose();
records.forEach(function(record){record.addedNodes.forEach(function(node){if(node.nodeType===1){mountAll(node);}});});
}).observe(d.documentElement,{childList:true,subtree:true});
d.addEventListener("DOMContentLoaded",expose);
w.addEventListener("load",expose);
}
w.Weline=w.Weline||{};
w.Weline.Form=w.WelineFormRuntime;
w.WelineFormRuntime.mountAll(d);
})(window,document);</script>
HTML;
    }

    private static function normalizeBoolean(mixed $value): bool
    {
        if ($value === '' || $value === true || $value === 1) {
            return true;
        }
        return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'on', 'novalidate'], true);
    }

    private static function normalizeTarget(string $target): string
    {
        $target = \trim($target);
        return \preg_match('/\A(?:_(?:self|blank|parent|top)|[A-Za-z][A-Za-z0-9_.:-]{0,63})\z/D', $target) === 1
            ? $target
            : '';
    }

    private static function normalizeTokens(string $value, int $limit): string
    {
        $tokens = \preg_split('/\s+/', \trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = \array_slice($tokens, 0, $limit);
        $tokens = \array_values(\array_filter(
            $tokens,
            static fn(string $token): bool => \preg_match('/\A[A-Za-z0-9_.:-]{1,64}\z/D', $token) === 1,
        ));
        return \implode(' ', $tokens);
    }

    private static function normalizeAcceptCharset(string $value): string
    {
        return \strcasecmp(\trim($value), 'UTF-8') === 0 ? 'UTF-8' : '';
    }

    private static function normalizeStyle(string $style): string
    {
        $style = self::stripControls($style);
        if (\preg_match('/(?:expression\s*\(|javascript\s*:|data\s*:)/i', $style) === 1) {
            return '';
        }
        return $style;
    }

    private static function stripControls(string $value): string
    {
        return \trim((string)(\preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? ''));
    }

    private static function normalizeSwitch(string $value, array $allowed, string $default): string
    {
        $value = \strtolower(\trim($value));
        return \in_array($value, $allowed, true) ? $value : $default;
    }

    private static function isSafeAction(string $action): bool
    {
        if (
            \str_starts_with($action, '//')
            || \str_contains($action, '\\')
            || \preg_match('/[\x00-\x1F\x7F]/', $action) === 1
        ) {
            return false;
        }
        if (\str_starts_with($action, '/') || \str_starts_with($action, '?') || \str_starts_with($action, '#')) {
            return true;
        }
        $scheme = \parse_url($action, PHP_URL_SCHEME);
        if ($scheme === null) {
            return true;
        }
        if (!\is_string($scheme)) {
            return false;
        }
        if (!\in_array(\strtolower($scheme), ['http', 'https'], true)) {
            return false;
        }
        return \is_string(\parse_url($action, PHP_URL_HOST))
            && \parse_url($action, PHP_URL_HOST) !== '';
    }
}

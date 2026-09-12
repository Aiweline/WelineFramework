<?php

declare(strict_types=1);

namespace Weline\Dropship\Taglib;

use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Theme\Taglib\SearchSelect;

/**
 * 货源远程仓选择（依赖供应商；SearchSelect + Provider 仓表）。
 *
 * <w:dropship:remote-warehouse:select
 *     id="dropship-wh-storage"
 *     name="remote_storage_id"
 *     provider-select="#dropship-wh-provider"
 * />
 */
final class RemoteWarehouseSelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'dropship:remote-warehouse:select';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'id' => true,
            'name' => false,
            'value' => false,
            'provider-select' => false,
            'country-input' => false,
            'placeholder' => false,
            'class' => false,
            'style' => false,
            'disabled' => false,
            'required' => false,
            'clearable' => false,
            'limit' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tag_key, $config, $tag_data, $attributes) {
            unset($tag_key, $config, $tag_data);
            $attributes = is_array($attributes) ? $attributes : [];
            $id = trim((string)($attributes['id'] ?? ''));
            if ($id === '') {
                throw new \Exception((string)__('id属性不能为空'));
            }
            $name = trim((string)($attributes['name'] ?? 'remote_storage_id'));
            $providerSelect = trim((string)($attributes['provider-select'] ?? '#dropship-wh-provider'));
            if ($providerSelect === '') {
                $providerSelect = '#dropship-wh-provider';
            }
            $countryInput = trim((string)($attributes['country-input'] ?? 'input[name="remote_country_code"]'));
            /** @var Url $urlBuilder */
            $urlBuilder = ObjectManager::getInstance(Url::class);
            $baseUrl = $urlBuilder->getBackendUrl('dropship/backend/warehouse/remoteSearch');
            $markup = SearchSelect::buildMarkup([
                'id' => $id,
                'name' => $name,
                'url' => $baseUrl,
                'value' => (string)($attributes['value'] ?? ''),
                'placeholder' => (string)($attributes['placeholder'] ?? __('搜索远程仓名称或 ID')),
                'class' => (string)($attributes['class'] ?? ''),
                'style' => (string)($attributes['style'] ?? ''),
                'disabled' => (string)($attributes['disabled'] ?? ''),
                'required' => (string)($attributes['required'] ?? ''),
                'clearable' => (string)($attributes['clearable'] ?? 'true'),
                'limit' => (string)($attributes['limit'] ?? '50'),
                'min-chars' => '0',
                'value-field' => 'value',
                'label-field' => 'label',
            ]);

            $idJs = json_encode($id, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $baseJs = json_encode($baseUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $providerJs = json_encode($providerSelect, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $countryJs = json_encode($countryInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $markup . <<<HTML
<script data-w-dropship-remote-wh-sync="1">(function(){
  var id = {$idJs};
  var baseUrl = {$baseJs};
  var providerSel = {$providerJs};
  var countrySel = {$countryJs};
  function providerEl() {
    try { return document.querySelector(providerSel); } catch (e) { return null; }
  }
  function countryEl() {
    try { return document.querySelector(countrySel); } catch (e) { return null; }
  }
  function buildUrl() {
    var p = providerEl();
    var c = countryEl();
    var code = p ? String(p.value || '') : '';
    var country = c ? String(c.value || '').trim().toUpperCase() : '';
    var url = baseUrl + (baseUrl.indexOf('?') > -1 ? '&' : '?') + 'provider_code=' + encodeURIComponent(code);
    if (country) {
      url += '&country_code=' + encodeURIComponent(country);
    }
    return url;
  }
  function syncUrlOnly() {
    var el = document.getElementById(id + '_container');
    if (!el) { return; }
    el.setAttribute('data-api-url', buildUrl());
    if (typeof el.__welineSearchSelectInvalidate === 'function') {
      el.__welineSearchSelectInvalidate();
    }
  }
  function clearValue() {
    var el = document.getElementById(id + '_container');
    var hidden = document.getElementById(id + '_value');
    var display = document.getElementById(id + '_display');
    var input = document.getElementById(id + '_input');
    if (hidden) {
      hidden.value = '';
      hidden.dispatchEvent(new Event('input', { bubbles: true }));
      hidden.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (display) { display.textContent = ''; }
    if (input) { input.value = ''; }
    if (el) { el.classList.remove('has-value'); }
  }
  function syncProvider() {
    syncUrlOnly();
    clearValue();
  }
  function fillCountryFromLabel() {
    var display = document.getElementById(id + '_display');
    var text = display ? String(display.textContent || '') : '';
    var m = text.match(/\(([A-Z]{2})\)\s*\[[^\]]*\]\s*$/);
    var cc = m ? m[1] : '';
    if (!cc) { return; }
    var c = countryEl();
    if (!c) { return; }
    if (String(c.value || '').toUpperCase() === cc) { return; }
    c.value = cc;
    c.dispatchEvent(new Event('input', { bubbles: true }));
    c.dispatchEvent(new Event('change', { bubbles: true }));
  }
  function bind() {
    var p = providerEl();
    var c = countryEl();
    var hidden = document.getElementById(id + '_value');
    if (p && p.getAttribute('data-w-dropship-remote-wh-bound') !== '1') {
      p.setAttribute('data-w-dropship-remote-wh-bound', '1');
      p.addEventListener('change', syncProvider);
    }
    if (c && c.getAttribute('data-w-dropship-remote-wh-country-bound') !== '1') {
      c.setAttribute('data-w-dropship-remote-wh-country-bound', '1');
      c.addEventListener('change', syncUrlOnly);
    }
    if (hidden && hidden.getAttribute('data-w-dropship-remote-wh-value-bound') !== '1') {
      hidden.setAttribute('data-w-dropship-remote-wh-value-bound', '1');
      hidden.addEventListener('change', fillCountryFromLabel);
    }
    syncUrlOnly();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();</script>
HTML;
        };
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
        return htmlspecialchars(
            '<h3><code>&lt;w:dropship:remote-warehouse:select&gt;</code></h3>'
            . '<p>货源远程仓可搜索单选；数据源 <code>dropship/backend/warehouse/remoteSearch</code>，随 provider-select 切换。</p>',
            ENT_NOQUOTES,
        );
    }
}

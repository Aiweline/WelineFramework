<?php

declare(strict_types=1);

/**
 * Weline Websites - 域名选择器标签
 * 
 * 提供可搜索的域名池选择器组件
 * 支持主题变量，兼容暗色/亮色模式
 */

namespace Weline\Websites\Taglib;

use Weline\Taglib\TaglibInterface;
use Weline\Framework\Http\Url;

/**
 * 域名选择标签
 *
 * 使用示例：
 * <w:websites:domain:select
 *     id="domain_select"
 *     name="domain"
 *     value="selectedDomain|''"
 *     display="displayText|'请选择域名'"
 *     class="w-100"
 *     on-select="handleDomainSelect"
 * />
 */
class DomainSelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'websites:domain:select';
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
            'name' => true,
            'value' => true,
            'display' => true,
            'class' => false,
            'style' => false,
            'limit' => false,
            'url' => false,
            'multiple' => false,  // 是否支持多选，默认 false（单选）
            'on-select' => false,  // 选择后的回调函数名
            'auto-fill-code' => false,  // 自动填充代码的目标输入框 ID
            'auto-fill-url' => false,   // 自动填充 URL 的目标输入框 ID
            'auto-fill-name' => false,  // 自动填充名称的目标输入框 ID
            'auto-fill-address' => false,  // 自动填充地址的目标 textarea ID（多选时填充多行）
            'site-ready-only' => false,    // v1.6.0: 是否只显示 site_ready=1 的域名（默认 true）
            'value-type' => false,         // v1.6.0: 值类型 "domain"（默认）或 "pool_id"
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            if (empty($attributes['id'])) {
                throw new \Exception(__('id属性不能为空'));
            }
            
            $placeholder = $attributes['placeholder'] ?? __('搜索域名...');
            $class = $attributes['class'] ?? '';
            $style = $attributes['style'] ?? '';
            $limit = (int)($attributes['limit'] ?? 50);
            $urlPath = $attributes['url'] ?? 'websites/backend/api/domain-pool';
            $onSelect = $attributes['on-select'] ?? '';
            $autoFillCode = $attributes['auto-fill-code'] ?? '';
            $autoFillUrl = $attributes['auto-fill-url'] ?? '';
            $autoFillName = $attributes['auto-fill-name'] ?? '';
            $autoFillAddress = $attributes['auto-fill-address'] ?? '';
            
            // 多选模式：支持 "true"、"1"、true
            $multipleRaw = $attributes['multiple'] ?? 'false';
            $isMultiple = in_array(strtolower(trim((string)$multipleRaw)), ['true', '1', 'yes'], true);
            
            // v1.6.0: site-ready-only 默认为 true，只显示可建站的域名
            $siteReadyOnlyRaw = $attributes['site-ready-only'] ?? 'true';
            $siteReadyOnly = !in_array(strtolower(trim((string)$siteReadyOnlyRaw)), ['false', '0', 'no'], true);
            
            // v1.6.0: 值类型 "domain"（默认）或 "pool_id"
            $valueType = strtolower(trim($attributes['value-type'] ?? 'domain'));
            
            /** @var Url $url */
            $url = w_obj(Url::class);
            $epPath = $url->getBackendUrlPath($urlPath);

            $attributes['url'] = $epPath;
            $attributes['limit'] = $limit;
            
            // 解析所有属性
            $code = \Weline\Taglib\Taglib::attributes($attributes);
            $html = [];
            $html[] = '<?php ' . $code . ' ?>';
            
            $html[] = '<style>';
            $html[] = '.weline-domain-select {';
            $html[] = '  position: relative;';
            $html[] = '}';
            $html[] = '.weline-domain-select-trigger {';
            $html[] = '  display: flex;';
            $html[] = '  align-items: center;';
            $html[] = '  justify-content: space-between;';
            $html[] = '  width: 100%;';
            $html[] = '  min-height: 38px;';
            $html[] = '  padding: 0.375rem 0.75rem;';
            $html[] = '  background-color: var(--backend-color-card-bg, #fff);';
            $html[] = '  border: 1px solid var(--backend-color-border-default, #ced4da);';
            $html[] = '  border-radius: var(--backend-border-radius-sm, 0.25rem);';
            $html[] = '  color: var(--backend-color-text-primary, #212529);';
            $html[] = '  cursor: pointer;';
            $html[] = '  transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;';
            $html[] = '}';
            $html[] = '.weline-domain-select-trigger:hover {';
            $html[] = '  border-color: var(--backend-color-primary, #556ee6);';
            $html[] = '}';
            $html[] = '.weline-domain-select-trigger:focus {';
            $html[] = '  border-color: var(--backend-color-primary, #556ee6);';
            $html[] = '  box-shadow: 0 0 0 0.2rem rgba(85, 110, 230, 0.25);';
            $html[] = '  outline: none;';
            $html[] = '}';
            $html[] = '.weline-domain-select-tags {';
            $html[] = '  display: flex;';
            $html[] = '  flex-wrap: wrap;';
            $html[] = '  gap: 0.25rem;';
            $html[] = '  flex: 1;';
            $html[] = '  align-items: center;';
            $html[] = '}';
            $html[] = '.weline-domain-select-tag {';
            $html[] = '  display: inline-flex;';
            $html[] = '  align-items: center;';
            $html[] = '  padding: 0.125rem 0.5rem;';
            $html[] = '  font-size: 0.75rem;';
            $html[] = '  background-color: var(--backend-color-primary-bg-subtle, rgba(85, 110, 230, 0.1));';
            $html[] = '  color: var(--backend-color-primary, #556ee6);';
            $html[] = '  border-radius: var(--backend-border-radius-sm, 0.25rem);';
            $html[] = '  white-space: nowrap;';
            $html[] = '}';
            $html[] = '.weline-domain-select-tag-remove {';
            $html[] = '  margin-left: 0.25rem;';
            $html[] = '  cursor: pointer;';
            $html[] = '  opacity: 0.7;';
            $html[] = '  font-size: 0.875rem;';
            $html[] = '}';
            $html[] = '.weline-domain-select-tag-remove:hover {';
            $html[] = '  opacity: 1;';
            $html[] = '}';
            $html[] = '.weline-domain-select-dropdown {';
            $html[] = '  position: absolute;';
            $html[] = '  left: 0;';
            $html[] = '  right: 0;';
            $html[] = '  z-index: 1060;';
            $html[] = '  padding: 0.75rem;';
            $html[] = '  background-color: var(--backend-color-card-bg, #fff);';
            $html[] = '  border: 1px solid var(--backend-color-border-default, #dee2e6);';
            $html[] = '  border-radius: var(--backend-border-radius-md, 0.375rem);';
            $html[] = '  box-shadow: var(--backend-shadow-lg, 0 0.5rem 1rem rgba(0, 0, 0, 0.15));';
            $html[] = '}';
            $html[] = '.weline-domain-select-search {';
            $html[] = '  width: 100%;';
            $html[] = '  padding: 0.5rem 0.75rem;';
            $html[] = '  margin-bottom: 0.5rem;';
            $html[] = '  background-color: var(--backend-color-card-bg);';
            $html[] = '  border: 1px solid var(--backend-color-border-default);';
            $html[] = '  border-radius: var(--backend-border-radius-sm, 0.25rem);';
            $html[] = '  color: var(--backend-color-text-primary);';
            $html[] = '}';
            $html[] = '.weline-domain-select-search:focus {';
            $html[] = '  border-color: var(--backend-color-primary, #556ee6);';
            $html[] = '  outline: none;';
            $html[] = '}';
            $html[] = '.weline-domain-select-list {';
            $html[] = '  max-height: 300px;';
            $html[] = '  overflow-y: auto;';
            $html[] = '  border: 1px solid var(--backend-color-border-default, #dee2e6);';
            $html[] = '  border-radius: var(--backend-border-radius-sm, 0.25rem);';
            $html[] = '  background-color: var(--backend-color-card-bg, #fff);';
            $html[] = '}';
            $html[] = '.weline-domain-select-group-label {';
            $html[] = '  padding: 0.5rem 0.75rem;';
            $html[] = '  font-weight: 600;';
            $html[] = '  font-size: 0.75rem;';
            $html[] = '  text-transform: uppercase;';
            $html[] = '  color: var(--backend-color-text-secondary, #6c757d);';
            $html[] = '  background-color: var(--backend-color-bg-secondary, #f8f9fa);';
            $html[] = '  border-bottom: 1px solid var(--backend-color-border-default, #dee2e6);';
            $html[] = '}';
            $html[] = '.weline-domain-select-item {';
            $html[] = '  padding: 0.5rem 0.75rem;';
            $html[] = '  cursor: pointer;';
            $html[] = '  border-bottom: 1px solid var(--backend-color-border-light, #e9ecef);';
            $html[] = '  color: var(--backend-color-text-primary, #212529);';
            $html[] = '  transition: background-color 0.15s ease;';
            $html[] = '}';
            $html[] = '.weline-domain-select-item:last-child {';
            $html[] = '  border-bottom: none;';
            $html[] = '}';
            $html[] = '.weline-domain-select-item:hover {';
            $html[] = '  background-color: var(--backend-color-bg-tertiary);';
            $html[] = '}';
            $html[] = '.weline-domain-select-item.active {';
            $html[] = '  background-color: var(--backend-color-bg-secondary);';
            $html[] = '  color: var(--backend-color-primary);';
            $html[] = '}';
            $html[] = '.weline-domain-select-item.selected {';
            $html[] = '  background-color: var(--backend-color-primary-bg-subtle, rgba(85, 110, 230, 0.1));';
            $html[] = '}';
            $html[] = '.weline-domain-select-checkbox {';
            $html[] = '  margin-right: 0.5rem;';
            $html[] = '  accent-color: var(--backend-color-primary, #556ee6);';
            $html[] = '}';
            $html[] = '.weline-domain-select-actions {';
            $html[] = '  display: flex;';
            $html[] = '  gap: 0.5rem;';
            $html[] = '  padding: 0.5rem;';
            $html[] = '  border-top: 1px solid var(--backend-color-border-default, #dee2e6);';
            $html[] = '  margin-top: 0.5rem;';
            $html[] = '}';
            $html[] = '.weline-domain-select-btn {';
            $html[] = '  flex: 1;';
            $html[] = '  padding: 0.375rem 0.75rem;';
            $html[] = '  font-size: 0.875rem;';
            $html[] = '  border: none;';
            $html[] = '  border-radius: var(--backend-border-radius-sm, 0.25rem);';
            $html[] = '  cursor: pointer;';
            $html[] = '  transition: background-color 0.15s ease;';
            $html[] = '}';
            $html[] = '.weline-domain-select-btn-primary {';
            $html[] = '  background-color: var(--backend-color-primary, #556ee6);';
            $html[] = '  color: #fff;';
            $html[] = '}';
            $html[] = '.weline-domain-select-btn-primary:hover {';
            $html[] = '  background-color: var(--backend-color-primary-hover, #4857d4);';
            $html[] = '}';
            $html[] = '.weline-domain-select-btn-secondary {';
            $html[] = '  background-color: var(--backend-color-bg-secondary, #f8f9fa);';
            $html[] = '  color: var(--backend-color-text-primary, #212529);';
            $html[] = '}';
            $html[] = '.weline-domain-select-btn-secondary:hover {';
            $html[] = '  background-color: var(--backend-color-bg-tertiary, #e9ecef);';
            $html[] = '}';
            $html[] = '.weline-domain-select-item-desc {';
            $html[] = '  font-size: 0.75rem;';
            $html[] = '  color: var(--backend-color-text-muted, #adb5bd);';
            $html[] = '}';
            $html[] = '.weline-domain-select-loading,';
            $html[] = '.weline-domain-select-empty {';
            $html[] = '  padding: 1rem;';
            $html[] = '  text-align: center;';
            $html[] = '  color: var(--backend-color-text-secondary, #6c757d);';
            $html[] = '}';
            $html[] = '.weline-domain-select-hint {';
            $html[] = '  margin-top: 0.25rem;';
            $html[] = '  font-size: 0.75rem;';
            $html[] = '  color: var(--backend-color-text-muted, #adb5bd);';
            $html[] = '}';
            $html[] = '</style>';

            // HTML 结构
            $multipleAttr = $isMultiple ? 'true' : 'false';
            $html[] = '<div class="weline-domain-select ' . htmlspecialchars($class) . '" style="' . htmlspecialchars($style) . '" id="<?= htmlspecialchars($Taglib__id) ?>_wrapper" data-multiple="' . $multipleAttr . '">';
            $html[] = '  <button type="button" class="weline-domain-select-trigger" id="<?= htmlspecialchars($Taglib__id) ?>_trigger">';
            if ($isMultiple) {
                $html[] = '    <div class="weline-domain-select-tags" id="<?= htmlspecialchars($Taglib__id) ?>_tags">';
                $html[] = '      <span class="weline-domain-select-placeholder" id="<?= htmlspecialchars($Taglib__id) ?>_placeholder">';
                $html[] = '        <i class="mdi mdi-domain me-1"></i>';
                $html[] = '        <span><?php $_display = trim($Taglib__display, "\'\""); if($_display !== ""): echo htmlspecialchars($_display); else: ?>' . htmlspecialchars(__('点击选择域名（可多选）')) . '<?php endif; ?></span>';
                $html[] = '      </span>';
                $html[] = '    </div>';
            } else {
                $html[] = '    <span>';
                $html[] = '      <i class="mdi mdi-domain me-1"></i>';
                $html[] = '      <span id="<?= htmlspecialchars($Taglib__id) ?>_display"><?php $_display = trim($Taglib__display, "\'\""); if($_display !== ""): echo htmlspecialchars($_display); else: ?>' . htmlspecialchars(__('请选择域名')) . '<?php endif; ?></span>';
                $html[] = '    </span>';
            }
            $html[] = '    <i class="mdi mdi-chevron-down"></i>';
            $html[] = '  </button>';
            $html[] = '  <input type="hidden" id="<?= htmlspecialchars($Taglib__id) ?>_value" name="<?= htmlspecialchars($Taglib__name) ?>" value="<?= htmlspecialchars($Taglib__value) ?>">';
            $html[] = '  <div id="<?= htmlspecialchars($Taglib__id) ?>_dropdown" class="weline-domain-select-dropdown" style="display:none;">';
            $html[] = '    <input type="text" class="weline-domain-select-search" id="<?= htmlspecialchars($Taglib__id) ?>_search" placeholder="' . htmlspecialchars($placeholder) . '" autocomplete="off">';
            $html[] = '    <div class="weline-domain-select-list">';
            $html[] = '      <div id="<?= htmlspecialchars($Taglib__id) ?>_loading" class="weline-domain-select-loading" style="display:none;">' . __('加载中...') . '</div>';
            $html[] = '      <div id="<?= htmlspecialchars($Taglib__id) ?>_list"></div>';
            $html[] = '    </div>';
            if ($isMultiple) {
                $html[] = '    <div class="weline-domain-select-actions">';
                $html[] = '      <button type="button" class="weline-domain-select-btn weline-domain-select-btn-secondary" id="<?= htmlspecialchars($Taglib__id) ?>_clear">' . __('清空') . '</button>';
                $html[] = '      <button type="button" class="weline-domain-select-btn weline-domain-select-btn-primary" id="<?= htmlspecialchars($Taglib__id) ?>_confirm">' . __('确定') . '</button>';
                $html[] = '    </div>';
            }
            $html[] = '  </div>';
            if ($isMultiple) {
                $html[] = '  <small class="weline-domain-select-hint">' . __('点击选择多个域名，选中后点击确定') . '</small>';
            } else {
                $html[] = '  <small class="weline-domain-select-hint">' . __('点击选择域名，选择后将自动填充相关字段') . '</small>';
            }
            $html[] = '</div>';

            // JavaScript
            $t_no_match = addslashes(__('未找到匹配的域名'));
            $t_load_fail = addslashes(__('加载失败'));
            $t_no_domain = addslashes(__('暂无可用域名，请先添加'));
            $t_default = addslashes(__('请选择域名'));
            $t_selected = addslashes(__('已选择 %s 个域名'));
            
            $html[] = '<script>(function(){';
            $html[] = '"use strict";';
            $html[] = 'var ep = ' . json_encode($epPath) . ';';
            $html[] = 'var id = <?= json_encode($Taglib__id) ?>;';
            $html[] = 'var limit = ' . $limit . ';';
            $html[] = 'var isMultiple = ' . ($isMultiple ? 'true' : 'false') . ';';
            $html[] = 'var siteReadyOnly = ' . ($siteReadyOnly ? 'true' : 'false') . ';';
            $html[] = 'var valueType = ' . json_encode($valueType) . ';';
            $html[] = 'var autoFillCode = ' . json_encode($autoFillCode) . ';';
            $html[] = 'var autoFillUrl = ' . json_encode($autoFillUrl) . ';';
            $html[] = 'var autoFillName = ' . json_encode($autoFillName) . ';';
            $html[] = 'var autoFillAddress = ' . json_encode($autoFillAddress) . ';';
            $html[] = 'var onSelectFn = ' . json_encode($onSelect) . ';';
            $html[] = '';
            $html[] = 'var trigger = document.getElementById(id + "_trigger");';
            $html[] = 'var dropdown = document.getElementById(id + "_dropdown");';
            $html[] = 'var list = document.getElementById(id + "_list");';
            $html[] = 'var loading = document.getElementById(id + "_loading");';
            $html[] = 'var search = document.getElementById(id + "_search");';
            $html[] = 'var hidden = document.getElementById(id + "_value");';
            $html[] = 'var display = isMultiple ? null : document.getElementById(id + "_display");';
            $html[] = 'var tagsContainer = isMultiple ? document.getElementById(id + "_tags") : null;';
            $html[] = 'var placeholder = isMultiple ? document.getElementById(id + "_placeholder") : null;';
            $html[] = 'var confirmBtn = isMultiple ? document.getElementById(id + "_confirm") : null;';
            $html[] = 'var clearBtn = isMultiple ? document.getElementById(id + "_clear") : null;';
            $html[] = '';
            $html[] = 'var cache = null;';
            $html[] = 'var selectedDomains = [];';
            $html[] = '';
            
            // 域名处理函数
            $html[] = 'function domainToCode(domain) {';
            $html[] = '  return domain.toLowerCase().replace(/\\./g, "_");';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function isSubdomain(domain) {';
            $html[] = '  var parts = domain.split(".");';
            $html[] = '  if (parts.length <= 2) return false;';
            $html[] = '  if (parts.length === 3 && parts[0].toLowerCase() === "www") return false;';
            $html[] = '  return true;';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function domainToUrl(domain) {';
            $html[] = '  domain = domain.toLowerCase().trim();';
            $html[] = '  if (!isSubdomain(domain) && !domain.startsWith("www.")) {';
            $html[] = '    domain = "www." + domain;';
            $html[] = '  }';
            $html[] = '  var port = window.location.port;';
            $html[] = '  var url = "https://" + domain;';
            $html[] = '  if (port && port !== "443" && port !== "80") {';
            $html[] = '    url += ":" + port;';
            $html[] = '  }';
            $html[] = '  return url;';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function domainToName(domain) {';
            $html[] = '  var parts = domain.split(".");';
            $html[] = '  if (parts.length >= 2) {';
            $html[] = '    var baseName = parts.slice(-2, -1)[0];';
            $html[] = '    return baseName.charAt(0).toUpperCase() + baseName.slice(1);';
            $html[] = '  }';
            $html[] = '  return domain;';
            $html[] = '}';
            $html[] = '';
            
            // 更新显示（多选模式下更新标签）
            $html[] = 'function updateDisplay() {';
            $html[] = '  if (!isMultiple) return;';
            $html[] = '  tagsContainer.innerHTML = "";';
            $html[] = '  if (selectedDomains.length === 0) {';
            $html[] = '    tagsContainer.innerHTML = \'<span class="weline-domain-select-placeholder"><i class="mdi mdi-domain me-1"></i><span>' . addslashes(__('点击选择域名（可多选）')) . '</span></span>\';';
            $html[] = '    return;';
            $html[] = '  }';
            $html[] = '  selectedDomains.forEach(function(domain) {';
            $html[] = '    var tag = document.createElement("span");';
            $html[] = '    tag.className = "weline-domain-select-tag";';
            $html[] = '    tag.innerHTML = \'<i class="mdi mdi-web me-1"></i>\' + domain + \'<span class="weline-domain-select-tag-remove" data-domain="\' + domain + \'">&times;</span>\';';
            $html[] = '    tagsContainer.appendChild(tag);';
            $html[] = '  });';
            $html[] = '  bindTagRemove();';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function bindTagRemove() {';
            $html[] = '  tagsContainer.querySelectorAll(".weline-domain-select-tag-remove").forEach(function(el) {';
            $html[] = '    el.addEventListener("click", function(e) {';
            $html[] = '      e.stopPropagation();';
            $html[] = '      var domain = this.dataset.domain;';
            $html[] = '      var idx = selectedDomains.indexOf(domain);';
            $html[] = '      if (idx > -1) { selectedDomains.splice(idx, 1); }';
            $html[] = '      updateHiddenValue();';
            $html[] = '      updateDisplay();';
            $html[] = '      doAutoFill();';
            $html[] = '    });';
            $html[] = '  });';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function updateHiddenValue() {';
            $html[] = '  if (isMultiple) {';
            $html[] = '    if (valueType === "pool_id") {';
            $html[] = '      // 根据 selectedDomains 查找对应的 pool_id';
            $html[] = '      var poolIds = [];';
            $html[] = '      (cache || []).forEach(function(group) {';
            $html[] = '        (group.options || []).forEach(function(opt) {';
            $html[] = '          if (selectedDomains.indexOf(opt.domain) > -1 && opt.pool_id) {';
            $html[] = '            poolIds.push(String(opt.pool_id));';
            $html[] = '          }';
            $html[] = '        });';
            $html[] = '      });';
            $html[] = '      hidden.value = poolIds.join(",");';
            $html[] = '    } else {';
            $html[] = '      hidden.value = selectedDomains.join(",");';
            $html[] = '    }';
            $html[] = '  }';
            $html[] = '  try { hidden.dispatchEvent(new Event("change")); } catch(e) {}';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function doAutoFill() {';
            $html[] = '  var domains = isMultiple ? selectedDomains : [hidden.value];';
            $html[] = '  if (domains.length === 0) return;';
            $html[] = '  var firstDomain = domains[0];';
            $html[] = '  if (autoFillCode) {';
            $html[] = '    var codeEl = document.getElementById(autoFillCode);';
            $html[] = '    if (codeEl) codeEl.value = domainToCode(firstDomain);';
            $html[] = '  }';
            $html[] = '  if (autoFillUrl) {';
            $html[] = '    var urlEl = document.getElementById(autoFillUrl);';
            $html[] = '    if (urlEl) urlEl.value = domainToUrl(firstDomain);';
            $html[] = '  }';
            $html[] = '  if (autoFillName) {';
            $html[] = '    var nameEl = document.getElementById(autoFillName);';
            $html[] = '    if (nameEl) nameEl.value = domainToName(firstDomain);';
            $html[] = '  }';
            $html[] = '  if (autoFillAddress) {';
            $html[] = '    var addrEl = document.getElementById(autoFillAddress);';
            $html[] = '    if (addrEl) {';
            $html[] = '      var addressList = [];';
            $html[] = '      var seen = {};';
            $html[] = '      var port = window.location.port;';
            $html[] = '      var portSuffix = (port && port !== "443" && port !== "80") ? ":" + port : "";';
            $html[] = '      domains.forEach(function(domain) {';
            $html[] = '        var d = domain.replace(/^(https?:\\/\\/)?/i, "").replace(/^\\/+|\\/+$/g, "");';
            $html[] = '        var rootDomain = d.startsWith("www.") ? d.substring(4) : d;';
            $html[] = '        var wwwDomain = "www." + rootDomain;';
            $html[] = '        if (!seen[rootDomain]) {';
            $html[] = '          seen[rootDomain] = true;';
            $html[] = '          addressList.push("https://" + wwwDomain + portSuffix);';
            $html[] = '          addressList.push("https://" + rootDomain + portSuffix);';
            $html[] = '          addressList.push("http://" + wwwDomain + portSuffix);';
            $html[] = '          addressList.push("http://" + rootDomain + portSuffix);';
            $html[] = '        }';
            $html[] = '      });';
            $html[] = '      addrEl.value = addressList.join("\\n");';
            $html[] = '    }';
            $html[] = '  }';
            $html[] = '  if (onSelectFn && typeof window[onSelectFn] === "function") {';
            $html[] = '    window[onSelectFn](isMultiple ? domains : firstDomain, { multiple: isMultiple });';
            $html[] = '  }';
            $html[] = '}';
            $html[] = '';
            
            // 渲染函数
            $html[] = 'function render(groups) {';
            $html[] = '  if (!groups || !groups.length) {';
            $html[] = '    list.innerHTML = \'<div class="weline-domain-select-empty">' . $t_no_match . '</div>\';';
            $html[] = '    return;';
            $html[] = '  }';
            $html[] = '  var html = "";';
            $html[] = '  groups.forEach(function(group) {';
            $html[] = '    html += \'<div class="weline-domain-select-group-label">\' + (group.label || "") + \'</div>\';';
            $html[] = '    if (group.options && group.options.length) {';
            $html[] = '      group.options.forEach(function(opt) {';
            $html[] = '        var descParts = [];';
            $html[] = '        if (opt.https_status === "valid") { descParts.push(\'<span style="color:var(--backend-color-success)">HTTPS</span>\'); }';
            $html[] = '        if (opt.site_ready) { descParts.push(\'<span style="color:var(--backend-color-success)">' . addslashes(__('可建站')) . '</span>\'); }';
            $html[] = '        if (opt.https_expires_at) { descParts.push(\'' . addslashes(__('证书到期')) . ': \' + opt.https_expires_at); }';
            $html[] = '        var desc = descParts.length > 0 ? \'<div class="weline-domain-select-item-desc">\' + descParts.join(" | ") + \'</div>\' : "";';
            $html[] = '        var isSelected = selectedDomains.indexOf(opt.domain) > -1;';
            $html[] = '        var selectedClass = isSelected ? " selected" : "";';
            $html[] = '        var poolId = opt.pool_id || "";';
            $html[] = '        html += \'<div class="weline-domain-select-item\' + selectedClass + \'" data-domain="\' + opt.domain + \'" data-poolid="\' + poolId + \'">\';';
            $html[] = '        if (isMultiple) {';
            $html[] = '          html += \'<input type="checkbox" class="weline-domain-select-checkbox"\' + (isSelected ? " checked" : "") + \'>\';';
            $html[] = '        }';
            $html[] = '        html += \'<i class="mdi mdi-web me-1"></i>\' + opt.domain + desc;';
            $html[] = '        html += \'</div>\';';
            $html[] = '      });';
            $html[] = '    }';
            $html[] = '  });';
            $html[] = '  list.innerHTML = html;';
            $html[] = '  bindItems();';
            $html[] = '}';
            $html[] = '';
            
            // 绑定点击事件
            $html[] = 'function bindItems() {';
            $html[] = '  list.querySelectorAll(".weline-domain-select-item").forEach(function(el) {';
            $html[] = '    el.addEventListener("click", function() {';
            $html[] = '      var domain = this.dataset.domain;';
            $html[] = '      var poolId = this.dataset.poolid || "";';
            $html[] = '      var itemValue = (valueType === "pool_id" && poolId) ? poolId : domain;';
            $html[] = '      if (isMultiple) {';
            $html[] = '        var idx = selectedDomains.indexOf(domain);';
            $html[] = '        if (idx > -1) {';
            $html[] = '          selectedDomains.splice(idx, 1);';
            $html[] = '          this.classList.remove("selected");';
            $html[] = '          var cb = this.querySelector(".weline-domain-select-checkbox");';
            $html[] = '          if (cb) cb.checked = false;';
            $html[] = '        } else {';
            $html[] = '          selectedDomains.push(domain);';
            $html[] = '          this.classList.add("selected");';
            $html[] = '          var cb = this.querySelector(".weline-domain-select-checkbox");';
            $html[] = '          if (cb) cb.checked = true;';
            $html[] = '        }';
            $html[] = '      } else {';
            $html[] = '        hidden.value = itemValue;';
            $html[] = '        hidden.dataset.domain = domain;';
            $html[] = '        hidden.dataset.poolid = poolId;';
            $html[] = '        display.textContent = domain;';
            $html[] = '        dropdown.style.display = "none";';
            $html[] = '        list.querySelectorAll(".weline-domain-select-item").forEach(function(li) {';
            $html[] = '          li.classList.remove("active");';
            $html[] = '        });';
            $html[] = '        this.classList.add("active");';
            $html[] = '        doAutoFill();';
            $html[] = '        try { hidden.dispatchEvent(new Event("change")); } catch(e) {}';
            $html[] = '      }';
            $html[] = '    });';
            $html[] = '  });';
            $html[] = '}';
            $html[] = '';
            
            // 多选确认按钮
            $html[] = 'if (isMultiple && confirmBtn) {';
            $html[] = '  confirmBtn.addEventListener("click", function() {';
            $html[] = '    updateHiddenValue();';
            $html[] = '    updateDisplay();';
            $html[] = '    doAutoFill();';
            $html[] = '    dropdown.style.display = "none";';
            $html[] = '  });';
            $html[] = '}';
            $html[] = '';
            $html[] = 'if (isMultiple && clearBtn) {';
            $html[] = '  clearBtn.addEventListener("click", function() {';
            $html[] = '    selectedDomains = [];';
            $html[] = '    updateHiddenValue();';
            $html[] = '    updateDisplay();';
            $html[] = '    render(cache || []);';
            $html[] = '  });';
            $html[] = '}';
            $html[] = '';
            
            // 加载数据
            $html[] = 'function loadData() {';
            $html[] = '  loading.style.display = "block";';
            $html[] = '  list.innerHTML = "";';
            $html[] = '  var apiUrl = ep + "?limit=" + limit + "&grouped=true&site_ready=" + (siteReadyOnly ? "true" : "false");';
            $html[] = '  fetch(apiUrl)';
            $html[] = '    .then(function(r) { return r.json(); })';
            $html[] = '    .then(function(res) {';
            $html[] = '      loading.style.display = "none";';
            $html[] = '      cache = (res && res.success) ? (res.data || []) : [];';
            $html[] = '      if (cache.length === 0) {';
            $html[] = '        list.innerHTML = \'<div class="weline-domain-select-empty">' . $t_no_domain . '</div>\';';
            $html[] = '        return;';
            $html[] = '      }';
            $html[] = '      // 初始化选中状态：根据 hidden.value 中的 pool_id 列表查找对应的 domain';
            $html[] = '      if (isMultiple && valueType === "pool_id" && hidden.value) {';
            $html[] = '        var initPoolIds = hidden.value.split(",").map(function(v) { return String(v).trim(); }).filter(function(v) { return v !== ""; });';
            $html[] = '        cache.forEach(function(group) {';
            $html[] = '          (group.options || []).forEach(function(opt) {';
            $html[] = '            if (initPoolIds.indexOf(String(opt.pool_id)) > -1 && selectedDomains.indexOf(opt.domain) === -1) {';
            $html[] = '              selectedDomains.push(opt.domain);';
            $html[] = '            }';
            $html[] = '          });';
            $html[] = '        });';
            $html[] = '        updateHiddenValue();';
            $html[] = '        updateDisplay();';
            $html[] = '      }';
            $html[] = '      render(cache);';
            $html[] = '    })';
            $html[] = '    .catch(function() {';
            $html[] = '      loading.style.display = "none";';
            $html[] = '      list.innerHTML = \'<div class="weline-domain-select-empty" style="color:var(--backend-color-danger,#dc3545)">' . $t_load_fail . '</div>\';';
            $html[] = '    });';
            $html[] = '}';
            $html[] = '';
            
            // 搜索过滤
            $html[] = 'var filterTimeout = null;';
            $html[] = 'function doFilter(kw) {';
            $html[] = '  kw = (kw || "").toLowerCase().trim();';
            $html[] = '  if (!kw) {';
            $html[] = '    render(cache || []);';
            $html[] = '    return;';
            $html[] = '  }';
            $html[] = '  var filtered = [];';
            $html[] = '  (cache || []).forEach(function(group) {';
            $html[] = '    var opts = (group.options || []).filter(function(o) {';
            $html[] = '      return o.domain.toLowerCase().indexOf(kw) !== -1;';
            $html[] = '    });';
            $html[] = '    if (opts.length > 0) {';
            $html[] = '      filtered.push({ label: group.label, options: opts });';
            $html[] = '    }';
            $html[] = '  });';
            $html[] = '  render(filtered);';
            $html[] = '}';
            $html[] = '';
            
            // 事件绑定
            $html[] = 'trigger.addEventListener("click", function(e) {';
            $html[] = '  e.stopPropagation();';
            $html[] = '  dropdown.style.top = (trigger.offsetHeight + 4) + "px";';
            $html[] = '  dropdown.style.display = "block";';
            $html[] = '  loadData();';
            $html[] = '  setTimeout(function() { search.focus(); }, 50);';
            $html[] = '});';
            $html[] = '';
            $html[] = 'search.addEventListener("input", function() {';
            $html[] = '  clearTimeout(filterTimeout);';
            $html[] = '  var val = this.value;';
            $html[] = '  filterTimeout = setTimeout(function() { doFilter(val); }, 200);';
            $html[] = '});';
            $html[] = '';
            $html[] = 'function closeDropdown(ev) {';
            $html[] = '  if (!dropdown.contains(ev.target) && !trigger.contains(ev.target)) {';
            $html[] = '    dropdown.style.display = "none";';
            $html[] = '    document.removeEventListener("click", closeDropdown);';
            $html[] = '    document.removeEventListener("keydown", escClose);';
            $html[] = '  }';
            $html[] = '}';
            $html[] = 'function escClose(e) {';
            $html[] = '  if (e.key === "Escape") {';
            $html[] = '    dropdown.style.display = "none";';
            $html[] = '    document.removeEventListener("click", closeDropdown);';
            $html[] = '    document.removeEventListener("keydown", escClose);';
            $html[] = '  }';
            $html[] = '}';
            $html[] = 'trigger.addEventListener("click", function() {';
            $html[] = '  setTimeout(function() {';
            $html[] = '    document.addEventListener("click", closeDropdown);';
            $html[] = '    document.addEventListener("keydown", escClose);';
            $html[] = '  }, 0);';
            $html[] = '});';
            $html[] = '';
            $html[] = '})();</script>';

            return implode("\n", $html);
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
        $doc = <<<DOC
<h3><code>&lt;w:websites:domain:select&gt;</code> 使用文档</h3>
<p><strong>作用</strong>：渲染"可搜索 + 点选"的域名选择器，用于从域名池（DomainPool）选择域名。支持单选和多选模式。</p>
<p><strong>v1.6.0 更新</strong>：数据源改为 DomainPool 模型，支持 site_ready 筛选和 pool_id 值类型。</p>

<h4>属性</h4>
<ul>
  <li><code>id</code>：组件唯一 ID（必需）</li>
  <li><code>name</code>：隐藏域表单名（必需）</li>
  <li><code>value</code>：隐藏域值，域名或 pool_id（支持变量/默认）</li>
  <li><code>display</code>：按钮显示文本（支持变量/默认）</li>
  <li><code>class</code>/<code>style</code>：外层样式</li>
  <li><code>limit</code>：加载数量限制（默认50）</li>
  <li><code>url</code>：API地址（默认 websites/backend/api/domain-pool）</li>
  <li><code>multiple</code>：是否多选（默认 false，支持 "true"/"1"/"yes"）</li>
  <li><code>on-select</code>：选择后的回调函数名</li>
  <li><code>auto-fill-code</code>：自动填充网站代码的输入框 ID</li>
  <li><code>auto-fill-url</code>：自动填充网站 URL 的输入框 ID</li>
  <li><code>auto-fill-name</code>：自动填充网站名称的输入框 ID</li>
  <li><code>auto-fill-address</code>：自动填充地址的 textarea ID（多选时填充多行）</li>
  <li><code>site-ready-only</code>：<strong>[v1.6.0]</strong> 是否只显示 site_ready=1 的域名（默认 true）</li>
  <li><code>value-type</code>：<strong>[v1.6.0]</strong> 值类型 "domain"（默认）或 "pool_id"</li>
</ul>

<h4>使用示例</h4>
<pre>
&lt;!-- 单选模式（默认，只显示可建站域名） --&gt;
&lt;w:websites:domain:select
    id="domain_select"
    name="domain"
    value="''"
    display="'请选择域名'"
    class="w-100"
    auto-fill-code="code"
    auto-fill-url="url"
    auto-fill-name="name"
/&gt;

&lt;!-- 多选模式，使用 pool_id 作为值 --&gt;
&lt;w:websites:domain:select
    id="domain_select"
    name="pool_ids"
    value="''"
    display="'点击选择域名（可多选）'"
    class="w-100"
    multiple="true"
    value-type="pool_id"
    auto-fill-address="address_lines"
/&gt;

&lt;!-- 显示所有域名（包括未就绪的） --&gt;
&lt;w:websites:domain:select
    id="all_domain_select"
    name="domain"
    value="''"
    display="'选择域名'"
    site-ready-only="false"
/&gt;
</pre>
DOC;

        return htmlspecialchars($doc, ENT_NOQUOTES);
    }
}

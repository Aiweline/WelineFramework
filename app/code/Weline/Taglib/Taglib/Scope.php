<?php

namespace Weline\Taglib\Taglib;

use Weline\Framework\App\Exception;
use Weline\Taglib\TaglibInterface;

class Scope implements TaglibInterface
{
    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return 'scope';
    }

    /**
     * @inheritDoc
     */
    public static function tag(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function attr(): array
    {
        return ['container-id' => true, 'url' => false, 'event' => false];
    }

    /**
     * @inheritDoc
     */
    public static function tag_start(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function tag_end(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $url = $attributes['url']??null;
            $container_id = $attributes['container-id'] ?? '';
            if (empty($url)) {
                $url = w_url('/taglib/backend/scope');
            }
            if (empty($container_id)) {
                throw new Exception(__('Scope 标签属性 container-id 不能为空！'));
            }
            $event = $attributes['event']??'input change';
            # 自动保存模板
            return <<<JS
<script>
$(function (){
    // ScopeData本地存储工具
    function getScopeData() {
        return JSON.parse(localStorage.getItem('ScopeData') || '{}');
    }
    function setScopeData(data) {
        localStorage.setItem('ScopeData', JSON.stringify(data));
    }
    // 全局防抖定时器
    if (!window.ScopeDataDebounceTimer) window.ScopeDataDebounceTimer = null;

    function triggerGlobalDebounceSave(url) {
        if (window.ScopeDataDebounceTimer) clearTimeout(window.ScopeDataDebounceTimer);
        window.ScopeDataDebounceTimer = setTimeout(function() {
            let allData = getScopeData();
            for (let scope in allData) {
                if (allData[scope].hasChange) {
                    autoSaveScope(scope, url);
                }
            }
        }, 5000);
    }

    function autoSaveScope(scope, url) {
        var allData = getScopeData();
        if (!allData[scope] || !allData[scope].hasChange) return;
        var dataObj = allData[scope].data || {};
        var keys = Object.keys(dataObj);
        if (keys.length === 0) return;
        // 一次性批量保存
        $.ajax({
            url: url,
            type: 'post',
            contentType: 'application/json',
            data: JSON.stringify({
                scope: scope,
                data: dataObj
            }),
            success: function(){
                var allData = getScopeData();
                if (allData[scope]) {
                    allData[scope].hasChange = false;
                    setScopeData(allData);
                }
            }
        });
    }

    function initScope(scope_eles){
        if(typeof debounce != 'function'){
            function debounce(func, delay) {
                let timeoutId;
                return function (...args) {
                    if (timeoutId) {
                        clearTimeout(timeoutId);
                    }
                    timeoutId = setTimeout(() => {
                        func.apply(this, args);
                        timeoutId = null;
                    }, delay);
                }
            }
        }
        // 监听变化，存本地并全局防抖保存
        scope_eles.on('$event', debounce(function(e){
            let target = $(this)
            let events = target.attr('event') || '$event';
            events = events.split(' ')
            let has_event = false
            for(let event of events){
                event = event.trim(' ')
                if(event && e.type == event){
                    has_event = true
                }
            }
            if(!has_event){ return }
            let tag = this.tagName.toLowerCase()
            let value = '';
            if(tag === 'input' || tag === 'textarea' || tag === 'select'){
                value = target.val()
            }else{
                value = target.attr('value')
            }
            let scope = target.attr('scope')
            let name = target.attr('name')
            switch (target.attr('type')){
                case 'checkbox':
                    value = target.prop('checked')?target.attr('value'):'';
                    break;
            }
            // 本地存储
            var allData = getScopeData();
            if (!allData[scope]) allData[scope] = { data: {}, hasChange: false };
            allData[scope].data[name] = value;
            allData[scope].hasChange = true;
            setScopeData(allData);
            // 全局防抖自动保存
            triggerGlobalDebounceSave('{$url}');
        }, 100));
        // 检测scope,每个scope只加载一次
        let scopes = Array.from(new Set(scope_eles.map(function(index, scopeEle){
            return scopeEle.getAttribute('scope')
        })));
        for(let scope of scopes){
            $.ajax({
                url: '{$url}',
                type: 'get',
                async: false,
                data: { scope: scope }
            }).done(function(res){
                if(res['json'] !== undefined){
                    let scopeData = res.json;
                    for (scope_ele of scope_eles){
                        let eleScope = scope_ele.getAttribute('scope');
                        let eleKey = scope_ele.getAttribute('name');
                        if(scope === eleScope && scopeData.hasOwnProperty(eleKey)){
                            let target = $('*[scope="' + scope + '"][name="' + eleKey + '"]');
                            let value = scopeData[eleKey];
                            target.each(function(){
                                let tag = this.tagName.toLowerCase();
                                let type = $(this).attr('type');
                                // 只在没有值时写入
                                if(tag === 'input'){
                                    if(type === 'checkbox'){
                                        if(!$(this).prop('checked') && value){
                                            let checked = (value!=='' && value!==0 && value!=='false' && value!=='0' && value!==false)?true:false;
                                            $(this).prop('checked', checked);
                                        }
                                    }else if(type === 'radio'){
                                        if(!$(this).prop('checked') && value == $(this).val()){
                                            $(this).prop('checked', true);
                                        }
                                    }else{
                                        if(!$(this).val()){
                                            $(this).val(value);
                                        }
                                    }
                                }else if(tag === 'textarea'){
                                    if(!$(this).val()){
                                        $(this).val(value);
                                    }
                                }else if(tag === 'select'){
                                    // select回填优化：如果没有选中项或选中项无效，则强制设置
                                    let hasOption = $(this).find('option[value=\'' + value + '\']').length > 0;
                                    if(!hasOption && value !== undefined && value !== null && value !== '') {
                                        // 先移除同值option，避免重复
                                        $(this).find('option[value=\'' + value + '\']').remove();
                                        $(this).append('<option value=\'' + value + '\'>' + value + '</option>');
                                    }
                                    // 只在没有选中项或选中项无效时设置
                                    if(!$(this).val() || $(this).val() != value) {
                                        $(this).val(value);
                                        $(this).trigger('change');
                                    }
                                }
                            });
                        }
                    }
                }
            })
        }
    }
    var scope_eles= $('*[scope]')
    initScope(scope_eles)
    // 观察器
    var observer = new MutationObserver((mutationsList, observer) => {
        for(let mutation of mutationsList) {
            if (mutation.type === 'childList') {
                if(mutation.addedNodes.length > 0){
                    for ( let addedNode of mutation.addedNodes){
                        if(addedNode.nodeName === 'DIV'){
                            let insertedScopes = $(addedNode).find('*[scope]')
                            initScope(insertedScopes)
                        }
                    }
                }
            }
        }
    });
    let container = document.getElementById('$container_id')
    if(!container){
        observer.observe(document.body, { childList: true, subtree: true });
    }else{
        observer.observe(container, { childList: true, subtree: true });
    }
})
</script>
JS;
        };
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    /**
     * 指定父标签，用于依赖管理
     * @return string|null 父标签名称
     */
    public static function parent(): ?string
    {
        return null; // Scope标签没有依赖
    }

    public static function document(): string
    {
        return <<<DOC
输入框使用方式：
<input type="text" value="Demo Product" name="name" scope="product" event="change click"/>
页面底部引用：
<w:scope url="@backend-url('backend/user-data')" container-id="product-container" event="change click"/>
url ：可选， 传入url地址，存储数据的地址
container-id ： 传入容器id，用于自动存储数据时监听的容器范围
event ： 可选，监听事件,当带有scope="*"的元素触发了此处指定的事件便开始自动保存
DOC;

    }
}

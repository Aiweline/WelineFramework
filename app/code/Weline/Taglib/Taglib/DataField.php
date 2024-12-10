<?php

namespace Weline\Taglib\Taglib;

use Weline\FileManager\Cache\FileManagerCacheFactory;
use Weline\FileManager\FileManagerInterface;
use Weline\FileManager\Model\BackendUserConfig;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;
use Weline\Taglib\TaglibInterface;

class DataField implements TaglibInterface
{
    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return 'data-field';
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
        return ['container-id' => true, 'url' => true, 'event' => false];
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
            $url = $attributes['url'];
            $container_id = $attributes['container-id'] ?? '';
            if (empty($url)) {
                throw new Exception(__('DataField 标签属性 url 不能为空！'));
            }
            if (empty($container_id)) {
                throw new Exception(__('DataField 标签属性 container-id 不能为空！'));
            }
            $event = $attributes['event']??'input change';
            # 自动保存模板
            return "
<script>
$(function (){
    function initScope(scope_eles){
        if(typeof debounce != 'function'){
            /*延迟期间最多执行一次：优化输入请求过多ajax*/
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
                };
            }
        }
        // jquery检测所有带了scope属性的元素
        scope_eles.on('$event', debounce(function(e){
                let target = $(this)
                let events = target.attr('event')
                if(!events){
                    events = '$event'
                }
               
                events = events.split(' ')
                let has_event = false
                for(let event of events){
                    event = event.trim(' ')
                    if(event && e.type == event){
                        has_event = true
                    }
                }
                if(!has_event){
                    return
                }
                // 检查输入框类型
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
                let url = '{$url}'
                    $.ajax({
                    url: url,
                    type: 'post',
                    data: {
                        scope: scope,
                        value: value,
                        name: name,
                    },
                    success: function(data){
                        console.log(data)                        
                    },
                    error: function(data){
                        console.log(data)                
                    }
                    }).done(function (res){
                    })
            }, 500))
        // 检测scope,每个scope只加载一次
        let scopes = Array.from(new Set(scope_eles.map(function(index, scopeEle){
            return scopeEle.getAttribute('scope')            
        })))
        let scopeDataItems = {};
        for(let scope of scopes){
            $.ajax({
                    url: '{$url}',
                    type: 'get',
                    async: false,
                    data: {
                        scope: scope,            
                    }
                    }).done(function(res){
                    if(res['data'] !== undefined){
                        let scopeData = Object.entries(res.data)
                        for (scope_ele of scope_eles){
                            for(let key of scopeData){
                                let eleScope = scope_ele.getAttribute('scope')
                                let eleKey = scope_ele.getAttribute('name')
                                if(scope === eleScope && key[0] === eleKey){
                                    let target = $('*[scope=\"'+scope+'\"][name=\"'+key[0]+'\"]')
                                    switch (target.attr('type')) {
                                        case 'checkbox':
                                            // 检测是否被选中
                                            if(key[1]){
                                                let checked = (key[1]!=='' && key[1]!==0 && key[1]!=='false' && key[1]!=='0' && key[1]!==false)?true:false
                                                target.prop('checked', checked)
                                            }
                                            break;
                                        case 'textarea':
                                            if(key[1] && target.textContent ===''){
                                                target.textContent = key[1]
                                            }
                                            break;
                                        case 'radio':
                                            if(key[1] && target.checked ===''){
                                                target.prop('checked', true)
                                            }
                                            break;
                                        default:
                                            if(target.val()===''){
                                                target.val(key[1])
                                            }
                                            break;                                        
                                    }
                                    target.trigger('change')
                                }
                            }
                        }
                    }
                })
        }
    }
    var scope_eles= $('*[scope]')
    initScope(scope_eles)
    // 创建一个观察器实例
    var observer = new MutationObserver((mutationsList, observer) => {
        for(let mutation of mutationsList) {
            if (mutation.type === 'childList') {
                // 元素被新增时，如果是div，查找是否有scope属性，有就初始化
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
    // 开始观察指定的节点，需要在回调函数外部做这个调用
    let container = document.getElementById('$container_id')
    if(!container){
        observer.observe(document.body, { childList: true, subtree: true });
    }else{
        observer.observe(container, { childList: true, subtree: true });
    }
})
</script>";
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

    public static function document(): string
    {
        return <<<DOC
输入框使用方式：
<input type="text" value="Demo Product" name="name" scope="product" event="change click"/>
页面底部引用：
<w:data-field url="@backend-url('backend/user-data')" container-id="product-container" event="change click"/>
url ： 传入url地址，存储数据的地址
container-id ： 传入容器id，用于自动存储数据时监听的容器范围
event ： 监听事件,当带有scope="*"的元素触发了此处指定的事件便开始自动保存
DOC;

    }
}

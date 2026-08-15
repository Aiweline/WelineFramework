<?php

declare(strict_types=1);

namespace Weline\Inquiry\Service;

use Weline\Inquiry\Api\InquiryRendererInterface;
use Weline\SystemConfig\Api\ConfigReader;

final class InquiryRenderer implements InquiryRendererInterface
{
    public function __construct(private readonly ConfigReader $config) {}

    public function render(string $code, array $options = []): string
    {
        $code = trim($code);
        if ($code === '') {
            return '<!-- inquiry: missing code -->';
        }

        $id = $this->id((string)($options['id'] ?? ''), $code);
        $mode = in_array(($mode = strtolower((string)($options['mode'] ?? 'inline'))), ['inline', 'modal', 'trigger'], true) ? $mode : 'inline';
        $e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $selector = trim((string)($options['trigger_selector'] ?? ''));
        $html = '<section class="weline-inquiry weline-inquiry--' . $e($mode) . '" id="' . $e($id) . '" data-inquiry-code="' . $e($code) . '" data-inquiry-mode="' . $e($mode) . '">';

        if ($mode !== 'inline' && $selector === '') {
            $html .= '<button type="button" class="weline-inquiry__trigger" data-inquiry-open="' . $e($id) . '">' . $e(__('获取报价')) . '</button>';
        }

        $html .= '<div class="weline-inquiry__modal"' . ($mode === 'inline' ? '' : ' hidden') . ' data-inquiry-modal><div class="weline-inquiry__backdrop" data-inquiry-close></div><div class="weline-inquiry__dialog" role="dialog" aria-modal="' . ($mode === 'inline' ? 'false' : 'true') . '"><button type="button" class="weline-inquiry__close" data-inquiry-close aria-label="' . $e(__('关闭')) . '">×</button><div class="weline-inquiry__body" data-inquiry-body aria-live="polite"></div></div></div>';
        $html .= $this->baseCss() . $this->css((string)($options['custom_css'] ?? '')) . $this->script($id, $code, $selector, (string)($options['custom_js'] ?? ''), $mode) . '</section>';

        return $html;
    }

    private function id(string $input, string $code): string
    {
        $input = preg_replace('/[^A-Za-z0-9_-]/', '-', $input) ?: '';
        return $input !== '' ? substr($input, 0, 96) : 'inquiry-' . substr(hash('sha256', $code . uniqid('', true)), 0, 12);
    }

    private function baseCss(): string
    {
        return '<style data-inquiry-base-style>.weline-inquiry__modal[hidden]{display:none!important}.weline-inquiry__modal{position:fixed;z-index:1000;inset:0;display:grid;place-items:center;padding:1rem}.weline-inquiry--inline .weline-inquiry__modal{position:relative;z-index:auto;padding:0;display:block}.weline-inquiry--inline .weline-inquiry__backdrop{display:none}.weline-inquiry--inline .weline-inquiry__dialog{width:auto;max-height:none;box-shadow:none}.weline-inquiry__backdrop{position:absolute;inset:0;background:rgba(0,0,0,.72)}.weline-inquiry__dialog{position:relative;z-index:1;width:min(100%,42rem);max-height:calc(100vh - 2rem);overflow:auto;padding:1.5rem;background:#fff;color:#171717;box-shadow:0 1rem 3rem rgba(0,0,0,.35)}.weline-inquiry__close{position:absolute;top:.75rem;right:.75rem}.weline-inquiry__field{display:grid;gap:.4rem;margin:0 0 1rem}.weline-inquiry__field input,.weline-inquiry__field textarea,.weline-inquiry__field select{width:100%;box-sizing:border-box;padding:.65rem;border:1px solid #a5a5a5}.weline-inquiry__honeypot{position:absolute;left:-9999px}.weline-inquiry__message{min-height:1.25em}</style>';
    }

    private function css(string $css): string
    {
        $css = str_replace('</style', '', $css);
        return trim($css) === '' ? '' : '<style data-inquiry-style>' . $css . '</style>';
    }

    private function script(string $id, string $code, string $selector, string $customJs, string $mode): string
    {
        $config = json_encode([
            'id' => $id,
            'code' => $code,
            'mode' => $mode,
            'selector' => preg_match('/^[#.][A-Za-z][A-Za-z0-9_:-]{0,127}$/', $selector) ? $selector : '',
            'allowJs' => $this->trustedJsAllowed(),
            'customJs' => $customJs,
            'loading' => (string)__('正在加载表单…'),
            'failed' => (string)__('表单加载失败，请稍后重试。'),
            'submitted' => (string)__('提交成功'),
            'submitFailed' => (string)__('提交失败，请稍后重试。'),
            'close' => (string)__('关闭'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}';

        return '<script>(function(c){var root=document.getElementById(c.id);if(!root)return;var modal=root.querySelector("[data-inquiry-modal]"),body=root.querySelector("[data-inquiry-body]"),loaded=null,pending=null,customRan=false;var q=function(s,p){return (p||document).querySelector(s)},el=function(t,a,x){var n=document.createElement(t);Object.keys(a||{}).forEach(function(k){if(k==="class")n.className=a[k];else if(k==="text")n.textContent=a[k];else n.setAttribute(k,a[k])});if(x!==undefined)n.textContent=x;return n},text=function(v){return v==null?"":String(v)},locale=function(){var v=(navigator.language||"").replace("-","_");return v==="zh_CN"?"zh_Hans_CN":(v==="zh_TW"?"zh_Hant_TW":v)},fieldId=function(k){return c.id+"-"+String(k).replace(/[^A-Za-z0-9_-]/g,"-")},safeType=function(t){return ["text","email","tel","number","date","url"].indexOf(t)>=0?t:"text"},showMessage=function(v){body.replaceChildren(el("p",{class:"weline-inquiry__message"},v))};function addField(form,field,copy){var key=text(field.key),type=text(field.type),required=!!field.required;if(!key)return;var wrap=el("div",{class:"weline-inquiry__field"}),id=fieldId(key),label=el("label",{for:id},text(copy.label||key)+(required?" *":""));wrap.appendChild(label);if(copy.help)wrap.appendChild(el("small",{},text(copy.help)));var name="values["+key+"]",input;if(type==="textarea"){input=el("textarea",{id:id,name:name});input.placeholder=text(copy.placeholder)}else if(type==="select"){input=el("select",{id:id,name:name});input.appendChild(el("option",{value:""},text(copy.empty_option||"")));(field.options||[]).forEach(function(o){var value=text(o.value),option=el("option",{value:value},text((copy.options||{})[value]||value));input.appendChild(option)})}else if(type==="radio"){input=el("div",{class:"weline-inquiry__choices"});(field.options||[]).forEach(function(o,i){var value=text(o.value),choice=el("label",{}),radio=el("input",{type:"radio",name:name,value:value,id:id+"-"+i});if(required)radio.required=true;choice.appendChild(radio);choice.appendChild(document.createTextNode(" "+text((copy.options||{})[value]||value)));input.appendChild(choice)})}else if(type==="checkbox"){input=el("input",{id:id,type:"checkbox",name:"values["+key+"][]",value:"1"})}else if(type==="file"){input=el("input",{id:id,type:"file",name:name,"data-inquiry-file":"1"})}else{input=el("input",{id:id,type:safeType(type),name:name});input.placeholder=text(copy.placeholder)}if(required&&type!=="radio")input.required=true;if(field.pattern&&typeof input.pattern!=="undefined")input.pattern=text(field.pattern);wrap.appendChild(input);form.appendChild(wrap)}function render(data){var copy=data.copy||{},schema=data.schema||{},form=el("form",{class:"weline-inquiry__form",novalidate:"novalidate"});body.replaceChildren();body.appendChild(el("h2",{},text(copy.title||(data.form||{}).name||"")));if(copy.description)body.appendChild(el("p",{},text(copy.description)));form.appendChild(el("input",{type:"text",name:"company_website",tabindex:"-1",autocomplete:"off",class:"weline-inquiry__honeypot","aria-hidden":"true"}));(schema.fields||[]).forEach(function(f){if(f&&typeof f==="object")addField(form,f,(copy.fields||{})[f.key]||{})});var message=el("p",{class:"weline-inquiry__message","aria-live":"polite"}),submit=el("button",{type:"submit"},text(copy.submit_label||"Submit"));form.appendChild(message);form.appendChild(submit);form.addEventListener("submit",function(e){e.preventDefault();submit.disabled=true;var values={},dataForm=new FormData(form);dataForm.forEach(function(v,k){var m=/^values\\[([^\\]]+)\\](\\[\\])?$/.exec(k);if(!m)return;if(m[2]){(values[m[1]]||(values[m[1]]=[])).push(v)}else{values[m[1]]=v}});Promise.resolve().then(function(){return Weline.load("api")}).then(function(api){var key=(window.crypto&&crypto.randomUUID)?crypto.randomUUID().replace(/-/g,""):String(Date.now())+Math.random().toString(16).slice(2);return api.resource("inquiry").submit({code:c.code,values:values,idempotency_key:key})}).then(function(result){message.textContent=result.message||copy.success_message||c.submitted;form.reset()}).catch(function(){message.textContent=c.submitFailed}).finally(function(){submit.disabled=false})});if(c.allowJs&&c.customJs&&!customRan){var script=document.createElement("script");script.text=c.customJs;root.appendChild(script);customRan=true}}function ensure(){if(loaded)return Promise.resolve(loaded);if(pending)return pending;showMessage(c.loading);pending=Promise.resolve().then(function(){return Weline.load("api")}).then(function(api){return api.resource("inquiry").schema({code:c.code,locale:locale()})}).then(function(data){loaded=data;render(data);return data}).catch(function(error){showMessage(c.failed);throw error}).finally(function(){pending=null});return pending}function open(){if(modal)modal.hidden=false;ensure().catch(function(){})}function close(){if(modal&&c.mode!=="inline")modal.hidden=true}root.querySelectorAll("[data-inquiry-open]").forEach(function(button){button.addEventListener("click",open)});root.querySelectorAll("[data-inquiry-close]").forEach(function(button){button.addEventListener("click",close)});if(c.selector){document.querySelectorAll(c.selector).forEach(function(button){button.addEventListener("click",function(e){e.preventDefault();open()})})}if(c.mode==="inline")ensure().catch(function(){})})(' . $config . ');</script>';
    }

    private function trustedJsAllowed(): bool
    {
        return (bool)$this->config->getConfig('allow_trusted_widget_js', 'Weline_Inquiry', ConfigReader::area_BACKEND, false);
    }
}

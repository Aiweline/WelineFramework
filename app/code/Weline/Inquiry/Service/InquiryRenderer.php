<?php

declare(strict_types=1);

namespace Weline\Inquiry\Service;

use Weline\Captcha\Service\LazyCaptchaClientRuntime;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Registry\Service\RegistryModulePresence;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Template;
use Weline\Inquiry\Api\InquiryRendererInterface;
use Weline\SystemConfig\Api\ConfigReader;

final class InquiryRenderer implements InquiryRendererInterface
{
    private const ADDRESS_SCRIPT_SOURCE = 'Weline_Theme::js/address.js';
    private const ADDRESS_LOADER_SOURCE = 'Weline_Theme::js/address-loader.js';
    private const ADDRESS_ASSET_BUST = '20260907-district-single2';

    public function __construct(private readonly ConfigReader $config) {}

    public function render(string $code, array $options = []): string
    {
        $code = trim($code);
        if ($code === '') {
            return '<!-- inquiry: missing code -->';
        }

        $id = $this->id((string)($options['id'] ?? ''), $code);
        $mode = in_array(($mode = strtolower((string)($options['mode'] ?? 'inline'))), ['inline', 'modal', 'trigger'], true) ? $mode : 'inline';
        $skin = $this->skin((string)($options['skin'] ?? ''));
        $e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $selector = trim((string)($options['trigger_selector'] ?? ''));
        $classes = 'weline-inquiry weline-inquiry--' . $e($mode);
        if ($skin !== '') {
            $classes .= ' weline-inquiry--' . $e($skin);
        }
        $html = '<section class="' . $classes . '" id="' . $e($id) . '" data-inquiry-code="' . $e($code) . '" data-inquiry-mode="' . $e($mode) . '"' . ($skin !== '' ? ' data-inquiry-skin="' . $e($skin) . '"' : '') . '>';

        if ($mode !== 'inline' && $selector === '') {
            $html .= '<button type="button" class="weline-inquiry__trigger" data-inquiry-open="' . $e($id) . '">' . $e(__('获取报价')) . '</button>';
        }

        $html .= '<div class="weline-inquiry__modal"' . ($mode === 'inline' ? '' : ' hidden') . ' data-inquiry-modal><div class="weline-inquiry__backdrop" data-inquiry-close></div><div class="weline-inquiry__dialog" role="dialog" aria-modal="' . ($mode === 'inline' ? 'false' : 'true') . '"><button type="button" class="weline-inquiry__close" data-inquiry-close aria-label="' . $e(__('关闭')) . '">×</button><div class="weline-inquiry__body" data-inquiry-body aria-live="polite"></div></div></div>';
        $html .= $this->baseCss() . $this->css((string)($options['custom_css'] ?? '')) . $this->script($id, $code, $selector, (string)($options['custom_js'] ?? ''), $mode, $skin) . '</section>';

        return $html;
    }

    private function skin(string $input): string
    {
        $input = strtolower(trim($input));
        return in_array($input, ['amazon'], true) ? $input : '';
    }

    private function id(string $input, string $code): string
    {
        $input = preg_replace('/[^A-Za-z0-9_-]/', '-', $input) ?: '';
        return $input !== '' ? substr($input, 0, 96) : 'inquiry-' . substr(hash('sha256', $code . uniqid('', true)), 0, 12);
    }

    private function baseCss(): string
    {
        return '<style data-inquiry-base-style>.weline-inquiry__modal[hidden]{display:none!important}.weline-inquiry__modal{position:fixed;z-index:1000;inset:0;display:grid;place-items:center;padding:1rem}.weline-inquiry--inline .weline-inquiry__modal{position:relative;z-index:auto;padding:0;display:block}.weline-inquiry--inline .weline-inquiry__backdrop{display:none}.weline-inquiry--inline .weline-inquiry__dialog{width:auto;max-height:none;box-shadow:none}.weline-inquiry__backdrop{position:absolute;inset:0;background:rgba(0,0,0,.72)}.weline-inquiry__dialog{position:relative;z-index:1;width:min(100%,42rem);max-height:calc(100vh - 2rem);overflow:auto;padding:1.5rem;background:#fff;color:#171717;box-shadow:0 1rem 3rem rgba(0,0,0,.35)}.weline-inquiry__close{position:absolute;top:.75rem;right:.75rem}.weline-inquiry__field{display:grid;gap:.4rem;margin:0 0 1rem}.weline-inquiry__field input,.weline-inquiry__field textarea,.weline-inquiry__field select{width:100%;box-sizing:border-box;padding:.65rem;border:1px solid #a5a5a5}.weline-inquiry__field--country{gap:.3rem}.weline-inquiry__field--country .w-address{margin:0}.weline-inquiry__honeypot{position:absolute;left:-9999px}.weline-inquiry__captcha{margin:.25rem 0 1rem}.weline-inquiry__message{min-height:1.25em}</style>';
    }

    private function css(string $css): string
    {
        $css = str_replace('</style', '', $css);
        return trim($css) === '' ? '' : '<style data-inquiry-style>' . $css . '</style>';
    }

    private function script(string $id, string $code, string $selector, string $customJs, string $mode, string $skin = ''): string
    {
        $config = json_encode([
            'id' => $id,
            'code' => $code,
            'mode' => $mode,
            'skin' => $skin,
            'selector' => preg_match('/^[#.][A-Za-z][A-Za-z0-9_:-]{0,127}$/', $selector) ? $selector : '',
            'allowJs' => $this->trustedJsAllowed(),
            'customJs' => $customJs,
            'addressLoader' => $this->addressAssetUrl(self::ADDRESS_LOADER_SOURCE),
            'addressScript' => $this->addressAssetUrl(self::ADDRESS_SCRIPT_SOURCE),
            'addressSourceUrl' => (string)w_url('/shipping/frontend/region/list'),

            'captchaEnabled' => $this->captchaEnabled(),
            'captchaModule' => 'captchaLazy',
            'captchaModulePath' => $this->captchaEnabled() ? LazyCaptchaClientRuntime::resolveScriptUrl() : '',
            'captchaIntent' => InquirySubmissionCaptchaGuard::INTENT,
            'loading' => (string)__('正在加载表单…'),
            'failed' => (string)__('表单加载失败，请稍后重试。'),
            'submitted' => (string)__('提交成功'),
            'submitFailed' => (string)__('提交失败，请稍后重试。'),
            'captchaFailed' => (string)__('人机验证失败或已过期，请重试'),
            'captchaLoadFailed' => (string)__('人机验证加载失败，请稍后重试'),
            'countryRequired' => (string)__('请选择国家 / 地区'),
            'close' => (string)__('关闭'),
            // Prefer storefront URL/runtime locale over browser navigator language.
            'locale' => $this->requestLocale(),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}';

        $js = <<<'JS'
(function(c){
var root=document.getElementById(c.id);if(!root)return;
var modal=root.querySelector("[data-inquiry-modal]"),body=root.querySelector("[data-inquiry-body]"),loaded=null,pending=null,customRan=false,addressBooted=false;
var el=function(t,a,x){var n=document.createElement(t);Object.keys(a||{}).forEach(function(k){if(k==="class")n.className=a[k];else if(k==="text")n.textContent=a[k];else n.setAttribute(k,a[k])});if(x!==undefined)n.textContent=x;return n};
var text=function(v){return v==null?"":String(v)};
var locale=function(){
  var fromConfig=text(c.locale).trim();
  if(fromConfig)return fromConfig;
  var runtime=(window.Weline&&Weline.config&&(Weline.config.currentLang||(Weline.config.api&&Weline.config.api.locale)))||"";
  if(runtime)return String(runtime).replace("-","_");
  var htmlLang=(document.documentElement.getAttribute("data-lang")||document.documentElement.getAttribute("data-local")||"").replace("-","_");
  if(htmlLang)return htmlLang;
  var v=(navigator.language||"").replace("-","_");
  return v==="zh_CN"?"zh_Hans_CN":(v==="zh_TW"?"zh_Hant_TW":v);
};
var fieldId=function(k){return c.id+"-"+String(k).replace(/[^A-Za-z0-9_-]/g,"-")};
var safeType=function(t){return ["text","email","tel","number","date","url"].indexOf(t)>=0?t:"text"};
var showMessage=function(v){body.replaceChildren(el("p",{class:"weline-inquiry__message"},v))};
function ensureAddressLoader(onReady){
  var done=function(){
    if(typeof onReady==="function"){onReady();}
    addressBooted=true;
  };
  if(document.querySelector('script[data-inquiry-address-direct="1"]')){
    var tries=0;(function waitBoot(){
      if(window.WelineThemeAddress&&typeof window.WelineThemeAddress.boot==="function"){
        done();
        return;
      }
      if(++tries>80)return;
      setTimeout(waitBoot,25);
    })();
    return;
  }
  // Always inject a cache-busted address.js so sticky deploy assetVersion cannot keep an old module.
  // Never fall back to DEV-shaped /Weline/*/view/statics/ (PROD 404).
  if(!c.addressScript){return;}
  var s=document.createElement("script");
  s.src=c.addressScript;
  s.defer=true;
  s.setAttribute("data-inquiry-address-direct","1");
  s.setAttribute("data-no-extract","true");
  s.onload=function(){
    var tries=0;(function waitBoot(){
      if(window.WelineThemeAddress&&typeof window.WelineThemeAddress.boot==="function"){
        done();
        return;
      }
      if(++tries>80)return;
      setTimeout(waitBoot,25);
    })();
  };
  document.head.appendChild(s);
}
function addField(form,field,copy){
  var key=text(field.key),type=text(field.type),required=!!field.required;
  if(!key)return;
  // Companion region keys are owned by the country address widget.
  if((type==="text"||type==="hidden")&&(key==="province"||key==="city"||key==="district")&&form.querySelector("[data-inquiry-country-field]")){
    return;
  }
  var wrap=el("div",{class:"weline-inquiry__field"}),id=fieldId(key),name="values["+key+"]",input;
  if(type==="country"){
    wrap.className="weline-inquiry__field weline-inquiry__field--country";
    wrap.setAttribute("data-inquiry-country-field","1");
    if(required)wrap.setAttribute("data-inquiry-country-required","1");
    var addr=el("div",{class:"w-address"});
    var labelText=text(copy.label||key)+(required?" *":"");
    var countryCatalog=(field.validation&&field.validation.catalog)||"global";
    if(countryCatalog!=="global"){countryCatalog="installed";}
    var levels=((field.validation&&field.validation.levels)||"country|province|city|district").split("|").map(function(v){return String(v||"").trim()}).filter(Boolean);
    if(!levels.length){levels=["country","province","city","district"]}
    var levelNames={};
    levels.forEach(function(level){
      levelNames[level]=level==="country"?name:("values["+level+"]");
    });
    var levelLabels={
      country:labelText,
      province:text((copy.levels&&copy.levels.province)||"省份"),
      city:text((copy.levels&&copy.levels.city)||"城市"),
      district:text((copy.levels&&copy.levels.district)||"区县"),
      selectCountry:text(copy.placeholder||"")
    };
    addr.setAttribute("data-catalog",countryCatalog);
    addr.setAttribute("data-address-config",JSON.stringify({
      for:levels.join("|"),
      code:"inquiry-"+c.id+"-"+key,
      names:levelNames,
      labels:levelLabels,
      filters:{},
      sourceUrl:c.addressSourceUrl||"",
      searchable:true,
      cascade:true,
      catalog:countryCatalog,
      selection:"single"
    }));
    // Defer data-w-address until the cache-busted address.js is ready, so a sticky older
    // module (header/checkout) cannot mount this field with installed/province catalog first.
    wrap.appendChild(addr);
    form.appendChild(wrap);
    ensureAddressLoader(function(){
      addr.setAttribute("data-w-address","1");
      if(window.WelineThemeAddress&&typeof window.WelineThemeAddress.boot==="function"){
        window.WelineThemeAddress.boot();
      }
    });
    return;
  }
  var label=el("label",{for:id},text(copy.label||key)+(required?" *":""));
  wrap.appendChild(label);
  if(copy.help)wrap.appendChild(el("small",{},text(copy.help)));
  if(type==="textarea"){input=el("textarea",{id:id,name:name});input.placeholder=text(copy.placeholder)}
  else if(type==="select"){input=el("select",{id:id,name:name});input.appendChild(el("option",{value:""},text(copy.empty_option||"")));(field.options||[]).forEach(function(o){var value=text(o.value),option=el("option",{value:value},text((copy.options||{})[value]||value));input.appendChild(option)})}
  else if(type==="radio"){input=el("div",{class:"weline-inquiry__choices"});(field.options||[]).forEach(function(o,i){var value=text(o.value),choice=el("label",{}),radio=el("input",{type:"radio",name:name,value:value,id:id+"-"+i});if(required)radio.required=true;choice.appendChild(radio);choice.appendChild(document.createTextNode(" "+text((copy.options||{})[value]||value)));input.appendChild(choice)})}
  else if(type==="checkbox"){input=el("input",{id:id,type:"checkbox",name:"values["+key+"][]",value:"1"})}
  else if(type==="file"){input=el("input",{id:id,type:"file",name:name,"data-inquiry-file":"1"})}
  else{input=el("input",{id:id,type:safeType(type),name:name});input.placeholder=text(copy.placeholder)}
  if(required&&type!=="radio")input.required=true;
  if(field.pattern&&typeof input.pattern!=="undefined")input.pattern=text(field.pattern);
  wrap.appendChild(input);
  form.appendChild(wrap);
}
function addCaptchaHost(form){
  if(!c.captchaEnabled)return null;
  var host=el("div",{class:"weline-captcha-lazy-host weline-inquiry__captcha"});
  host.setAttribute("data-weline-captcha-lazy","1");
  host.setAttribute("data-challenge-route","weline_captcha/frontend/challenge");
  host.setAttribute("data-form-id",form.id||((c.id||"inquiry")+"-form"));
  host.setAttribute("data-intent",c.captchaIntent||"inquiry.submit");
  host.setAttribute("data-captcha-mode","lazy");
  form.appendChild(host);
  return host;
}
var captchaModulePromise=null;
function ensureCaptchaModule(){
  if(!c.captchaEnabled)return Promise.resolve(null);
  if(window.Weline&&window.Weline.Captcha&&window.Weline.Captcha.__runtime==="captcha-lazy"){
    return Promise.resolve(window.Weline.Captcha);
  }
  if(!captchaModulePromise){
    var moduleName=c.captchaModule||"captchaLazy";
    captchaModulePromise=Promise.resolve().then(function(){
      if(!window.Weline||typeof window.Weline.load!=="function"){throw new Error("captcha_module");}
      var modulePath=text(c.captchaModulePath||"");
      return window.Weline.load(moduleName,modulePath||null);
    }).then(function(){
      if(window.Weline&&window.Weline.Captcha&&typeof window.Weline.Captcha.boot==="function"){window.Weline.Captcha.boot(root);}
      return window.Weline&&window.Weline.Captcha;
    });
  }
  return captchaModulePromise;
}
function mountInquiryForm(form){
  if(!form)return;
  if(window.Weline&&window.Weline.Form&&typeof window.Weline.Form.mount==="function"){window.Weline.Form.mount(form);}
}
function ensureInquiryCaptcha(form){
  if(!c.captchaEnabled||!(form instanceof HTMLFormElement))return Promise.resolve();
  return ensureCaptchaModule().then(function(Captcha){
    if(!Captcha||typeof Captcha.ensure!=="function"){throw new Error("captcha_load");}
    var host=form.querySelector("[data-weline-captcha-lazy]");
    var existing=form.querySelector("[data-weline-captcha-provider], .weline-captcha");
    return Promise.resolve(Captcha.ensure(host||existing||form,false)).then(function(){mountInquiryForm(form);});
  });
}
function captchaPayload(dataForm){
  return {
    captcha_provider:text(dataForm.get("captcha_provider")),
    captcha_token:text(dataForm.get("captcha_token")),
    captcha_response:text(dataForm.get("captcha_response")),
    captcha_action:text(dataForm.get("captcha_action"))
  };
}
function render(data){
  var copy=data.copy||{},schema=data.schema||{},form=el("form",{class:"weline-inquiry__form",novalidate:"novalidate"});
  form.id=(c.id||"inquiry")+"-form";
  form.setAttribute("data-weline-form-intent",c.captchaIntent||"inquiry.submit");
  body.replaceChildren();
  body.appendChild(el("h2",{class:"weline-inquiry__title"},text(copy.title||(data.form||{}).name||"")));
  if(copy.description)body.appendChild(el("p",{class:"weline-inquiry__description"},text(copy.description)));
  form.appendChild(el("input",{type:"text",name:"company_website",tabindex:"-1",autocomplete:"off",class:"weline-inquiry__honeypot","aria-hidden":"true"}));
  (schema.fields||[]).forEach(function(f){if(f&&typeof f==="object")addField(form,f,(copy.fields||{})[f.key]||{})});
  var message=el("p",{class:"weline-inquiry__message","aria-live":"polite"}),submit=el("button",{type:"submit",class:"weline-inquiry__submit"},text(copy.submit_label||"Submit"));
  addCaptchaHost(form);
  form.appendChild(message);form.appendChild(submit);body.appendChild(form);
  if(window.WelineThemeAddress&&typeof window.WelineThemeAddress.boot==="function"){window.WelineThemeAddress.boot();addressBooted=true;}
  mountInquiryForm(form);
  if(c.captchaEnabled){ensureInquiryCaptcha(form).catch(function(){});}
  form.addEventListener("weline:form:verification-error",function(){message.textContent=c.captchaLoadFailed||c.submitFailed;});
  form.addEventListener("submit",function(e){
    e.preventDefault();
    if(c.captchaEnabled&&form.dataset.welineCaptchaPending==="1")return;
    var countryWraps=form.querySelectorAll('[data-inquiry-country-required="1"]');
    for(var i=0;i<countryWraps.length;i++){
      var hidden=countryWraps[i].querySelector('input[name="values[country]"]')||countryWraps[i].querySelector('input[name^="values["]');
      if(!hidden||!String(hidden.value||"").trim()){message.textContent=c.countryRequired||"Please select country / region";return;}
    }
    submit.disabled=true;
    Promise.resolve().then(function(){return ensureInquiryCaptcha(form);}).then(function(){
      var values={},dataForm=new FormData(form);
      dataForm.forEach(function(v,k){var m=/^values\[([^\]]+)\](\[\])?$/.exec(k);if(!m)return;if(m[2]){(values[m[1]]||(values[m[1]]=[])).push(v)}else{values[m[1]]=v}});
      return Promise.resolve().then(function(){return Weline.load("api")}).then(function(api){
        var key=(window.crypto&&crypto.randomUUID)?crypto.randomUUID().replace(/-/g,""):String(Date.now())+Math.random().toString(16).slice(2);
        var payload={code:c.code,values:values,idempotency_key:key};
        if(c.captchaEnabled){Object.assign(payload,captchaPayload(dataForm));}
        return api.resource("inquiry").submit(payload);
      }).then(function(result){message.textContent=result.message||copy.success_message||c.submitted;form.reset();if(window.WelineThemeAddress&&typeof window.WelineThemeAddress.boot==="function"){window.WelineThemeAddress.boot();}mountInquiryForm(form);if(c.captchaEnabled){ensureInquiryCaptcha(form).catch(function(){});}});
    }).catch(function(err){
      delete form.dataset.welineCaptchaVerified;
      message.textContent=(err&&err.message==="captcha_load")||(err&&err.message==="captcha_module")?(c.captchaLoadFailed||c.submitFailed):(c.captchaFailed||c.submitFailed);
      if(c.captchaEnabled){form.dispatchEvent(new CustomEvent("weline:captcha:refresh-requested",{bubbles:true,detail:{form:form}}));}
    }).finally(function(){submit.disabled=false;});
  });
  if(c.allowJs&&c.customJs&&!customRan){var script=document.createElement("script");script.text=c.customJs;root.appendChild(script);customRan=true}
}
function ensure(){
  if(loaded)return Promise.resolve(loaded);
  if(pending)return pending;
  showMessage(c.loading);
  pending=Promise.resolve().then(function(){return Weline.load("api")}).then(function(api){return api.resource("inquiry").schema({code:c.code,locale:locale()})}).then(function(data){loaded=data;render(data);return data}).catch(function(error){showMessage(c.failed);throw error}).finally(function(){pending=null});
  return pending;
}
function open(){if(modal)modal.hidden=false;ensure().catch(function(){})}
function close(){if(modal&&c.mode!=="inline")modal.hidden=true}
root.querySelectorAll("[data-inquiry-open]").forEach(function(button){button.addEventListener("click",open)});
root.querySelectorAll("[data-inquiry-close]").forEach(function(button){button.addEventListener("click",close)});
if(c.selector){document.querySelectorAll(c.selector).forEach(function(button){button.addEventListener("click",function(e){e.preventDefault();open()})})}
if(c.mode==="inline")ensure().catch(function(){})
})
JS;

        return '<script>' . $js . '(' . $config . ');</script>';
    }

    private function trustedJsAllowed(): bool
    {
        return (bool)$this->config->getConfig('allow_trusted_widget_js', 'Weline_Inquiry', ConfigReader::area_BACKEND, false);
    }

    private function captchaEnabled(): bool
    {
        return RegistryModulePresence::isActivePresent('Weline_Captcha');
    }

    private function requestLocale(): string
    {
        $locale = trim((string)State::getLang());
        if ($locale === '' || strtolower($locale) === 'default') {
            return 'zh_Hans_CN';
        }

        return str_replace('-', '_', $locale);
    }

    /**
     * Resolve storefront static URL via fetchTagSource (PROD /static/theme/...).
     * Never emit DEV-shaped /Weline/Module/view/statics/ fallbacks.
     */
    private function addressAssetUrl(string $moduleSource): string
    {
        try {
            /** @var Template $template */
            $template = ObjectManager::getInstance(Template::class);
            $url = trim((string)$template->fetchTagSource(DataInterface::dir_type_STATICS, $moduleSource));
            if ($url === '') {
                return '';
            }
            $bust = self::ADDRESS_ASSET_BUST;
            return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $bust;
        } catch (\Throwable) {
            return '';
        }
    }
}

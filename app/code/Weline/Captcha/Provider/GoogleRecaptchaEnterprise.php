<?php

declare(strict_types=1);

namespace Weline\Captcha\Provider;

use Weline\Captcha\Interface\VerificationProviderInterface;
use Weline\Captcha\Model\CaptchaResult;
use Weline\Captcha\Service\CaptchaConfig;
use Weline\Captcha\Service\CaptchaOutboundProxy;
use Weline\Captcha\Service\GoogleOAuthService;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

final class GoogleRecaptchaEnterprise implements VerificationProviderInterface
{
    public function __construct(
        private readonly CaptchaConfig $config,
        private readonly CaptchaResult $results,
        private readonly GoogleOAuthService $oauth,
    ) {
    }

    public function code(): string
    {
        return 'google_enterprise';
    }

    public function cspDirectives(): array
    {
        // Official FAQ hosts + recaptcha.net mirror (CN/fallback). Collected via Extends CaptchaVendorsCsp.
        // https://cloud.google.com/recaptcha/docs/faq — CSP with reCAPTCHA
        return [
            'script-src' => [
                'https://www.google.com',
                'https://www.gstatic.com',
                'https://www.recaptcha.net',
            ],
            'frame-src' => [
                'https://www.google.com',
                'https://www.gstatic.com',
                'https://recaptcha.google.com',
                'https://www.recaptcha.net',
            ],
            'connect-src' => [
                'https://www.google.com',
                'https://www.gstatic.com',
                'https://www.recaptcha.net',
                // Server-side CreateAssessment also needs this when browser tooling proxies; harmless for FE.
                'https://recaptchaenterprise.googleapis.com',
            ],
            'img-src' => [
                'https://www.gstatic.com',
                'https://www.google.com',
                'https://www.recaptcha.net',
            ],
        ];
    }

    public function render(array $context): string
    {
        $siteKey = \trim($this->config->googleSiteKey());
        // Never emit enterprise.js?render= (Google shows「需要网站密钥」); router should not pick us empty.
        if ($siteKey === '') {
            return '';
        }
        $intent = $this->normalizeAction((string)($context['intent'] ?? 'generic'));
        $formId = (string)($context['form_id'] ?? '');
        $siteKeyJson = $this->json($siteKey);
        $intentJson = $this->json($intent);
        $formIdJson = $this->json($formId);
        // Prefer google.com first (assessment affinity); fall back to recaptcha.net on hang/error.
        $scriptUrlsJson = $this->json([
            'https://www.google.com/recaptcha/enterprise.js?render=' . \rawurlencode($siteKey),
            'https://www.recaptcha.net/recaptcha/enterprise.js?render=' . \rawurlencode($siteKey),
        ]);
        $allowDegrade = $this->config->allowLocalDegrade();
        $allowDegradeJson = $this->json($allowDegrade);

        $notice = \htmlspecialchars((string)__('此表单受 Google reCAPTCHA Enterprise 保护'), ENT_QUOTES, 'UTF-8');
        $privacy = \htmlspecialchars((string)__('隐私权'), ENT_QUOTES, 'UTF-8');
        $terms = \htmlspecialchars((string)__('条款'), ENT_QUOTES, 'UTF-8');
        $brand = \htmlspecialchars((string)__('受 Google 保护'), ENT_QUOTES, 'UTF-8');
        $googleMark = '<svg class="weline-captcha-google-trust__logo" viewBox="0 0 24 24" width="18" height="18" focusable="false" aria-hidden="true">'
            . '<path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>'
            . '<path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>'
            . '<path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>'
            . '<path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>'
            . '</svg>';

        return '<div class="weline-captcha weline-captcha-google" data-weline-captcha-provider="google_enterprise"'
            . ' data-allow-local-degrade="' . ($allowDegrade ? '1' : '0') . '" data-sdk-ready="0">'
            . '<input type="hidden" name="captcha_provider" value="google_enterprise">'
            . '<input type="hidden" name="captcha_response" value="">'
            . '<input type="hidden" name="captcha_action" value="' . \htmlspecialchars($intent, ENT_QUOTES, 'UTF-8') . '">'
            . '<div class="weline-captcha-google-trust" data-testid="google-recaptcha-trust-badge" role="note"'
            . ' aria-label="' . $notice . '" hidden data-sdk-ready="0">'
            . '<span class="weline-captcha-google-trust__mark">' . $googleMark . '</span>'
            . '<span class="weline-captcha-google-trust__body">'
            . '<span class="weline-captcha-google-trust__brand">' . $brand . '</span>'
            . '<small class="weline-captcha-google-trust__notice">' . $notice . '</small>'
            . '<span class="weline-captcha-google-trust__links">'
            . '<a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">' . $privacy . '</a>'
            . '<span aria-hidden="true">·</span>'
            . '<a href="https://policies.google.com/terms" target="_blank" rel="noopener noreferrer">' . $terms . '</a>'
            . '</span>'
            . '</span>'
            . '</div>'
            . '</div>'
            . '<script>(function(){var formId=' . $formIdJson . ';'
            . 'var form=formId?document.getElementById(formId):null;'
            // Checkout address editor is a section/div (not <form>); resolve by data-form-id / captcha root.
            . 'if(!form){var hint=(formId&&document.querySelector("[data-form-id=\\""+formId+"\\"]"))'
            . '||(document.currentScript&&document.currentScript.previousElementSibling)||null;'
            . 'form=hint?(hint.closest("form")||hint.closest("[data-shipping-editor]")'
            . '||hint.closest("[data-address-editor]")||hint.closest("[data-shipping-checkout-address]")'
            . '||hint.parentElement):null;}'
            . 'if(!form||form.dataset.welineCaptchaBound==="1"){return;}form.dataset.welineCaptchaBound="1";'
            . 'var allowDegrade=' . $allowDegradeJson . ';'
            . 'var root=form.querySelector("[data-weline-captcha-provider=\\"google_enterprise\\"]");'
            . 'var badge=root?root.querySelector("[data-testid=\\"google-recaptcha-trust-badge\\"]"):null;'
            . 'var reveal=function(){if(badge){badge.hidden=false;badge.setAttribute("data-sdk-ready","1");}'
            . 'if(root){root.setAttribute("data-sdk-ready","1");}};'
            . 'var conceal=function(){if(badge){badge.hidden=true;badge.setAttribute("data-sdk-ready","0");}'
            . 'if(root){root.setAttribute("data-sdk-ready","0");}};'
            . 'var fail=function(error){delete form.dataset.welineCaptchaPending;conceal();'
            . 'var failReason=String(error&&error.message||error||"recaptcha_unavailable");'
            . 'form.dispatchEvent(new CustomEvent("weline:form:verification-error",{bubbles:true,detail:{form:form,error:error,degrade:allowDegrade?"local_image":"",provider:"google_enterprise"}}));'
            . 'if(allowDegrade){form.dispatchEvent(new CustomEvent("weline:captcha:degrade",{bubbles:true,detail:{form:form,prefer:"local_image",reason:failReason}}));}};'
            . 'var urls=' . $scriptUrlsJson . ';var hostIdx=0;var tries=0;'
            // Hang without onerror must not block fallback. Never start host N+1 after host N onload —
            // a short watch + second enterprise.js can race and mint BROWSER_ERROR empty_token_props.
            . 'var loadNext=function(){if(window.grecaptcha&&grecaptcha.enterprise){return;}'
            . 'if(hostIdx>=urls.length){fail(new Error("recaptcha_script_error"));return;}'
            . 'var s=document.createElement("script");var idx=hostIdx;hostIdx+=1;s.src=urls[idx];s.async=true;s.defer=true;'
            . 'var settled=false;var onloaded=false;'
            . 'var finish=function(ok){if(settled){return;}settled=true;clearTimeout(watch);'
            . 'if(ok){tries=0;return;}loadNext();};'
            . 'var watch=setTimeout(function(){if(onloaded){return;}finish(!!(window.grecaptcha&&grecaptcha.enterprise));},5000);'
            . 's.onload=function(){onloaded=true;clearTimeout(watch);'
            . 'if(window.grecaptcha&&grecaptcha.enterprise){finish(true);return;}'
            . 'var grace=0;var g=setInterval(function(){grace+=1;'
            . 'if(window.grecaptcha&&grecaptcha.enterprise){clearInterval(g);finish(true);}'
            . 'else if(grace>=50){clearInterval(g);finish(false);}},100);};'
            . 's.onerror=function(){finish(false);};'
            . 'document.head.appendChild(s);};'
            . 'loadNext();'
            . 'var timer=setInterval(function(){tries+=1;'
            . 'if(window.grecaptcha&&grecaptcha.enterprise){clearInterval(timer);grecaptcha.enterprise.ready(function(){reveal();});}'
            . 'else if(tries>=80){clearInterval(timer);fail(new Error("recaptcha_unavailable"));}},250);'
            . 'form.addEventListener("weline:form:prepare-submit",function(event){'
            . 'var active=form.querySelector("[data-weline-captcha-provider]");'
            . 'if(!active||active.getAttribute("data-weline-captcha-provider")!=="google_enterprise"){return;}'
            . 'if(form.dataset.welineCaptchaVerified==="1"){return;}event.preventDefault();'
            . 'if(form.dataset.welineCaptchaPending==="1"){return;}form.dataset.welineCaptchaPending="1";'
            . 'if(!window.grecaptcha||!grecaptcha.enterprise){fail(new Error("recaptcha_unavailable"));return;}'
            // BROWSER_ERROR often arrives as ultra-fast/short tokens; retry then degrade — do not submit weak tickets.
            . 'var applyToken=function(token){'
            . 'var live=form.querySelector("[data-weline-captcha-provider=\\"google_enterprise\\"]");'
            . 'var input=live?live.querySelector("[name=captcha_response]"):null;'
            . 'if(!input||!token){fail(new Error("recaptcha_empty_token"));return;}'
            . 'input.value=token;form.dataset.welineCaptchaVerified="1";delete form.dataset.welineCaptchaPending;'
            . 'form.dispatchEvent(new CustomEvent("weline:form:verified",{bubbles:true,detail:{form:form,provider:"google_enterprise"}}));'
            . 'if(form.tagName==="FORM"){if(typeof form.requestSubmit==="function"){form.requestSubmit();}else{form.submit();}}};'
            . 'var tokenLooksWeak=function(token,ms){return !token||String(token).length<1000||ms<120||ms>=12000;};'
            . 'var runExecute=function(left){'
            . 'var started=Date.now();'
            . 'grecaptcha.enterprise.execute(' . $siteKeyJson . ',{action:' . $intentJson . '}).then(function(token){'
            . 'var ms=Date.now()-started;'
            . 'if(tokenLooksWeak(token,ms)&&left>1){window.setTimeout(function(){runExecute(left-1);},450);return;}'
            . 'if(tokenLooksWeak(token,ms)){fail(new Error("recaptcha_weak_token"));return;}'
            . 'applyToken(token);'
            . '}).catch(function(err){'
            . 'if(left>1){window.setTimeout(function(){runExecute(left-1);},350);return;}'
            . 'fail(err);'
            . '});};'
            . 'grecaptcha.enterprise.ready(function(){runExecute(3);});'
            . '});})();</script>';
    }

    public function verify(array $submission, string $intent, string $hostname, ?string $ip = null): bool
    {
        $token = \trim((string)($submission['captcha_response'] ?? ''));
        $action = $this->normalizeAction($intent);
        $hostname = \strtolower(\trim($hostname));
        if ($token === '') {
            throw new \RuntimeException('google_empty_token');
        }
        if ($action === '') {
            throw new \RuntimeException('google_invalid_action');
        }
        if ($hostname === '') {
            throw new \RuntimeException('google_empty_hostname');
        }

        $digest = \hash('sha256', $token);
        $used = clone $this->results;
        $used->clearData()->clearQuery()
            ->where(CaptchaResult::schema_fields_TOKEN, $digest)
            ->find()
            ->fetch();
        if ($used->getId()) {
            throw new \RuntimeException('google_token_reuse');
        }

        $assessment = $this->createAssessment($token, $action);
        $tokenProperties = \is_array($assessment['tokenProperties'] ?? null) ? $assessment['tokenProperties'] : [];
        $risk = \is_array($assessment['riskAnalysis'] ?? null) ? $assessment['riskAnalysis'] : [];
        if (($tokenProperties['valid'] ?? false) !== true) {
            $reason = \strtolower(\trim((string)($tokenProperties['invalidReason'] ?? 'UNKNOWN')));
            $remoteHost = \strtolower(\trim((string)($tokenProperties['hostname'] ?? '')));
            $remoteAction = \strtolower(\trim((string)($tokenProperties['action'] ?? '')));
            if ($reason === 'browser_error' && ($remoteHost === '' || $remoteAction === '')) {
                // Empty props = key/domain challenge never bound (not a "bot score" reject).
                $reason = 'browser_error:empty_token_props';
            }
            throw new \RuntimeException('google_token_invalid:' . ($reason !== '' ? $reason : 'UNKNOWN'));
        }
        $remoteAction = $this->normalizeAction((string)($tokenProperties['action'] ?? ''));
        if ($remoteAction !== $action) {
            throw new \RuntimeException('google_action_mismatch:' . $remoteAction . '!=' . $action);
        }

        $remoteHost = \strtolower(\trim((string)($tokenProperties['hostname'] ?? '')));
        if ($remoteHost === '' || !$this->hostnameAllowed($remoteHost, $hostname)) {
            throw new \RuntimeException(
                'google_hostname_mismatch:token=' . $remoteHost . ';request=' . $hostname
            );
        }

        $createTime = \strtotime((string)($tokenProperties['createTime'] ?? ''));
        if ($createTime <= 0 || $createTime < \time() - $this->config->tokenMaxAge() || $createTime > \time() + 30) {
            throw new \RuntimeException('google_token_expired');
        }
        $score = (float)($risk['score'] ?? 0.0);
        if ($score < $this->config->scoreThreshold()) {
            throw new \RuntimeException('google_score_low:' . $score);
        }

        $record = clone $this->results;
        $record->clearData()
            ->setData(CaptchaResult::schema_fields_TOKEN, $digest)
            ->setData(CaptchaResult::schema_fields_CODE, 'used')
            ->setData(CaptchaResult::schema_fields_TYPE, $this->code())
            ->setData(CaptchaResult::schema_fields_EXPIRES_AT, \date('Y-m-d H:i:s', \time() + 600))
            ->setData(CaptchaResult::schema_fields_CREATED_AT, \date('Y-m-d H:i:s'))
            ->save();
        return true;
    }

    /** @return array<string, mixed> */
    private function createAssessment(string $token, string $action): array
    {
        $projectId = $this->config->googleProjectId();
        $apiKey = $this->config->googleApiKey();
        if ($this->config->googleApiKeyLooksLikeSiteKey()) {
            throw new \RuntimeException((string)__(
                'Google Enterprise API Key 配置错误：当前值像 Site Key（6L…），服务端校验需要 Cloud「API 密钥」（通常 AIza… 开头）。请到系统配置 → Weline_Captcha 更换 API Key。'
            ));
        }
        $url = 'https://recaptchaenterprise.googleapis.com/v1/projects/'
            . \rawurlencode($projectId) . '/assessments';
        if ($apiKey !== '') {
            $url .= '?key=' . \rawurlencode($apiKey);
        }
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $accessToken = $this->config->googleAccessToken();
        if ($accessToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $accessToken;
        }

        [$raw, $status, $error] = $this->requestAssessment($url, $headers, $token, $action);
        if ($status === 401 && $apiKey === '' && $this->config->googleRefreshToken() !== '') {
            $accessToken = $this->oauth->refreshAccessToken();
            if ($accessToken !== '') {
                $headers = [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $accessToken,
                ];
                [$raw, $status, $error] = $this->requestAssessment($url, $headers, $token, $action);
            }
        }
        if (!\is_string($raw) || $status < 200 || $status >= 300) {
            $bodySnippet = '';
            if (\is_string($raw) && $raw !== '') {
                $collapsed = \preg_replace('/\s+/u', ' ', $raw);
                $bodySnippet = \mb_substr(\is_string($collapsed) ? $collapsed : $raw, 0, 800);
            }
            $detail = \trim($error . ($bodySnippet !== '' ? ' ' . $bodySnippet : ''));
            if ($status === 400 && \stripos($detail, 'siteKey is invalid') !== false) {
                throw new \RuntimeException((string)__(
                    'Google Enterprise Site Key 无效：与项目 %{1} 不匹配或已删除。请到 Google Cloud → reCAPTCHA Enterprise 为该项目新建/复制 Site Key，并加入本地域名（如 *.test.weline.com），再写回系统配置 Weline_Captcha。',
                    [$projectId]
                ));
            }
            throw new \RuntimeException((string)__(
                'Google reCAPTCHA Enterprise 验证失败：HTTP %{1} %{2}',
                [$status, $detail]
            ));
        }
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException((string)__('Google reCAPTCHA Enterprise 返回了无效响应'));
        }
        return $decoded;
    }

    /** @return array{0:string|false,1:int,2:string} */
    private function requestAssessment(
        string $url,
        array $headers,
        string $token,
        string $action,
    ): array {
        $ch = \curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException((string)__('无法初始化 Google 验证请求'));
        }
        \curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $this->json([
                'event' => [
                    'token' => $token,
                    'siteKey' => $this->config->googleSiteKey(),
                    'expectedAction' => $action,
                ],
            ]),
        ]);
        // Auto punch: reuse captcha/social-login/env outbound proxy (PHP curl ignores env alone).
        CaptchaOutboundProxy::apply($ch);
        $raw = \curl_exec($ch);
        $status = (int)\curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = \curl_error($ch);
        \curl_close($ch);
        return [$raw, $status, $error];
    }

    private function hostnameAllowed(string $remoteHost, string $requestHost): bool
    {
        if ($remoteHost !== $requestHost) {
            return false;
        }
        $domains = $this->config->allowedDomains();
        $data = new DataObject(['domains' => $domains]);
        ObjectManager::getInstance(EventsManager::class)->dispatch('Weline_Captcha::domains::collect', $data);
        $collected = $data->getData('domains');
        if (\is_array($collected)) {
            $domains = \array_merge($domains, $collected);
        }
        if ($domains === []) {
            return true;
        }
        foreach ($domains as $domain) {
            $domain = \strtolower(\trim((string)$domain));
            if ($domain === $requestHost) {
                return true;
            }
            if (\str_starts_with($domain, '*.') && \str_ends_with($requestHost, \substr($domain, 1))) {
                return true;
            }
        }
        return false;
    }

    private function normalizeAction(string $action): string
    {
        $action = \trim($action);
        // Google Enterprise: alphanumeric, slashes, underscores only — dots/dashes mint BROWSER_ERROR tokens.
        $action = \preg_replace('/[^A-Za-z0-9_\/]+/', '_', $action) ?? '';
        $action = \preg_replace('/_+/', '_', $action) ?? '';
        $action = \trim($action, '_');
        return \preg_match('/\A[A-Za-z0-9_\/]{1,100}\z/D', $action) === 1 ? $action : '';
    }

    private function json(mixed $value): string
    {
        $json = \json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        return $json === false ? '{}' : $json;
    }
}

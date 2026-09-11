<?php

declare(strict_types=1);

namespace Weline\Captcha\Provider;

use Weline\Captcha\Interface\VerificationProviderInterface;
use Weline\Captcha\Model\CaptchaResult;
use Weline\Captcha\Service\CaptchaConfig;

/**
 * Tencent Cloud Captcha (Web) — ticket + randstr verified via DescribeCaptchaResult.
 */
final class TencentCaptcha implements VerificationProviderInterface
{
    private const SDK_URL = 'https://turing.captcha.qcloud.com/TCaptcha.js';

    public function __construct(
        private readonly CaptchaConfig $config,
        private readonly CaptchaResult $results,
    ) {
    }

    public function code(): string
    {
        return 'tencent_captcha';
    }

    public function cspDirectives(): array
    {
        return [
            'script-src' => [
                'https://turing.captcha.qcloud.com',
            ],
            'frame-src' => [
                'https://turing.captcha.qcloud.com',
            ],
            'connect-src' => [
                'https://turing.captcha.qcloud.com',
                'https://captcha.tencentcloudapi.com',
            ],
        ];
    }

    public function render(array $context): string
    {
        $appId = $this->config->tencentAppId();
        $intent = $this->normalizeAction((string)($context['intent'] ?? 'generic'));
        $formId = (string)($context['form_id'] ?? '');
        $allowDegrade = $this->config->allowLocalDegrade();
        $appIdJson = $this->json($appId);
        $formIdJson = $this->json($formId);
        $allowDegradeJson = $this->json($allowDegrade);
        $sdkUrl = \htmlspecialchars(self::SDK_URL, ENT_QUOTES, 'UTF-8');

        return '<div class="weline-captcha weline-captcha-tencent" data-weline-captcha-provider="tencent_captcha"'
            . ' data-allow-local-degrade="' . ($allowDegrade ? '1' : '0') . '">'
            . '<input type="hidden" name="captcha_provider" value="tencent_captcha">'
            . '<input type="hidden" name="captcha_response" value="">'
            . '<input type="hidden" name="captcha_randstr" value="">'
            . '<input type="hidden" name="captcha_action" value="' . \htmlspecialchars($intent, ENT_QUOTES, 'UTF-8') . '">'
            . '<small>' . \htmlspecialchars((string)__('此表单受腾讯云验证码保护'), ENT_QUOTES, 'UTF-8') . '</small>'
            . '</div>'
            . '<script src="' . $sdkUrl . '" async defer></script>'
            . '<script>(function(){var form=document.getElementById(' . $formIdJson . ');'
            . 'if(!form||form.dataset.welineCaptchaBound==="1"){return;}form.dataset.welineCaptchaBound="1";'
            . 'var allowDegrade=' . $allowDegradeJson . ';'
            . 'var degrade=function(error){delete form.dataset.welineCaptchaPending;'
            . 'form.dispatchEvent(new CustomEvent("weline:form:verification-error",{bubbles:true,detail:{form:form,error:error,degrade:allowDegrade?"local_image":"",provider:"tencent_captcha"}}));'
            . 'if(allowDegrade){form.dispatchEvent(new CustomEvent("weline:captcha:degrade",{bubbles:true,detail:{form:form,prefer:"local_image",reason:String(error&&error.message||error||"tencent_unavailable")}}));}};'
            . 'form.addEventListener("weline:form:prepare-submit",function(event){'
            . 'var active=form.querySelector("[data-weline-captcha-provider]");'
            . 'if(!active||active.getAttribute("data-weline-captcha-provider")!=="tencent_captcha"){return;}'
            . 'if(form.dataset.welineCaptchaVerified==="1"){return;}event.preventDefault();'
            . 'if(form.dataset.welineCaptchaPending==="1"){return;}form.dataset.welineCaptchaPending="1";'
            . 'var fail=function(error){degrade(error);};'
            . 'var run=function(){try{'
            . 'if(typeof window.TencentCaptcha!=="function"){fail(new Error("tencent_sdk_unavailable"));return;}'
            . 'var captcha=new window.TencentCaptcha(' . $appIdJson . ',function(res){'
            . 'if(!res||res.ret===2){delete form.dataset.welineCaptchaPending;return;}'
            . 'if(res.ret!==0||!res.ticket||String(res.ticket).indexOf("trerror_")==="0"){fail(new Error("tencent_ticket_invalid"));return;}'
            . 'var root=form.querySelector("[data-weline-captcha-provider=\\"tencent_captcha\\"]");'
            . 'var ticketInput=root?root.querySelector("[name=captcha_response]"):null;'
            . 'var randInput=root?root.querySelector("[name=captcha_randstr]"):null;'
            . 'if(!ticketInput||!randInput){fail(new Error("tencent_inputs_missing"));return;}'
            . 'ticketInput.value=String(res.ticket);randInput.value=String(res.randstr||"");'
            . 'form.dataset.welineCaptchaVerified="1";delete form.dataset.welineCaptchaPending;'
            . 'form.dispatchEvent(new CustomEvent("weline:form:verified",{bubbles:true,detail:{form:form,provider:"tencent_captcha"}}));'
            . 'if(typeof form.requestSubmit==="function"){form.requestSubmit();}else{form.submit();}'
            . '},{needFeedBack:false});captcha.show();'
            . '}catch(error){fail(error);}};'
            . 'if(typeof window.TencentCaptcha==="function"){run();}'
            . 'else{var tries=0;var timer=setInterval(function(){tries++;if(typeof window.TencentCaptcha==="function"){clearInterval(timer);run();}'
            . 'else if(tries>=40){clearInterval(timer);fail(new Error("tencent_sdk_timeout"));}},100);}'
            . '});})();</script>';
    }

    public function verify(array $submission, string $intent, string $hostname, ?string $ip = null): bool
    {
        $ticket = \trim((string)($submission['captcha_response'] ?? ''));
        $randstr = \trim((string)($submission['captcha_randstr'] ?? ''));
        if ($ticket === '' || $randstr === '' || \str_starts_with($ticket, 'trerror_')) {
            return false;
        }

        $digest = \hash('sha256', $ticket);
        $used = clone $this->results;
        $used->clearData()->clearQuery()
            ->where(CaptchaResult::schema_fields_TOKEN, $digest)
            ->find()
            ->fetch();
        if ($used->getId()) {
            return false;
        }

        $userIp = \trim((string)$ip);
        if ($userIp === '') {
            $userIp = '127.0.0.1';
        }

        $result = $this->describeCaptchaResult($ticket, $randstr, $userIp);
        $code = (int)($result['CaptchaCode'] ?? $result['Response']['CaptchaCode'] ?? 0);
        if ($code !== 1) {
            return false;
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
    private function describeCaptchaResult(string $ticket, string $randstr, string $userIp): array
    {
        $appId = $this->config->tencentAppId();
        $secret = $this->config->tencentAppSecretKey();
        $payload = [
            'Action' => 'DescribeCaptchaResult',
            'Version' => '2019-07-22',
            'CaptchaType' => 9,
            'Ticket' => $ticket,
            'UserIp' => $userIp,
            'Randstr' => $randstr,
            'CaptchaAppId' => (int)$appId,
            'AppSecretKey' => $secret,
        ];

        $ch = \curl_init('https://captcha.tencentcloudapi.com/');
        if ($ch === false) {
            throw new \RuntimeException((string)__('无法初始化腾讯云验证请求'));
        }
        \curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-TC-Action: DescribeCaptchaResult',
                'X-TC-Version: 2019-07-22',
            ],
            CURLOPT_POSTFIELDS => $this->json($payload),
        ]);
        $raw = \curl_exec($ch);
        $status = (int)\curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = \curl_error($ch);
        \curl_close($ch);
        if (!\is_string($raw) || $status < 200 || $status >= 300) {
            throw new \RuntimeException((string)__('腾讯云验证码校验失败：HTTP %{1} %{2}', [$status, $error]));
        }
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException((string)__('腾讯云验证码返回了无效响应'));
        }
        // API may wrap under Response.
        if (isset($decoded['Response']) && \is_array($decoded['Response'])) {
            return $decoded['Response'];
        }
        return $decoded;
    }

    private function normalizeAction(string $action): string
    {
        $action = \trim($action);
        return \preg_match('/\A[A-Za-z0-9_\/.-]{1,100}\z/D', $action) === 1 ? $action : '';
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

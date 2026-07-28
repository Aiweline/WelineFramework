<?php

declare(strict_types=1);

namespace Weline\Captcha\Provider;

use Weline\Captcha\Interface\VerificationProviderInterface;
use Weline\Captcha\Model\CaptchaResult;
use Weline\Captcha\Service\CaptchaConfig;
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

    public function render(array $context): string
    {
        $siteKey = $this->config->googleSiteKey();
        $intent = $this->normalizeAction((string)($context['intent'] ?? 'generic'));
        $formId = (string)($context['form_id'] ?? '');
        $siteKeyJson = $this->json($siteKey);
        $intentJson = $this->json($intent);
        $formIdJson = $this->json($formId);
        $scriptUrl = 'https://www.google.com/recaptcha/enterprise.js?render=' . \rawurlencode($siteKey);

        return '<div class="weline-captcha weline-captcha-google" data-weline-captcha-provider="google_enterprise">'
            . '<input type="hidden" name="captcha_provider" value="google_enterprise">'
            . '<input type="hidden" name="captcha_response" value="">'
            . '<input type="hidden" name="captcha_action" value="' . \htmlspecialchars($intent, ENT_QUOTES, 'UTF-8') . '">'
            . '<small>' . \htmlspecialchars((string)__('此表单受 Google reCAPTCHA Enterprise 保护'), ENT_QUOTES, 'UTF-8') . '</small>'
            . '</div>'
            . '<script src="' . \htmlspecialchars($scriptUrl, ENT_QUOTES, 'UTF-8') . '" async defer></script>'
            . '<script>(function(){var form=document.getElementById(' . $formIdJson . ');'
            . 'if(!form||form.dataset.welineCaptchaBound==="1"){return;}form.dataset.welineCaptchaBound="1";'
            . 'form.addEventListener("weline:form:prepare-submit",function(event){'
            . 'if(form.dataset.welineCaptchaVerified==="1"){return;}event.preventDefault();'
            . 'if(form.dataset.welineCaptchaPending==="1"){return;}form.dataset.welineCaptchaPending="1";'
            . 'var fail=function(error){delete form.dataset.welineCaptchaPending;form.dispatchEvent(new CustomEvent("weline:form:verification-error",{bubbles:true,detail:{form:form,error:error}}));};'
            . 'if(!window.grecaptcha||!grecaptcha.enterprise){fail(new Error("recaptcha_unavailable"));return;}'
            . 'grecaptcha.enterprise.ready(function(){grecaptcha.enterprise.execute(' . $siteKeyJson . ',{action:' . $intentJson . '}).then(function(token){'
            . 'var input=form.querySelector("[name=captcha_response]");if(!input||!token){fail(new Error("recaptcha_empty_token"));return;}'
            . 'input.value=token;form.dataset.welineCaptchaVerified="1";delete form.dataset.welineCaptchaPending;'
            . 'form.dispatchEvent(new CustomEvent("weline:form:verified",{bubbles:true,detail:{form:form,provider:"google_enterprise"}}));'
            . 'if(typeof form.requestSubmit==="function"){form.requestSubmit();}else{form.submit();}'
            . '}).catch(fail);});});})();</script>';
    }

    public function verify(array $submission, string $intent, string $hostname, ?string $ip = null): bool
    {
        $token = \trim((string)($submission['captcha_response'] ?? ''));
        $action = $this->normalizeAction($intent);
        $hostname = \strtolower(\trim($hostname));
        if ($token === '' || $action === '' || $hostname === '') {
            return false;
        }

        $digest = \hash('sha256', $token);
        $used = clone $this->results;
        $used->clearData()->clearQuery()
            ->where(CaptchaResult::schema_fields_TOKEN, $digest)
            ->find()
            ->fetch();
        if ($used->getId()) {
            return false;
        }

        $assessment = $this->createAssessment($token, $action);
        $tokenProperties = \is_array($assessment['tokenProperties'] ?? null) ? $assessment['tokenProperties'] : [];
        $risk = \is_array($assessment['riskAnalysis'] ?? null) ? $assessment['riskAnalysis'] : [];
        if (($tokenProperties['valid'] ?? false) !== true) {
            return false;
        }
        if ($this->normalizeAction((string)($tokenProperties['action'] ?? '')) !== $action) {
            return false;
        }

        $remoteHost = \strtolower(\trim((string)($tokenProperties['hostname'] ?? '')));
        if ($remoteHost === '' || !$this->hostnameAllowed($remoteHost, $hostname)) {
            return false;
        }

        $createTime = \strtotime((string)($tokenProperties['createTime'] ?? ''));
        if ($createTime <= 0 || $createTime < \time() - $this->config->tokenMaxAge() || $createTime > \time() + 30) {
            return false;
        }
        if ((float)($risk['score'] ?? 0.0) < $this->config->scoreThreshold()) {
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
    private function createAssessment(string $token, string $action): array
    {
        $projectId = $this->config->googleProjectId();
        $apiKey = $this->config->googleApiKey();
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
            throw new \RuntimeException((string)__('Google reCAPTCHA Enterprise 验证失败：HTTP %{1} %{2}', [$status, $error]));
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

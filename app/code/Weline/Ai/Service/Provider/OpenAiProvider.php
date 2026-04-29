<?php
declare(strict_types=1);

/*
 * 鏈枃浠剁敱 绉嬫灚闆侀 缂栧啓锛屾墍鏈夎В閲婃潈褰扐iweline鎵€鏈夈€?
 * 浣滆€咃細Admin
 * 閭锛歛iweline@qq.com
 * 缃戝潃锛歛iweline.com
 * 璁哄潧锛歨ttps://bbs.aiweline.com
 * 鏃ユ湡锛?025/10/09
 */

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Model\AiModel;
use Weline\Ai\Helper\ErrorMessageHelper;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Http\Sse\SseContext;
use Weline\Ai\Service\Provider\Stream\OpenAiStreamParser;
use Weline\Framework\Php\CurlStreamPump;
use Weline\Framework\Php\FiberTaskRunner;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * OpenAI API鎻愪緵鑰?
 * 
 * 鍔熻兘锛?
 * - 璋冪敤OpenAI API鐢熸垚鍐呭
 * - 鏀寔娴佸紡鍝嶅簲
 * - 鏀寔浠ｇ悊閰嶇疆
 * - 閿欒澶勭悊鍜岄噸璇曟満鍒?
 * - Token浣跨敤閲忕粺璁?
 */
class OpenAiProvider implements ProviderInterface
{
    private const THINKING_PROTOCOL_PAYLOAD = 'thinking_payload';
    private const THINKING_PROTOCOL_BOOLEAN = 'boolean_toggle';
    private const THINKING_PROTOCOL_REASONING_EFFORT = 'reasoning_effort_only';
    private const THINKING_PROTOCOL_NONE = 'none';

    /**
     * 鏈€澶ч噸璇曟鏁?
     */
    private const MAX_RETRIES = 3;

    /**
     * 閲嶈瘯寤惰繜锛堢锛?
     */
    private const RETRY_DELAY = 1;

    /**
     * curl 鍦?CURLOPT_WRITEFUNCTION 杩斿洖 -1 涓浼犺緭鏃剁殑鍏稿瀷閿欒锛堝 SSE 瀵圭鏂紑銆佸洖璋冭繑鍥?false锛夈€?
     * 涓嶅簲鎸夈€屼笂娓?API 鏁呴殰銆嶅悜涓婃姏鑷村懡寮傚父锛屽惁鍒欏凡鏂紑鐨?SSE 閾捐矾浠嶄細鍦ㄦ湇鍔＄璁颁竴鏉¤瀵兼€?exception銆?
     */
    private function isCurlStreamWriteAbortError(string $error): bool
    {
        if ($error === '') {
            return false;
        }
        if (stripos($error, 'failure writing output to destination') !== false) {
            return true;
        }
        if (stripos($error, 'returned -1') !== false) {
            return true;
        }

        return stripos($error, 'returned -1') !== false
            && (stripos($error, 'writestring') !== false || stripos($error, 'callback') !== false);
    }

    /**
     * 鏋勯€犲嚱鏁?
     */
    public function __construct()
    {
        // 鏃犲弬鏁版瀯閫犲嚱鏁帮紝鐢ㄤ簬渚濊禆娉ㄥ叆鍏煎鎬?
    }

    /**
     * 璋冪敤OpenAI API
     * 
     * @param AiModel $model
     * @param string $prompt
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function generate(AiModel $model, string $prompt, array $params = []): array
    {
        $config = $model->getConfig();
        
        // 璋冭瘯鏃ュ織
        Env::log('ai_provider_debug.log', sprintf(
            '[OpenAiProvider::generate] model->getConfig() api_key=%s',
            isset($config['api_key']) ? (empty($config['api_key']) ? '(empty)' : '...' . substr($config['api_key'], -4)) : '(not set)'
        ), 'DEBUG');
        
        // 鍚堝苟provider_config锛堜紭鍏堬級
        $providerConfig = $model->getData('provider_config');
        if (!empty($providerConfig)) {
            $providerData = is_string($providerConfig) ? json_decode($providerConfig, true) : $providerConfig;
            Env::log('ai_provider_debug.log', sprintf(
                '[OpenAiProvider::generate] provider_config api_key=%s',
                isset($providerData['api_key']) ? (empty($providerData['api_key']) ? '(empty)' : '...' . substr($providerData['api_key'], -4)) : '(not set)'
            ), 'DEBUG');
            if (is_array($providerData)) {
                foreach ($providerData as $k => $v) {
                    if ($v !== '' && $v !== null) {
                        $config[$k] = $v;
                    }
                }
            }
        }
        
        Env::log('ai_provider_debug.log', sprintf(
            '[OpenAiProvider::generate] final config api_key=%s',
            isset($config['api_key']) ? (empty($config['api_key']) ? '(empty)' : '...' . substr($config['api_key'], -4)) : '(not set)'
        ), 'DEBUG');
        
        $apiKey = $this->getApiKey($config);
        
        if (empty($apiKey)) {
            throw new Exception(ErrorMessageHelper::getMissingApiKeyMessage());
        }

        $messages = $this->buildMessages($prompt, $params);
        $timeout = ProviderTimeoutPolicy::resolveRequestTimeout($params, $config);

        // 璁剧疆鎵ц鏃堕棿闄愬埗锛圫SE 妯″紡涓嬭烦杩囷紝鐢?SseWriter 缁熶竴绠＄悊涓烘棤闄愬埗锛?

        
        // 璁剧疆鎵ц鏃堕棿闄愬埗锛圫SE 妯″紡涓嬭烦杩囷紝鐢?SseWriter 缁熶竴绠＄悊涓烘棤闄愬埗锛?
        $shouldRestoreExecutionTimeLimit = !SseContext::isSseEnabled();
        if ($shouldRestoreExecutionTimeLimit) {
            if ($timeout > 0) {
                $timeLimit = $timeout + ProviderTimeoutPolicy::EXECUTION_TIME_BUFFER;
                @set_time_limit($timeLimit);
            } else {
                @set_time_limit(0);
            }
        }
        try {
            $requestData = $this->buildChatCompletionRequestData($model, $config, $messages, $params, false);

        // 鏅鸿兘浣撴ā寮忥細娣诲姞 tools锛坒unction calling锛?
        if (!empty($params['tools']) && is_array($params['tools'])) {
            $requestData['tools'] = $this->convertToolsToOpenAiFormat($params['tools']);
            $requestData['tool_choice'] = $params['tool_choice'] ?? 'auto';
        }

        // JSON 妯″紡锛堜笟鐣屾爣鍑嗭級锛氬己鍒舵ā鍨嬪彧杈撳嚭鍚堟硶 JSON锛岄檷浣庤В鏋愬け璐ョ巼
        if (!empty($params['response_format']) && is_array($params['response_format'])) {
            $requestData['response_format'] = $params['response_format'];
        }

        // 浼樺厛浣跨敤base_url锛屽鏋滄病鏈夊垯浣跨敤api_url锛屾渶鍚庝娇鐢ㄩ粯璁ゅ€?
        $apiUrl = $config['base_url'] ?? $config['api_url'] ?? 'https://api.openai.com/v1';
        if (!str_ends_with($apiUrl, '/chat/completions')) {
            $apiUrl = rtrim($apiUrl, '/') . '/chat/completions';
        }
        
        // 纭繚proxyInfo鏄暟缁勶紙鍙兘瀛樺偍涓?JSON 瀛楃涓诧級
        $proxyInfo = $model->getProxyInfo();
        if (is_string($proxyInfo) && !empty($proxyInfo)) {
            $proxyInfo = json_decode($proxyInfo, true) ?: [];
        }
        if (!is_array($proxyInfo)) {
            $proxyInfo = [];
        }
        
        $response = $this->callApiWithRetry(
            $apiUrl,
            $apiKey,
            $requestData,
            $proxyInfo,
            $timeout
        );

        // 鎻愬彇 tool_calls锛堟櫤鑳戒綋妯″紡锛?
        $toolCalls = $this->extractToolCalls($response);
        $finishReason = $response['choices'][0]['finish_reason'] ?? '';

        $message = $response['choices'][0]['message'] ?? [];
        $result = [
            'content' => $message['content'] ?? '',
            'usage' => [
                'prompt_tokens' => $response['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $response['usage']['total_tokens'] ?? 0,
            ],
            'model' => $response['model'] ?? '',
            'finish_reason' => $finishReason,
        ];

            // 鎹曡幏鎺ㄧ悊/鎬濊€冮摼鍐呭锛圖eepSeek reasoning_content 绛夛級
            if (!empty($message['reasoning_content'])) {
                $result['reasoning_content'] = $message['reasoning_content'];
            }

            // 濡傛灉鏈?tool_calls锛岄檮鍔犲埌缁撴灉涓?
            if (!empty($toolCalls)) {
                $result['tool_calls'] = $toolCalls;
                // 淇濈暀鍘熷 message 鐢ㄤ簬鏋勫缓鍚庣画娑堟伅锛坅gent 闇€瑕佸畬鏁寸殑 assistant message锛?
                $result['assistant_message'] = $message;
            }

            return $result;
        } finally {
            if ($shouldRestoreExecutionTimeLimit) {
                @set_time_limit(0);
            }
        }
    }

    /**
     * 娴佸紡鐢熸垚骞惰繑鍥炲畬鏁寸粨鏋勫寲鍝嶅簲锛堝惈 tool_calls锛?
     * 
     * 鐢ㄤ簬鏅鸿兘浣撴ā寮忥細浣跨敤娴佸紡浼犺緭淇濇寔杩炴帴娲昏穬锛屽悓鏃堕€氳繃鍥炶皟瀹炴椂鎺ㄩ€?
     * reasoning_content 鍜?content锛屾渶缁堣繑鍥炰笌 generate() 鐩稿悓鏍煎紡鐨勭粨鏋溿€?
     * 
     * @param AiModel $model
     * @param string $prompt
     * @param array $params 棰濆鍙傛暟锛屾敮鎸侊細
     *   - messages: 娑堟伅鏁扮粍
     *   - tools: 宸ュ叿瀹氫箟
     *   - on_reasoning: callable(string $chunk) 鎺ㄧ悊鍐呭鍥炶皟
     *   - on_content: callable(string $chunk) 姝ｆ枃鍐呭鍥炶皟
     *   - on_heartbeat: callable() 蹇冭烦鍥炶皟锛堟瘡鏀跺埌鏁版嵁灏辫Е鍙戯級
     * @return array 涓?generate() 鐩稿悓鏍煎紡锛歝ontent, reasoning_content, tool_calls, finish_reason, usage, assistant_message
     * @throws Exception
     */
    public function generateStreamFull(AiModel $model, string $prompt, array $params = []): array
    {
        $config = $model->getConfig();

        // 璋冭瘯鏃ュ織
        Env::log('ai_provider_debug.log', sprintf(
            '[OpenAiProvider::generateStreamFull] objId=%d, model->getConfig() api_key=%s',
            spl_object_id($model),
            isset($config['api_key']) ? (empty($config['api_key']) ? '(empty)' : '...' . substr($config['api_key'], -4)) : '(not set)'
        ), 'DEBUG');

        // 鍚堝苟 provider_config
        $providerConfig = $model->getData('provider_config');
        if (!empty($providerConfig)) {
            $providerData = is_string($providerConfig) ? json_decode($providerConfig, true) : $providerConfig;
            Env::log('ai_provider_debug.log', sprintf(
                '[OpenAiProvider::generateStreamFull] provider_config api_key=%s',
                isset($providerData['api_key']) ? (empty($providerData['api_key']) ? '(empty)' : '...' . substr($providerData['api_key'], -4)) : '(not set)'
            ), 'DEBUG');
            if (is_array($providerData)) {
                foreach ($providerData as $k => $v) {
                    if ($v !== '' && $v !== null) {
                        $config[$k] = $v;
                    }
                }
            }
        }

        Env::log('ai_provider_debug.log', sprintf(
            '[OpenAiProvider::generateStreamFull] final config api_key=%s',
            isset($config['api_key']) ? (empty($config['api_key']) ? '(empty)' : '...' . substr($config['api_key'], -4)) : '(not set)'
        ), 'DEBUG');

        $apiKey = $this->getApiKey($config);
        if (empty($apiKey)) {
            throw new Exception(ErrorMessageHelper::getMissingApiKeyMessage());
        }

        $messages = $this->buildMessages($prompt, $params);
        $timeout = $this->resolveStreamTimeout($params, $config);

        // 闈?SSE 鐨勬祦寮忚皟鐢ㄤ篃瑕佸彇娑堟€绘墽琛屾椂闂撮檺鍒讹紝閬垮厤 generateStreamFull 鍦ㄩ暱鎺ㄧ悊鏃惰 PHP 鎴柇銆?
        $shouldRestoreExecutionTimeLimit = !SseContext::isSseEnabled();
        if ($shouldRestoreExecutionTimeLimit) {
            if ($timeout > 0) {
                @set_time_limit($timeout + ProviderTimeoutPolicy::EXECUTION_TIME_BUFFER);
            } else {
                @set_time_limit(0);
            }
        }

        $streamWriteAborted = false;
        try {
            $requestData = $this->buildChatCompletionRequestData($model, $config, $messages, $params, true);

        // 鏅鸿兘浣撴ā寮忥細娣诲姞 tools
        if (!empty($params['tools']) && is_array($params['tools'])) {
            $requestData['tools'] = $this->convertToolsToOpenAiFormat($params['tools']);
            $requestData['tool_choice'] = $params['tool_choice'] ?? 'auto';
        }

        // JSON 妯″紡锛堜笟鐣屾爣鍑嗭級锛氬己鍒舵ā鍨嬪彧杈撳嚭鍚堟硶 JSON
        if (!empty($params['response_format']) && is_array($params['response_format'])) {
            $requestData['response_format'] = $params['response_format'];
        }

        $apiUrl = $config['base_url'] ?? $config['api_url'] ?? 'https://api.openai.com/v1';
        if (!str_ends_with($apiUrl, '/chat/completions')) {
            $apiUrl = rtrim($apiUrl, '/') . '/chat/completions';
        }

        $proxyInfo = $model->getProxyInfo();
        if (is_string($proxyInfo) && !empty($proxyInfo)) {
            $proxyInfo = json_decode($proxyInfo, true) ?: [];
        }
        if (!is_array($proxyInfo)) {
            $proxyInfo = [];
        }

        // 鍥炶皟
        $onReasoning = $params['on_reasoning'] ?? null;
        $onContent = $params['on_content'] ?? null;
        $onHeartbeat = $params['on_heartbeat'] ?? null;
        $onWaiting = $params['on_waiting'] ?? null;

        // 绱Н鍣?
        $fullContent = '';
        $fullReasoning = '';
        $toolCallsAccum = []; // index => ['id'=>..., 'name'=>..., 'arguments'=>'']
        $finishReason = '';
        $modelName = '';

        $ch = $this->initCurl($apiUrl, $apiKey, $requestData, $proxyInfo, $timeout, true);

        $rawResponseBuffer = '';
        $hasValidChunk = false;
        $streamLineBuffer = '';
        $startTime = microtime(true);
        $lastProgressNotify = $startTime;
        $progressInterval = 5; // 姣?5 绉掑彂閫佷竴娆＄瓑寰呯姸鎬?

        // 杩涘害鍥炶皟锛氬湪 AI 鏈繑鍥炴暟鎹椂鍛ㄦ湡鎬цЕ鍙戯紝淇濇寔 SSE 娲昏穬骞舵樉绀虹瓑寰呯姸鎬?
        if ($onWaiting || $onHeartbeat) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($curl, $dlTotal, $dlNow, $ulTotal, $ulNow) use (
                $onWaiting, $onHeartbeat, &$hasValidChunk, $startTime, &$lastProgressNotify, $progressInterval
            ) {
                $now = microtime(true);
                $sinceLast = $now - $lastProgressNotify;

                // AI 杩樻病鏈夎繑鍥炴湁鏁堟暟鎹椂锛屾瘡闅?$progressInterval 绉掑彂閫佺瓑寰呴€氱煡
                if (!$hasValidChunk && $sinceLast >= $progressInterval) {
                    $elapsed = (int)($now - $startTime);
                    if ($onWaiting) {
                        $onWaiting($elapsed);
                    }
                    if ($onHeartbeat) {
                        $onHeartbeat();
                    }
                    $lastProgressNotify = $now;
                }
                // AI 宸叉湁鏁版嵁娴佹椂锛屼粛淇濇寔杈冮珮棰戜繚娲伙紝閬垮厤浠ｇ悊绌洪棽瓒呮椂璇柇锛堟瘡 8 绉掞級
                elseif ($hasValidChunk && $onHeartbeat && $sinceLast >= 8) {
                    $onHeartbeat();
                    $lastProgressNotify = $now;
                }

                return 0; // 杩斿洖 0 缁х画浼犺緭
            });
        }

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) use (
            &$fullContent, &$fullReasoning, &$toolCallsAccum, &$finishReason, &$modelName,
            &$rawResponseBuffer, &$hasValidChunk, &$streamLineBuffer, $startTime, $timeout,
            $onReasoning, $onContent, $onHeartbeat
        ) {
            if (strlen($rawResponseBuffer) < 4096) {
                $rawResponseBuffer .= $data;
            }

            // 蹇冭烦锛氭敹鍒颁换浣曟暟鎹兘瑙﹀彂
            if ($onHeartbeat) {
                $onHeartbeat();
            }

            $streamLineBuffer .= $data;
            $lines = explode("\n", $streamLineBuffer);
            $streamLineBuffer = array_pop($lines) ?? '';
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || !str_starts_with($line, 'data: ')) {
                    continue;
                }
                $jsonData = substr($line, 6);
                if ($jsonData === '[DONE]') {
                    continue;
                }

                $chunk = json_decode($jsonData, true);
                if (!is_array($chunk)) {
                    continue;
                }

                $hasValidChunk = true;
                $delta = $chunk['choices'][0]['delta'] ?? [];
                $choiceFinish = $chunk['choices'][0]['finish_reason'] ?? null;
                if ($choiceFinish) {
                    $finishReason = $choiceFinish;
                }
                if (!empty($chunk['model'])) {
                    $modelName = $chunk['model'];
                }

                // 鎺ㄧ悊/鎬濊€冨唴瀹?
                if (!empty($delta['reasoning_content'])) {
                    $fullReasoning .= $delta['reasoning_content'];
                    if ($onReasoning) {
                        $onReasoning($delta['reasoning_content']);
                    }
                }

                // 姝ｆ枃鍐呭
                if (isset($delta['content']) && $delta['content'] !== '') {
                    $fullContent .= $delta['content'];
                    if ($onContent) {
                        $onContent($delta['content']);
                    }
                }

                // tool_calls 澧為噺绱Н
                if (!empty($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $tc) {
                        $idx = $tc['index'] ?? 0;
                        if (!isset($toolCallsAccum[$idx])) {
                            $toolCallsAccum[$idx] = [
                                'id' => $tc['id'] ?? uniqid('tc_'),
                                'name' => $tc['function']['name'] ?? '',
                                'arguments' => '',
                            ];
                        }
                        if (isset($tc['id'])) {
                            $toolCallsAccum[$idx]['id'] = $tc['id'];
                        }
                        if (!empty($tc['function']['name'])) {
                            $toolCallsAccum[$idx]['name'] = $tc['function']['name'];
                        }
                        if (isset($tc['function']['arguments'])) {
                            $toolCallsAccum[$idx]['arguments'] .= $tc['function']['arguments'];
                        }
                    }
                }
            }

            return strlen($data);
        });

        $this->executeCurl($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            if (strpos($error, 'timeout') !== false || strpos($error, 'timed out') !== false) {
                throw new Exception($this->getTimeoutErrorMessage($timeout));
            }
            if ($this->isCurlStreamWriteAbortError($error)) {
                $streamWriteAborted = true;
            } else {
                throw new Exception(__('Stream API request failed: %{error}', ['error' => $error]));
            }
        }
        if (!$streamWriteAborted && $httpCode !== 200) {
            throw new Exception($this->parseApiErrorResponse($rawResponseBuffer, $httpCode));
        }
        if (!$streamWriteAborted && !$hasValidChunk && !empty($rawResponseBuffer)) {
            throw new Exception($this->parseApiErrorResponse($rawResponseBuffer, $httpCode));
        }

        // 鏋勫缓 tool_calls锛堜笌 generate() 鐩稿悓鏍煎紡锛?
        $toolCalls = [];
        foreach ($toolCallsAccum as $tc) {
            $args = $tc['arguments'];
            if (is_string($args)) {
                $args = json_decode($args, true) ?: [];
            }
            $toolCalls[] = [
                'id' => $tc['id'],
                'name' => $tc['name'],
                'arguments' => $args,
            ];
        }

        // 鏋勫缓缁撴灉锛堜笌 generate() 杩斿洖鏍煎紡涓€鑷达級
        $result = [
            'content' => $fullContent,
            'usage' => [
                'prompt_tokens' => $this->estimateTokens(json_encode($requestData['messages'])),
                'completion_tokens' => $this->estimateTokens($fullContent . $fullReasoning),
                'total_tokens' => 0,
            ],
            'model' => $modelName,
            'finish_reason' => $finishReason,
        ];
        $result['usage']['total_tokens'] = $result['usage']['prompt_tokens'] + $result['usage']['completion_tokens'];

        if (!empty($fullReasoning)) {
            $result['reasoning_content'] = $fullReasoning;
        }

        if (!empty($toolCalls)) {
            $result['tool_calls'] = $toolCalls;
            // 鏋勫缓 assistant_message锛堢敤浜庡悗缁秷鎭巻鍙诧級
            $result['assistant_message'] = [
                'role' => 'assistant',
                'content' => $fullContent ?: null,
                'tool_calls' => array_map(fn($tc) => [
                    'id' => $tc['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $tc['name'],
                        'arguments' => json_encode($tc['arguments']),
                    ],
                ], $toolCalls),
            ];
        }

            return $result;
        } finally {
            if ($shouldRestoreExecutionTimeLimit) {
                @set_time_limit(0);
            }
        }
    }

    /**
     * 娴佸紡鐢熸垚
     * 
     * @param AiModel $model
     * @param string $prompt
     * @param callable $callback
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function generateStream(AiModel $model, string $prompt, callable $callback, array $params = []): array
    {
        $config = $model->getConfig();
        
        // 鍚堝苟provider_config锛堜紭鍏堬級
        $providerConfig = $model->getData('provider_config');
        if (!empty($providerConfig)) {
            $providerData = is_string($providerConfig) ? json_decode($providerConfig, true) : $providerConfig;
            if (is_array($providerData)) {
                foreach ($providerData as $k => $v) {
                    if ($v !== '' && $v !== null) {
                        $config[$k] = $v;
                    }
                }
            }
        }
        
        $apiKey = $this->getApiKey($config);
        
        if (empty($apiKey)) {
            throw new Exception(ErrorMessageHelper::getMissingApiKeyMessage());
        }

        $messages = $this->buildMessages($prompt, $params);
        $timeout = $this->resolveStreamTimeout($params, $config);
        
        // 璁剧疆鎵ц鏃堕棿闄愬埗锛圫SE 妯″紡涓嬭烦杩囷紝鐢?SseWriter 缁熶竴绠＄悊涓烘棤闄愬埗锛?
        $shouldRestoreExecutionTimeLimit = !SseContext::isSseEnabled();
        if ($shouldRestoreExecutionTimeLimit) {
            if ($timeout > 0) {
                $timeLimit = $timeout + ProviderTimeoutPolicy::EXECUTION_TIME_BUFFER;
                @set_time_limit($timeLimit);
            } else {
                @set_time_limit(0);
            }
        }
        try {
            $requestData = $this->buildChatCompletionRequestData($model, $config, $messages, $params, true);

        // 鏅鸿兘浣撴ā寮忥細娣诲姞 tools锛坒unction calling锛?
        if (!empty($params['tools']) && is_array($params['tools'])) {
            $requestData['tools'] = $this->convertToolsToOpenAiFormat($params['tools']);
            $requestData['tool_choice'] = $params['tool_choice'] ?? 'auto';
        }

        if (!empty($params['response_format']) && is_array($params['response_format'])) {
            $requestData['response_format'] = $params['response_format'];
        }

        $totalTokens = [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ];

        $fullContent = '';
        
        // 浼樺厛浣跨敤base_url锛屽鏋滄病鏈夊垯浣跨敤api_url锛屾渶鍚庝娇鐢ㄩ粯璁ゅ€?
        $apiUrl = $config['base_url'] ?? $config['api_url'] ?? 'https://api.openai.com/v1';
        if (!str_ends_with($apiUrl, '/chat/completions')) {
            $apiUrl = rtrim($apiUrl, '/') . '/chat/completions';
        }
        
        // 纭繚proxyInfo鏄暟缁勶紙鍙兘瀛樺偍涓?JSON 瀛楃涓诧級
        $proxyInfo = $model->getProxyInfo();
        if (is_string($proxyInfo) && !empty($proxyInfo)) {
            $proxyInfo = json_decode($proxyInfo, true) ?: [];
        }
        if (!is_array($proxyInfo)) {
            $proxyInfo = [];
        }
        
        // 鎺ㄧ悊/鎬濊€冨唴瀹瑰洖璋?
        $reasoningCallback = $params['reasoning_callback'] ?? null;
        $fullReasoning = '';
        $onHeartbeat = \is_callable($params['on_heartbeat'] ?? null) ? $params['on_heartbeat'] : null;

        $this->callStreamApi(
            $apiUrl,
            $apiKey,
            $requestData,
            function($chunk) use ($callback, &$fullContent) {
                $fullContent .= $chunk;
                // CRITICAL-FIX-2026-04-02: Propagate callback return value for SSE abort signal
                return $callback($chunk);
            },
            $proxyInfo,
            $timeout,
            function($chunk) use ($reasoningCallback, &$fullReasoning) {
                $fullReasoning .= $chunk;
                if (\is_callable($reasoningCallback)) {
                    return $reasoningCallback($chunk);
                }

                return true;
            },
            $onHeartbeat
        );

        if (empty(trim($fullContent))) {
            $fallbackContent = $this->resolveReasoningOnlyFallbackContent($fullReasoning, $params, $prompt);
            if ($fallbackContent !== null) {
                $fullContent = $fallbackContent;
            }
        }

        // 濡傛灉娴佸紡璋冪敤娌℃湁杩斿洖浠讳綍鍐呭锛屾姏鍑烘槑纭敊璇?
        if (empty(trim($fullContent))) {
            if (!empty(trim($fullReasoning))) {
                $supplier = \strtolower((string)$model->getSupplier());
                $modelCode = (string)($requestData['model'] ?? $model->getModelCode());
                $protocol = $this->resolveThinkingControlProtocol($model, $requestData);
                throw new Exception(
                    'AI stream returned reasoning_content only without final content.'
                    . ' model=' . $modelCode
                    . ' supplier=' . $supplier
                    . ' thinking_protocol=' . $protocol
                    . ' For structured JSON tasks, disable thinking or increase max_tokens.'
                );
            }
            throw new Exception(__('AI stream completed but returned no content. Please check model configuration (API Key, Base URL, model name).'));
        }

        // 浼扮畻token浣跨敤閲?
        $totalTokens['completion_tokens'] = $this->estimateTokens($fullContent);
        $totalTokens['prompt_tokens'] = $this->estimateTokens($prompt);
        $totalTokens['total_tokens'] = $totalTokens['prompt_tokens'] + $totalTokens['completion_tokens'];

        $result = [
            'content' => $fullContent,
            'usage' => $totalTokens,
        ];

            if (!empty($fullReasoning)) {
                $result['reasoning_content'] = $fullReasoning;
            }

            return $result;
        } finally {
            if ($shouldRestoreExecutionTimeLimit) {
                @set_time_limit(0);
            }
        }
    }

    /**
     * 瑙ｆ瀽 max_tokens锛?
     * - 璋冪敤鏂规樉寮忎紶鍙傛椂锛屼互璋冪敤鏂逛负鍑嗭紙涓氬姟渚у彲鎸夊満鏅彁鍗囬绠楋級
     * - 鏈紶鍙傛椂锛屽洖钀芥ā鍨?閰嶇疆榛樿鍊?
     * - 鏈€缁堝€间笉瓒呰繃妯″瀷鏀寔鐨?max_tokens 涓婇檺
     */
    private function capMaxTokens(AiModel $model, array $config, array $params): int
    {
        $modelMaxTokens = (int)($model->getData(AiModel::schema_fields_MAX_TOKENS) ?? 0);

        if (\array_key_exists('max_tokens', $params)) {
            $requested = \max(1, (int)$params['max_tokens']);
            if ($modelMaxTokens > 0) {
                return \min($requested, $modelMaxTokens);
            }
            return $requested;
        }

        $fallback = (int)($modelMaxTokens ?: ($config['max_tokens'] ?? 2000));
        return \max(1, $fallback);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function buildChatCompletionRequestData(
        AiModel $model,
        array $config,
        array $messages,
        array $params,
        bool $stream
    ): array {
        $requestData = [
            'model' => $config['model'] ?? $model->getModelCode(),
            'messages' => $messages,
            'temperature' => (float)($params['temperature'] ?? $config['temperature'] ?? 0.7),
            'max_tokens' => $this->capMaxTokens($model, $config, $params),
            'stream' => $stream,
        ];

        if (!$stream) {
            $requestData['top_p'] = (float)($params['top_p'] ?? $config['top_p'] ?? 1.0);
            $requestData['frequency_penalty'] = (float)($params['frequency_penalty'] ?? $config['frequency_penalty'] ?? 0.0);
            $requestData['presence_penalty'] = (float)($params['presence_penalty'] ?? $config['presence_penalty'] ?? 0.0);
        }

        if (!empty($params['tools']) && is_array($params['tools'])) {
            $requestData['tools'] = $this->convertToolsToOpenAiFormat($params['tools']);
            $requestData['tool_choice'] = $params['tool_choice'] ?? 'auto';
        }

        if (!empty($params['response_format']) && is_array($params['response_format'])) {
            $requestData['response_format'] = $params['response_format'];
        }

        $this->applyThinkingControls($model, $requestData, \array_replace(
            $this->extractThinkingConfig($config),
            $params
        ));

        return $requestData;
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $params
     */
    private function applyThinkingControls(AiModel $model, array &$requestData, array $params): void
    {
        $thinking = $this->resolveThinkingPayload($params);
        $disableByDefault = $thinking === null && $this->shouldDisableThinkingByDefault($params);
        $effectiveThinking = $thinking ?? ($disableByDefault ? ['type' => 'disabled'] : null);
        $protocol = $this->resolveThinkingControlProtocol($model, $requestData);

        if ($effectiveThinking !== null) {
            if ($protocol === self::THINKING_PROTOCOL_PAYLOAD) {
                $requestData['thinking'] = $effectiveThinking;
            } elseif ($protocol === self::THINKING_PROTOCOL_BOOLEAN) {
                $requestData['enable_thinking'] = $this->thinkingPayloadToEnabledToggle($effectiveThinking);
            }
        }

        $reasoningEffort = \trim((string)($params['reasoning_effort'] ?? ''));
        if (
            $reasoningEffort === ''
            && $protocol === self::THINKING_PROTOCOL_REASONING_EFFORT
            && $this->isThinkingPayloadDisabled($effectiveThinking)
        ) {
            $reasoningEffort = 'minimal';
        }
        if (
            $reasoningEffort !== ''
            && !$this->isThinkingDisabledByRequestParams($params, $requestData)
            && $this->supportsReasoningEffort($model, $requestData)
        ) {
            $requestData['reasoning_effort'] = $reasoningEffort;
        } else {
            unset($requestData['reasoning_effort']);
        }
    }

    /**
     * @param array<string, mixed>|null $thinking
     */
    private function isThinkingPayloadDisabled(?array $thinking): bool
    {
        if ($thinking === null) {
            return false;
        }

        return \strtolower(\trim((string)($thinking['type'] ?? ''))) === 'disabled';
    }

    /**
     * @param array<string, mixed> $thinking
     */
    private function thinkingPayloadToEnabledToggle(array $thinking): bool
    {
        return !$this->isThinkingPayloadDisabled($thinking);
    }

    /**
     * @param array<string, mixed> $requestData
     */
    private function resolveThinkingControlProtocol(AiModel $model, array $requestData): string
    {
        $supplier = \strtolower((string)$model->getSupplier());
        $modelCode = \strtolower((string)($requestData['model'] ?? $model->getModelCode()));

        if ($supplier === 'deepseek' || \str_starts_with($modelCode, 'deepseek-')) {
            return self::THINKING_PROTOCOL_PAYLOAD;
        }
        if (
            \in_array($supplier, ['qwen', 'tongyi', 'aliyun'], true)
            || \str_starts_with($modelCode, 'qwen3')
            || \str_starts_with($modelCode, 'qwen-')
        ) {
            return self::THINKING_PROTOCOL_BOOLEAN;
        }
        if (
            \in_array($supplier, ['glm', 'zhipu'], true)
            || \str_contains($modelCode, 'glm-z1')
        ) {
            return self::THINKING_PROTOCOL_PAYLOAD;
        }
        if (
            $supplier === 'anthropic'
            || \str_starts_with($modelCode, 'claude-')
        ) {
            return self::THINKING_PROTOCOL_PAYLOAD;
        }
        if ($this->isOpenAiReasoningModel($model, $requestData)) {
            return self::THINKING_PROTOCOL_REASONING_EFFORT;
        }

        return self::THINKING_PROTOCOL_NONE;
    }

    /**
     * DeepSeek V4 在 thinking=disabled 时不允许 reasoning_effort。
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $requestData
     */
    private function isThinkingDisabledByRequestParams(array $params, array $requestData): bool
    {
        $thinking = $requestData['thinking'] ?? ($params['thinking'] ?? null);
        if (\is_array($thinking)) {
            $type = \strtolower(\trim((string)($thinking['type'] ?? '')));
            if ($type === 'disabled') {
                return true;
            }
        }

        if (isset($params['enable_thinking']) && $params['enable_thinking'] === false) {
            return true;
        }
        if (isset($params['enable_reasoning']) && $params['enable_reasoning'] === false) {
            return true;
        }
        if (\is_string($params['thinking_mode'] ?? null) && \strtolower(\trim((string)$params['thinking_mode'])) === 'disabled') {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function extractThinkingConfig(array $config): array
    {
        $keys = [
            'thinking',
            'thinking_mode',
            'enable_thinking',
            'enable_reasoning',
            'reasoning_effort',
            'thinking_budget',
            'thinking_budget_tokens',
        ];
        $result = [];
        foreach ($keys as $key) {
            if (\array_key_exists($key, $config)) {
                $result[$key] = $config[$key];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function resolveThinkingPayload(array $params): ?array
    {
        $thinking = $params['thinking'] ?? null;
        if (\is_array($thinking) && \trim((string)($thinking['type'] ?? '')) !== '') {
            return $thinking;
        }

        foreach (['thinking_mode', 'enable_thinking', 'enable_reasoning'] as $key) {
            if (!\array_key_exists($key, $params)) {
                continue;
            }

            return $this->normalizeThinkingModePayload($params[$key], $params);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function normalizeThinkingModePayload(mixed $mode, array $params): ?array
    {
        if (\is_array($mode)) {
            $type = \trim((string)($mode['type'] ?? ''));
            return $type !== '' ? $mode : null;
        }

        $type = $this->normalizeThinkingModeType($mode);
        if ($type === '') {
            return null;
        }

        $payload = ['type' => $type];
        $budgetTokens = (int)($params['thinking_budget_tokens'] ?? $params['thinking_budget'] ?? 0);
        if ($type === 'enabled' && $budgetTokens > 0) {
            $payload['budget_tokens'] = $budgetTokens;
        }

        return $payload;
    }

    private function normalizeThinkingModeType(mixed $mode): string
    {
        if (\is_bool($mode)) {
            return $mode ? 'enabled' : 'disabled';
        }

        $normalized = \strtolower(\trim((string)$mode));
        if (\in_array($normalized, ['1', 'true', 'yes', 'on', 'enable', 'enabled', 'auto'], true)) {
            return 'enabled';
        }
        if (\in_array($normalized, ['0', 'false', 'no', 'off', 'disable', 'disabled', 'none'], true)) {
            return 'disabled';
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $requestData
     */
    private function isDeepSeekV4Model(AiModel $model, array $requestData): bool
    {
        if (\strtolower($model->getSupplier()) !== 'deepseek') {
            return false;
        }

        $modelCode = \strtolower((string)($requestData['model'] ?? $model->getModelCode()));
        return \str_starts_with($modelCode, 'deepseek-v4-')
            || \str_starts_with($modelCode, 'deepseek-chat')
            || \str_starts_with($modelCode, 'deepseek-reasoner');
    }

    /**
     * 模型只返回 reasoning_content（思维链）而没有最终 content 时的兜底逻辑：
     *  - response_format=json_object 时直接尝试从 reasoning 中提取 JSON 对象
     *  - 即便未显式要求 json_object，只要 prompt 里明显写了"JSON / 返回 JSON / json_object"
     *    等结构化要求，也尝试按 JSON 兜底；这能覆盖 ContentGenerationAdapter / 类似 Adapter
     *    在 prompt 末尾追加"必须返回有效的JSON格式数据"的场景，避免 deepseek-v4-pro 等模型
     *    在 thinking 协议下"只产出思维链"导致前端"无响应"。
     *
     * @param array<string, mixed> $params
     */
    private function resolveReasoningOnlyFallbackContent(string $reasoningContent, array $params, string $prompt = ''): ?string
    {
        if (\trim($reasoningContent) === '') {
            return null;
        }

        if (!$this->shouldAttemptReasoningJsonFallback($params, $prompt)) {
            return null;
        }

        return $this->extractJsonObjectFromReasoningContent($reasoningContent);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function shouldAttemptReasoningJsonFallback(array $params, string $prompt): bool
    {
        $responseFormat = $params['response_format'] ?? null;
        if (
            \is_array($responseFormat)
            && \strtolower(\trim((string)($responseFormat['type'] ?? ''))) === 'json_object'
        ) {
            return true;
        }

        $promptLower = \strtolower($prompt);
        if ($promptLower === '') {
            return false;
        }

        // 命中明显的 JSON 格式约束才放行兜底，避免对纯文本任务做 JSON 误提取。
        return \str_contains($promptLower, 'json_object')
            || \str_contains($promptLower, 'response_format')
            || \str_contains($promptLower, 'return json')
            || \str_contains($promptLower, 'return a json')
            || \str_contains($promptLower, 'valid json')
            || \str_contains($promptLower, '返回json')
            || \str_contains($promptLower, '返回 json')
            || \str_contains($promptLower, '必须返回有效的json')
            || \str_contains($promptLower, '必须返回有效的 json')
            || \str_contains($promptLower, 'json格式数据')
            || \str_contains($promptLower, 'json 格式数据');
    }

    private function extractJsonObjectFromReasoningContent(string $reasoningContent): ?string
    {
        $direct = $this->normalizeJsonObjectCandidate($reasoningContent);
        if ($direct !== null) {
            return $direct;
        }

        if (\preg_match_all('/```(?:json)?\s*([\s\S]*?)\s*```/i', $reasoningContent, $matches)) {
            foreach ($matches[1] as $candidate) {
                $normalized = $this->normalizeJsonObjectCandidate((string)$candidate);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        $start = \strpos($reasoningContent, '{');
        $end = \strrpos($reasoningContent, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $candidate = \substr($reasoningContent, $start, $end - $start + 1);
        return $this->normalizeJsonObjectCandidate($candidate === false ? '' : $candidate);
    }

    private function normalizeJsonObjectCandidate(string $candidate): ?string
    {
        $trimmed = \trim($candidate);
        if ($trimmed === '' || !\str_starts_with($trimmed, '{')) {
            return null;
        }

        $decoded = \json_decode($trimmed, true);
        if (!\is_array($decoded) || \json_last_error() !== \JSON_ERROR_NONE || \array_is_list($decoded)) {
            return null;
        }

        return $trimmed;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function shouldDisableThinkingByDefault(array $params): bool
    {
        if (
            \array_key_exists('thinking', $params)
            || \array_key_exists('thinking_mode', $params)
            || \array_key_exists('enable_thinking', $params)
            || \array_key_exists('enable_reasoning', $params)
            || \array_key_exists('reasoning_effort', $params)
        ) {
            return false;
        }

        $responseFormat = $params['response_format'] ?? null;
        return \is_array($responseFormat)
            && \strtolower(\trim((string)($responseFormat['type'] ?? ''))) === 'json_object';
    }

    /**
     * @param array<string, mixed> $requestData
     */
    private function supportsReasoningEffort(AiModel $model, array $requestData): bool
    {
        if ($this->isDeepSeekV4Model($model, $requestData)) {
            return true;
        }

        return $this->isOpenAiReasoningModel($model, $requestData);
    }

    /**
     * @param array<string, mixed> $requestData
     */
    private function isOpenAiReasoningModel(AiModel $model, array $requestData): bool
    {
        $supplier = \strtolower((string)$model->getSupplier());
        $modelCode = \strtolower((string)($requestData['model'] ?? $model->getModelCode()));
        if (!\in_array($supplier, ['openai', ''], true) && !\str_contains($modelCode, 'openai')) {
            return false;
        }

        // o-series 推理模型
        if (
            \str_starts_with($modelCode, 'o1-')
            || \str_starts_with($modelCode, 'o3-')
            || \str_starts_with($modelCode, 'o4-')
        ) {
            return true;
        }

        // GPT-5 / GPT-5.x 推理模型族（含 GPT-5.5、GPT-5.5 Pro、GPT-5.3-Codex 等）
        if ($modelCode === 'gpt-5' || \str_starts_with($modelCode, 'gpt-5-') || \str_starts_with($modelCode, 'gpt-5.')) {
            return true;
        }

        // Codex 系列编码模型显式纳入
        if (\str_contains($modelCode, 'codex')) {
            return true;
        }

        return false;
    }

    /**
     * 鏋勫缓娑堟伅鏁扮粍
     * 
     * @param string $prompt
     * @param array $params
     * @return array
     */
    private function buildMessages(string $prompt, array $params): array
    {
        // 鏅鸿兘浣撴ā寮忥細鐩存帴浣跨敤瀹屾暣娑堟伅鍘嗗彶
        if (!empty($params['messages']) && is_array($params['messages'])) {
            return $this->ensureJsonResponseFormatMention($params['messages'], $params);
        }

        $messages = [];

        // 绯荤粺娑堟伅
        if (!empty($params['system_message'])) {
            $messages[] = [
                'role' => 'system',
                'content' => $params['system_message']
            ];
        }

        // 鍘嗗彶瀵硅瘽
        if (!empty($params['history']) && is_array($params['history'])) {
            $messages = array_merge($messages, $params['history']);
        }

        // 鐢ㄦ埛娑堟伅
        if (!empty($prompt)) {
            $messages[] = [
                'role' => 'user',
                'content' => $prompt
            ];
        }

        return $this->ensureJsonResponseFormatMention($messages, $params);
    }

    private function ensureJsonResponseFormatMention(array $messages, array $params): array
    {
        if (!$this->requiresJsonObjectResponseFormat($params) || $this->messagesMentionJson($messages)) {
            return $messages;
        }

        $instruction = 'Return the response as valid JSON.';
        foreach ($messages as $index => $message) {
            if (($message['role'] ?? '') !== 'system') {
                continue;
            }
            $messages[$index]['content'] = \rtrim((string)($message['content'] ?? '')) . "\n\n" . $instruction;
            return $messages;
        }

        \array_unshift($messages, [
            'role' => 'system',
            'content' => $instruction,
        ]);

        return $messages;
    }

    private function requiresJsonObjectResponseFormat(array $params): bool
    {
        return \is_array($params['response_format'] ?? null)
            && \strtolower((string)($params['response_format']['type'] ?? '')) === 'json_object';
    }

    private function messagesMentionJson(array $messages): bool
    {
        foreach ($messages as $message) {
            $content = $message['content'] ?? '';
            if (\is_string($content) && \stripos($content, 'json') !== false) {
                return true;
            }
            if (\is_array($content) && $this->messagePartsMentionJson($content)) {
                return true;
            }
        }

        return false;
    }

    private function messagePartsMentionJson(array $parts): bool
    {
        foreach ($parts as $part) {
            if (\is_string($part) && \stripos($part, 'json') !== false) {
                return true;
            }
            if (!\is_array($part)) {
                continue;
            }
            foreach (['text', 'content'] as $key) {
                if (isset($part[$key]) && \is_string($part[$key]) && \stripos($part[$key], 'json') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 灏嗘鏋朵腑闂存牸寮忕殑 Tool 瀹氫箟杞崲涓?OpenAI function calling 鏍煎紡
     * 
     * 妗嗘灦鏍煎紡锛歔['name' => '...', 'description' => '...', 'parameters' => [...]]]
     * OpenAI 鏍煎紡锛歔['type' => 'function', 'function' => ['name' => '...', 'description' => '...', 'parameters' => [...]]]]
     * 
     * @param array $tools 妗嗘灦涓棿鏍煎紡鐨?Tool 瀹氫箟
     * @return array OpenAI 鏍煎紡
     */
    private function convertToolsToOpenAiFormat(array $tools): array
    {
        $openAiTools = [];
        foreach ($tools as $tool) {
            $openAiTools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                ],
            ];
        }
        return $openAiTools;
    }

    /**
     * 浠?OpenAI 鍝嶅簲涓彁鍙?tool_calls
     * 
     * @param array $response OpenAI API 鍝嶅簲
     * @return array 鏍囧噯鍖栫殑 tool_calls 鏁扮粍
     */
    private function extractToolCalls(array $response): array
    {
        $toolCalls = [];
        $message = $response['choices'][0]['message'] ?? [];

        if (!empty($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $tc) {
                $arguments = $tc['function']['arguments'] ?? '{}';
                if (is_string($arguments)) {
                    $arguments = json_decode($arguments, true) ?: [];
                }
                $toolCalls[] = [
                    'id' => $tc['id'] ?? uniqid('tc_'),
                    'name' => $tc['function']['name'] ?? '',
                    'arguments' => $arguments,
                ];
            }
        }

        return $toolCalls;
    }

    /**
     * 甯﹂噸璇曠殑API璋冪敤
     * 
     * @param string $url
     * @param string $apiKey
     * @param array $data
     * @param array $proxyInfo
     * @param int $timeout
     * @param int $retryCount
     * @return array
     * @throws Exception
     */
    private function callApiWithRetry(string $url, string $apiKey, array $data, array $proxyInfo, int $timeout, int $retryCount = 0): array
    {
        // SSE 妯″紡涓嬩笉鍋?PHP 灞傞潰鐨勮秴鏃舵娴?
        $isSseMode = SseContext::isSseEnabled();
        
        $startTime = microtime(true);
        $maxExecutionTime = $isSseMode ? 0 : (int)ini_get('max_execution_time');
        $timeLimit = $maxExecutionTime > 0 ? $maxExecutionTime : null;
        
        try {
            $ch = $this->initCurl($url, $apiKey, $data, $proxyInfo, $timeout);
            
            // 鍦ㄦ墽琛屽墠妫€鏌ュ墿浣欐椂闂达紝濡傛灉鏃堕棿涓嶈冻锛屾彁鍓嶆姏鍑洪敊璇?
            if ($timeLimit !== null && $timeLimit > 0) {
                $elapsedBeforeRequest = microtime(true) - $startTime;
                $remainingTime = $timeLimit - $elapsedBeforeRequest;
                // 濡傛灉鍓╀綑鏃堕棿灏戜簬5绉掞紝鎻愬墠鎶涘嚭閿欒
                if ($remainingTime < 5) {
                    throw new Exception($this->getTimeoutErrorMessage($timeout));
                }
            }
            
            // 娓呴櫎涔嬪墠鐨勯敊璇?
            error_clear_last();
            
            $response = $this->executeCurl($ch);
            
            // 妫€鏌?PHP 瓒呮椂锛圫SE 妯″紡涓嬭烦杩囷級
            if (!$isSseMode) {
                $lastError = error_get_last();
                if ($lastError && (
                    strpos($lastError['message'], 'Maximum execution time') !== false ||
                    strpos($lastError['message'], 'exceeded') !== false
                )) {
                    throw new Exception($this->getTimeoutErrorMessage($timeout));
                }
                
                // 妫€鏌ユ墽琛屾椂闂存槸鍚︽帴杩戦檺鍒?
                $elapsedTime = microtime(true) - $startTime;
                if ($timeLimit !== null && $elapsedTime >= ($timeLimit - 2)) {
                    throw new Exception($this->getTimeoutErrorMessage($timeout));
                }
            }
            
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if ($response === false) {
                // 妫€鏌ユ槸鍚︽槸瓒呮椂閿欒
                if (strpos($error, 'timeout') !== false || strpos($error, 'timed out') !== false) {
                    throw new Exception($this->getTimeoutErrorMessage($timeout));
                }
                throw new Exception(__('API request failed: %{error}', ['error' => $error]));
            }

            $result = json_decode($response, true);
            
            if ($httpCode >= 500 && $retryCount < self::MAX_RETRIES) {
                // 鏈嶅姟鍣ㄩ敊璇紝閲嶈瘯
                SchedulerSystem::sleep(self::RETRY_DELAY * ($retryCount + 1));
                return $this->callApiWithRetry($url, $apiKey, $data, $proxyInfo, $timeout, $retryCount + 1);
            }

            if ($httpCode !== 200) {
                $errorMsg = $result['error']['message'] ?? __('HTTP error: %{code}', ['code' => $httpCode]);
                if ($this->isNonRetryableApiError($httpCode, (string)$errorMsg)) {
                    throw new Exception(__('API returned a non-retryable error: %{error}', ['error' => $errorMsg]));
                }
                throw new Exception(__('API returned an error: %{error}', ['error' => $errorMsg]));
            }

            if (!isset($result['choices'][0]['message']['content'])) {
                throw new Exception(__('Invalid API response format.'));
            }

            return $result;

        } catch (\Exception $e) {
            // 濡傛灉鏄秴鏃堕敊璇紝鐩存帴鎶涘嚭锛屼笉閲嶈瘯
            if (strpos($e->getMessage(), 'timed out') !== false ||
                strpos($e->getMessage(), 'execution time') !== false) {
                throw $e;
            }
            if ($this->isNonRetryableApiErrorMessage($e->getMessage())) {
                throw $e;
            }
            
            if ($retryCount < self::MAX_RETRIES) {
                SchedulerSystem::sleep(self::RETRY_DELAY * ($retryCount + 1));
                return $this->callApiWithRetry($url, $apiKey, $data, $proxyInfo, $timeout, $retryCount + 1);
            }
            throw new Exception(__('API request failed after %{count} retries: %{msg}', [
                'count' => $retryCount,
                'msg' => $e->getMessage()
            ]));
        }
    }

    private function isNonRetryableApiError(int $httpCode, string $errorMessage): bool
    {
        if (\in_array($httpCode, [400, 401, 402, 403], true)) {
            return true;
        }
        return $this->isNonRetryableApiErrorMessage($errorMessage);
    }

    private function isNonRetryableApiErrorMessage(string $message): bool
    {
        $normalized = \mb_strtolower(\trim($message));
        if ($normalized === '') {
            return false;
        }
        foreach ([
            'authentication fails',
            'invalid api key',
            'unauthorized',
            'permission denied',
            'insufficient balance',
            'balance not enough',
            '余额不足',
            'insufficient_quota',
            'quota exceeded',
            'exceeded your current quota',
            'current quota',
            'insufficient quota',
            'invalid_request_error',
            'model not found',
        ] as $keyword) {
            if (\str_contains($normalized, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 娴佸紡API璋冪敤
     * 
     * @param string $url
     * @param string $apiKey
     * @param array $data
     * @param callable $callback
     * @param array $proxyInfo
     * @param int $timeout
     * @throws Exception
     */
    private function callStreamApi(
        string $url,
        string $apiKey,
        array $data,
        callable $callback,
        array $proxyInfo,
        int $timeout,
        ?callable $reasoningCallback = null,
        ?callable $onHeartbeat = null
    ): void {

        // CLI / queue 内并发场景：FiberTaskRunner 已激活 cURL multi pump，走真并发流式分支。

        $pump = FiberTaskRunner::currentPump();

        if ($pump instanceof CurlStreamPump

            && \class_exists(\Fiber::class)

            && \Fiber::getCurrent() !== null

            && SchedulerSystem::isSchedulerActive()

        ) {

            $this->callStreamApiViaPump(

                $pump,

                $url,

                $apiKey,

                $data,

                $callback,

                $proxyInfo,

                $timeout,

                $reasoningCallback,

                $onHeartbeat

            );

            return;

        }



        // SSE 模式下不做 PHP 层面超时检测，由 SseWriter 与 curl 自身控制。

        $isSseMode = SseContext::isSseEnabled();



        $startTime = microtime(true);

        $maxExecutionTime = $isSseMode ? 0 : (int)ini_get('max_execution_time');

        $timeLimit = $maxExecutionTime > 0 ? $maxExecutionTime : null;



        $ch = $this->initCurl($url, $apiKey, $data, $proxyInfo, $timeout, true);



        if ($timeLimit !== null && $timeLimit > 0) {

            $elapsedBeforeRequest = microtime(true) - $startTime;

            $remainingTime = $timeLimit - $elapsedBeforeRequest;

            if ($remainingTime < 5) {

                throw new Exception($this->getTimeoutErrorMessage($timeout));

            }

        }



        error_clear_last();



        $parser = new OpenAiStreamParser();

        // on_heartbeat 仅允许「轻量保活」（如 SseWriter::sendComment），禁止在进度回调里 sendEvent。

        $streamKeepaliveIntervalSec = 5.0;

        $lastStreamKeepaliveAt = microtime(true);

        if ($onHeartbeat !== null) {

            try {

                $onHeartbeat();

                $lastStreamKeepaliveAt = microtime(true);

            } catch (\Throwable) {

            }

            curl_setopt($ch, CURLOPT_NOPROGRESS, false);

            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function (

                $curl,

                $dlTotal,

                $dlNow,

                $ulTotal,

                $ulNow

            ) use ($onHeartbeat, &$lastStreamKeepaliveAt, $streamKeepaliveIntervalSec) {

                $now = microtime(true);

                if (($now - $lastStreamKeepaliveAt) < $streamKeepaliveIntervalSec) {

                    return 0;

                }

                $lastStreamKeepaliveAt = $now;

                try {

                    $onHeartbeat();

                } catch (\Throwable) {

                }



                return 0;

            });

        }



        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) use (

            $callback,

            $reasoningCallback,

            $startTime,

            $timeLimit,

            $parser,

            $onHeartbeat,

            &$lastStreamKeepaliveAt,

            $streamKeepaliveIntervalSec

        ) {

            if ($timeLimit !== null) {

                $elapsedTime = microtime(true) - $startTime;

                if ($elapsedTime >= ($timeLimit - 2)) {

                    return -1;

                }

            }



            if ($onHeartbeat !== null) {

                $nowHb = microtime(true);

                if (($nowHb - $lastStreamKeepaliveAt) >= $streamKeepaliveIntervalSec) {

                    $lastStreamKeepaliveAt = $nowHb;

                    try {

                        $onHeartbeat();

                    } catch (\Throwable) {

                    }

                }

            }



            if (!$parser->ingest($data, $callback, $reasoningCallback)) {

                // CRITICAL-FIX-2026-04-02: Abort curl on SSE disconnect (return -1 stops CURLOPT_WRITEFUNCTION).

                return -1;

            }



            if (SchedulerSystem::isSchedulerActive() && \Fiber::getCurrent()) {

                SchedulerSystem::yieldDelay(1);

            }



            return strlen($data);

        });



        $this->executeCurl($ch);



        if (!$parser->flushTail($callback, $reasoningCallback)) {

            curl_close($ch);

            throw new Exception(__('SSE stream aborted by callback'));

        }



        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);



        if (!$isSseMode) {

            $lastError = error_get_last();

            if ($lastError && (

                strpos($lastError['message'], 'Maximum execution time') !== false ||

                strpos($lastError['message'], 'exceeded') !== false

            )) {

                curl_close($ch);

                throw new Exception($this->getTimeoutErrorMessage($timeout));

            }

        }



        $error = curl_error($ch);

        curl_close($ch);



        $this->validateStreamOutcome($parser, (int)$httpCode, (string)$error, $timeout);

    }



    /**

     * 在 FiberTaskRunner 的 cURL multi pump 上执行流式调用：N 路并行不会互相阻塞。

     * 仅用于 CLI / 系统调用触发（queue:run 等）的并发批；浏览器在线 SSE 仍走 curl_exec 路径。

     */

    private function callStreamApiViaPump(

        CurlStreamPump $pump,

        string $url,

        string $apiKey,

        array $data,

        callable $callback,

        array $proxyInfo,

        int $timeout,

        ?callable $reasoningCallback = null,

        ?callable $onHeartbeat = null

    ): void {

        $ch = $this->initCurl($url, $apiKey, $data, $proxyInfo, $timeout, true);

        $parser = new OpenAiStreamParser();

        $hid = $pump->register($ch);



        $aborted = false;

        $heartbeatIntervalSec = 5.0;

        $lastHeartbeatAt = microtime(true);

        if ($onHeartbeat !== null) {

            try {

                $onHeartbeat();

                $lastHeartbeatAt = microtime(true);

            } catch (\Throwable) {

            }

        }



        try {

            while (($chunk = $pump->awaitChunk($hid)) !== null) {

                if ($onHeartbeat !== null) {

                    $now = microtime(true);

                    if (($now - $lastHeartbeatAt) >= $heartbeatIntervalSec) {

                        $lastHeartbeatAt = $now;

                        try {

                            $onHeartbeat();

                        } catch (\Throwable) {

                        }

                    }

                }



                if (!$parser->ingest($chunk, $callback, $reasoningCallback)) {

                    $pump->abort($hid);

                    $aborted = true;

                    break;

                }

            }



            if (!$aborted && !$parser->flushTail($callback, $reasoningCallback)) {

                $pump->abort($hid);

                $aborted = true;

            }



            $info = $pump->finalize($hid);

            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);



            if ($aborted) {

                return;

            }



            $error = $info['ok'] ? '' : (string)$info['error'];

            $this->validateStreamOutcome($parser, $httpCode, $error, $timeout);

        } catch (\Throwable $throwable) {

            try {

                $pump->abort($hid);

            } catch (\Throwable) {

            }

            if ($ch instanceof \CurlHandle) {

                @curl_close($ch);

            }

            throw $throwable;

        }

    }



    /**

     * 流式调用结束后的统一校验：HTTP / curl 错误 → 抛；解析器内部状态 → 抛或忽略。

     * 抽离自原 callStreamApi 的尾段，使新旧两条路径共享一致的失败语义。

     */

    private function validateStreamOutcome(

        OpenAiStreamParser $parser,

        int $httpCode,

        string $error,

        int $timeout

    ): void {

        $streamWriteAborted = false;

        if ($error !== '') {

            if (strpos($error, 'timeout') !== false || strpos($error, 'timed out') !== false) {

                throw new Exception($this->getTimeoutErrorMessage($timeout));

            }

            if ($this->isCurlStreamWriteAbortError($error)) {

                $streamWriteAborted = true;

            } else {

                throw new Exception(__('Stream API request failed: %{error}', ['error' => $error]));

            }

        }



        $rawResponse = $parser->rawResponseSnapshot();



        if (!$streamWriteAborted && $httpCode !== 200 && $httpCode !== 0) {

            throw new Exception($this->parseApiErrorResponse($rawResponse, $httpCode));

        }



        if (!$streamWriteAborted && !$parser->hasValidChunk() && $rawResponse !== '') {

            throw new Exception($this->parseApiErrorResponse($rawResponse, $httpCode));

        }



        if (!$streamWriteAborted && $parser->hasValidChunk() && !$parser->streamTerminatedNormally()) {

            $tailPreview = mb_substr(trim($parser->tailLineBuffer()), 0, 180);

            throw new Exception(

                'AI upstream stream terminated before the [DONE] marker.'

                . ($tailPreview !== '' ? " tail={$tailPreview}" : '')

            );

        }

    }

    
    /**
     * 瑙ｆ瀽 API 閿欒鍝嶅簲锛屾彁鍙栧彲璇荤殑閿欒淇℃伅
     */
    private function parseApiErrorResponse(string $rawResponse, int $httpCode): string
    {
        $trimmed = trim($rawResponse);
        
        // 灏濊瘯瑙ｆ瀽 JSON 閿欒鍝嶅簲
        if (!empty($trimmed)) {
            $errorData = json_decode($trimmed, true);
            if (is_array($errorData)) {
                // OpenAI 鏍煎紡: {"error": {"message": "...", "type": "..."}}
                if (isset($errorData['error']['message'])) {
                    $errType = $errorData['error']['type'] ?? 'api_error';
                    return "AI API error (HTTP {$httpCode}, {$errType}): " . $errorData['error']['message'];
                }
                // 鍏朵粬鏍煎紡: {"message": "..."} 鎴?{"detail": "..."}
                if (isset($errorData['message'])) {
                    return "AI API error (HTTP {$httpCode}): " . $errorData['message'];
                }
                if (isset($errorData['detail'])) {
                    return "AI API error (HTTP {$httpCode}): " . $errorData['detail'];
                }
            }
        }
        
        // HTTP 鐘舵€佺爜鍙嬪ソ鎻愮ず
        $statusMessages = [
            401 => 'Invalid or expired API key. Check the AI model API Key configuration.',
            403 => 'API access was denied. Check account permissions.',
            404 => 'API endpoint was not found. Check the Base URL configuration.',
            429 => 'API rate limit or quota was exceeded. Try again later.',
            500 => 'AI service internal error. Try again later.',
            502 => 'AI service gateway error. Try again later.',
            503 => 'AI service is temporarily unavailable. Try again later.',
        ];
        
        if (isset($statusMessages[$httpCode])) {
            return "AI API error (HTTP {$httpCode}): " . $statusMessages[$httpCode];
        }
        
        if ($httpCode > 0) {
            $preview = mb_substr($trimmed, 0, 200);
            return "AI API returned an unexpected response (HTTP {$httpCode})" . ($preview ? ", response: {$preview}" : '');
        }
        
        return "No response from AI API. Check network connectivity and Base URL configuration.";
    }

    /**
     * 鍒濆鍖朇URL
     * 
     * @param string $url
     * @param string $apiKey
     * @param array $data
     * @param array $proxyInfo
     * @return \CurlHandle|false
     */
    private function initCurl(
        string $url,
        string $apiKey,
        array $data,
        array $proxyInfo,
        int $timeout,
        bool $isStream = false
    ): \CurlHandle|false
    {
        $ch = curl_init($url);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ]);
        foreach ($this->buildCurlTimeoutOptions($timeout, $isStream) as $option => $value) {
            curl_setopt($ch, $option, $value);
        }
        
        // SSL閰嶇疆锛氬湪Windows鏈湴寮€鍙戠幆澧冧腑锛屽彲鑳介渶瑕佽烦杩嘢SL楠岃瘉
        // 鐢熶骇鐜寤鸿閰嶇疆姝ｇ‘鐨凜A璇佷功鍖?
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        if ($isWindows) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            // Windows 鍙屾爤缃戠粶鍙兘瀵艰嚧 IPv6 瓒呮椂锛屽己鍒朵娇鐢?IPv4
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        }

        // 浠ｇ悊閰嶇疆
        if (!empty($proxyInfo['enabled'])) {
            $proxy = $proxyInfo['host'] . ':' . $proxyInfo['port'];
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            
            if (!empty($proxyInfo['username']) && !empty($proxyInfo['password'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyInfo['username'] . ':' . $proxyInfo['password']);
            }
        }

        return $ch;
    }

    private function resolveStreamTimeout(array $params, array $config): int
    {
        return ProviderTimeoutPolicy::resolveStreamTimeout($params, $config);
    }

    /**
     * @return array<int, int>
     */
    private function buildCurlTimeoutOptions(int $timeout, bool $isStream = false): array
    {
        $timeout = max(0, (int)$timeout);

        if ($isStream) {
            return [
                CURLOPT_TIMEOUT => 0,
                CURLOPT_CONNECTTIMEOUT => ProviderTimeoutPolicy::DEFAULT_CONNECT_TIMEOUT,
                // 娴佸紡闀胯繛鎺ュ湪鎺ㄧ悊闃舵鍙兘闀挎椂闂翠笉鍑?chunk锛屼笉鑳界敤浣庨€熶繚鎶ゆ妸瀹冭鍒ゆ垚鎸傛銆?
                CURLOPT_LOW_SPEED_LIMIT => 0,
                CURLOPT_LOW_SPEED_TIME => 0,
            ];
        }

        $options = [
            CURLOPT_TIMEOUT => $timeout,
        ];

        if ($timeout > 0) {
            $options[CURLOPT_CONNECTTIMEOUT] = min($timeout, ProviderTimeoutPolicy::DEFAULT_CONNECT_TIMEOUT);
        } else {
            $options[CURLOPT_CONNECTTIMEOUT] = ProviderTimeoutPolicy::DEFAULT_CONNECT_TIMEOUT;
            $options[CURLOPT_LOW_SPEED_LIMIT] = 1;
            $options[CURLOPT_LOW_SPEED_TIME] = 120;
        }

        return $options;
    }










    private function executeCurl(\CurlHandle $ch): string|bool
    {
        if (!SchedulerSystem::isSchedulerActive() || !\Fiber::getCurrent()) {
            return curl_exec($ch);
        }

        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $ch);

        $running = 0;
        $multiResult = \CURLM_OK;
        $curlResult = \CURLE_OK;

        do {
            do {
                $multiResult = curl_multi_exec($multi, $running);
            } while ($multiResult === \CURLM_CALL_MULTI_PERFORM);

            while ($info = curl_multi_info_read($multi)) {
                if (($info['handle'] ?? null) === $ch) {
                    $curlResult = (int)($info['result'] ?? \CURLE_OK);
                }
            }

            if ($multiResult !== \CURLM_OK || $curlResult !== \CURLE_OK) {
                break;
            }

            if ($running > 0) {
                SchedulerSystem::yieldDelay(10);
            }
        } while ($running > 0);

        $content = curl_multi_getcontent($ch);
        curl_multi_remove_handle($multi, $ch);
        curl_multi_close($multi);

        if ($multiResult !== \CURLM_OK || $curlResult !== \CURLE_OK) {
            return false;
        }

        return $content === null ? false : $content;
    }

    /**
     * 鑾峰彇API瀵嗛挜
     * 
     * @param array $config
     * @return string
     */
    private function getApiKey(array $config): string
    {
        // 浼樺厛浣跨敤鐜鍙橀噺
        if (!empty($config['api_key_env'])) {
            $envKey = getenv($config['api_key_env']);
            if ($envKey) {
                return $this->normalizeApiKey($envKey);
            }
        }

        // 浣跨敤閰嶇疆涓殑瀵嗛挜
        return $this->normalizeApiKey((string)($config['api_key'] ?? ''));
    }

    private function normalizeApiKey(string $apiKey): string
    {
        $apiKey = trim($apiKey);

        // Repair the duplicated-prefix corruption observed in existing OpenAI-compatible model self-configs.
        if (str_starts_with($apiKey, 'ssk-')) {
            return 'sk-' . substr($apiKey, 4);
        }

        return $apiKey;
    }

    /**
     * 浼扮畻token鏁伴噺
     * 
     * @param string $text
     * @return int
     */
    private function estimateTokens(string $text): int
    {
        // 绠€鍗曚及绠楋細鑻辨枃绾?瀛楃=1token锛屼腑鏂囩害1.5瀛楃=1token
        $englishChars = preg_match_all('/[a-zA-Z0-9\s]/', $text);
        $otherChars = mb_strlen($text) - $englishChars;
        
        return (int)ceil($englishChars / 4 + $otherChars / 1.5);
    }

    /**
     * 妫€鏌ユā鍨嬫敮鎸?
     * 
     * @param string $modelCode
     * @return bool
     */
    public function supports(string $modelCode): bool
    {
        // 鏀寔OpenAI鍜屽吋瀹筄penAI API鐨勬ā鍨嬶紙濡侱eepSeek绛夛紝浣嗕笉鍖呮嫭Claude锛孋laude浣跨敤AnthropicProvider锛?
        return str_contains($modelCode, 'gpt') 
            || str_contains($modelCode, 'openai') 
            || str_contains($modelCode, 'deepseek')
            || str_contains($modelCode, 'gemini')
            || str_contains($modelCode, 'kimi')
            || str_contains($modelCode, 'moonshot')
            || str_starts_with($modelCode, 'o1-')
            || str_starts_with($modelCode, 'o3-')
            || str_starts_with($modelCode, 'o4-');
    }

    /**
     * 鑾峰彇渚涘簲鍟嗕唬鐮?
     * 
     * @return string
     */
    public function getProviderCode(): string
    {
        return 'openai';
    }

    /**
     * 鑾峰彇璇ヤ緵搴斿晢鏀寔鐨勬ā鍨嬪垪琛?
     * 
     * @return array
     */
    public function getSupportedModels(): array
    {
        return VendorConfigManager::getProviderModels($this->getProviderCode());
    }

    /**
     * 鑾峰彇瓒呮椂閿欒娑堟伅
     * 
     * @param int $timeout 瓒呮椂鏃堕棿锛堢锛?
     * @return string 鍙嬪ソ鐨勯敊璇秷鎭紙绾枃鏈紝涓嶅寘鍚獺TML鏍囩锛?
     */
    private function getTimeoutErrorMessage(int $timeout): string
    {
        $message = "";
        if ($timeout > 0) {
            $message = sprintf(
                "AI request timed out after %d seconds. Check network connectivity, upstream AI response time, and request complexity.",
                $timeout
            );
        } else {
            $message = "AI request timed out. Check network connectivity, upstream AI response time, and request complexity.";
        }

        $message = strip_tags($message);
        $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $message = trim(preg_replace('/\s+/', ' ', $message));

        return $message;
    }




}


<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Provider\Stream;

/**
 * OpenAI Chat Completions 流式响应解析器（SSE 风格）。
 *
 * 设计目标：
 * - 与 HTTP / cURL 解耦：只接收 raw 字节切片，不关心来自 WRITEFUNCTION 还是 {@see \Weline\Framework\Php\CurlStreamPump}。
 * - 单一职责：把 `data: {json}\n\n` 帧切分、JSON 解析、`[DONE]` 标记识别、`delta.content` /
 *   `delta.reasoning_content` 派发集中在一处。
 * - 抗碎片：上游可能在任意位置 flush（半行、跨行），通过 `lineBuffer` 累积；最后一段 flushTail。
 * - 抗中断：业务回调返回 false 时立即停止后续派发，并把"应中断"信号回吐给调用方。
 */
final class OpenAiStreamParser
{
    private string $lineBuffer = '';

    private string $rawResponse = '';

    private bool $hasValidChunk = false;

    private bool $streamTerminated = false;

    public function __construct(
        private readonly int $rawBufferLimit = 4096,
    ) {
    }

    /**
     * 喂入一段原始字节。返回 false 表示业务方请求中断（callback 返回 false）。
     */
    public function ingest(string $data, callable $onContent, ?callable $onReasoning = null): bool
    {
        if ($data === '') {
            return true;
        }

        if (\strlen($this->rawResponse) < $this->rawBufferLimit) {
            $remaining = $this->rawBufferLimit - \strlen($this->rawResponse);
            $this->rawResponse .= \substr($data, 0, $remaining);
        }

        $this->lineBuffer .= $data;
        $lines = \explode("\n", $this->lineBuffer);
        $this->lineBuffer = (string)\array_pop($lines);

        foreach ($lines as $line) {
            if (!$this->processLine(\trim($line), $onContent, $onReasoning)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 处理上游断流时残留在 lineBuffer 里的最后一段（无尾部换行的 `data: ...`）。
     */
    public function flushTail(callable $onContent, ?callable $onReasoning = null): bool
    {
        $tail = \trim($this->lineBuffer);
        $this->lineBuffer = '';
        if ($tail === '') {
            return true;
        }

        return $this->processLine($tail, $onContent, $onReasoning);
    }

    public function streamTerminatedNormally(): bool
    {
        return $this->streamTerminated;
    }

    public function hasValidChunk(): bool
    {
        return $this->hasValidChunk;
    }

    public function rawResponseSnapshot(): string
    {
        return $this->rawResponse;
    }

    public function tailLineBuffer(): string
    {
        return $this->lineBuffer;
    }

    private function processLine(string $line, callable $onContent, ?callable $onReasoning): bool
    {
        if ($line === '' || !\str_starts_with($line, 'data: ')) {
            return true;
        }

        $jsonData = \substr($line, 6);
        if ($jsonData === '[DONE]') {
            $this->streamTerminated = true;
            return true;
        }

        $chunk = \json_decode($jsonData, true);
        if (!\is_array($chunk)) {
            return true;
        }
        $delta = $chunk['choices'][0]['delta'] ?? null;
        if (!\is_array($delta)) {
            return true;
        }

        $reasoningParts = [];
        if (\array_key_exists('reasoning_content', $delta) && $delta['reasoning_content'] !== null) {
            $reasoningParts[] = (string)$delta['reasoning_content'];
        }
        if (\array_key_exists('reasoning', $delta) && $delta['reasoning'] !== null) {
            $reasoningParts[] = (string)$delta['reasoning'];
        }

        if ($reasoningParts !== []) {
            $this->hasValidChunk = true;
            if ($onReasoning !== null) {
                foreach ($reasoningParts as $reasoningPart) {
                    if ($reasoningPart === '') {
                        continue;
                    }
                    if ($onReasoning($reasoningPart) === false) {
                        return false;
                    }
                }
            }
        }

        if (\array_key_exists('content', $delta) && $delta['content'] !== null) {
            [$cleanContent, $thinkSegments] = $this->splitThinkContent((string)$delta['content']);
            if ($onReasoning !== null) {
                foreach ($thinkSegments as $segment) {
                    if ($segment === '') {
                        continue;
                    }
                    $this->hasValidChunk = true;
                    if ($onReasoning($segment) === false) {
                        return false;
                    }
                }
            }

            if ($cleanContent !== '') {
                $this->hasValidChunk = true;
                if ($onContent($cleanContent) === false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array{0:string,1:list<string>} [formalContent, thinkSegments]
     */
    private function splitThinkContent(string $content): array
    {
        if ($content === '' || !\str_contains($content, '<think')) {
            return [$content, []];
        }

        $thinkSegments = [];
        if (\preg_match_all('/<think\b[^>]*>([\s\S]*?)<\/think>/i', $content, $matches)) {
            foreach (($matches[1] ?? []) as $segment) {
                $thinkSegments[] = (string)$segment;
            }
        }

        $formal = (string)\preg_replace('/<think\b[^>]*>[\s\S]*?<\/think>/i', '', $content);
        return [$formal, $thinkSegments];
    }
}

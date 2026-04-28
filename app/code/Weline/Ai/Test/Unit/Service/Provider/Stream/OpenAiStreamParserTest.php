<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Provider\Stream;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\Provider\Stream\OpenAiStreamParser;

final class OpenAiStreamParserTest extends TestCase
{
    public function testEmitsContentChunksFromCompleteSseLines(): void
    {
        $parser = new OpenAiStreamParser();
        $chunks = [];
        $reasoning = [];

        $shouldContinue = $parser->ingest(
            $this->buildSseFrame(['choices' => [['delta' => ['content' => 'hello']]]])
            . $this->buildSseFrame(['choices' => [['delta' => ['content' => ' world']]]]),
            static function (string $chunk) use (&$chunks): bool {
                $chunks[] = $chunk;
                return true;
            },
            static function (string $reason) use (&$reasoning): bool {
                $reasoning[] = $reason;
                return true;
            }
        );

        self::assertTrue($shouldContinue);
        self::assertSame(['hello', ' world'], $chunks);
        self::assertSame([], $reasoning);
        self::assertTrue($parser->hasValidChunk());
        self::assertFalse($parser->streamTerminatedNormally());
    }

    public function testHandlesContentSpanningMultipleWriteCalls(): void
    {
        $parser = new OpenAiStreamParser();
        $chunks = [];
        $contentCallback = static function (string $chunk) use (&$chunks): bool {
            $chunks[] = $chunk;
            return true;
        };

        $frame = $this->buildSseFrame(['choices' => [['delta' => ['content' => 'partial']]]]);
        $half = (int)\floor(\strlen($frame) / 2);
        self::assertTrue($parser->ingest(\substr($frame, 0, $half), $contentCallback));
        self::assertSame([], $chunks, 'No callback before the line is complete.');
        self::assertTrue($parser->ingest(\substr($frame, $half), $contentCallback));
        self::assertSame(['partial'], $chunks);
    }

    public function testAcceptsDoneMarkerAndSetsStreamTerminated(): void
    {
        $parser = new OpenAiStreamParser();
        $chunks = [];

        $parser->ingest(
            $this->buildSseFrame(['choices' => [['delta' => ['content' => 'final']]]])
            . "data: [DONE]\n\n",
            static function (string $chunk) use (&$chunks): bool {
                $chunks[] = $chunk;
                return true;
            }
        );

        self::assertSame(['final'], $chunks);
        self::assertTrue($parser->streamTerminatedNormally());
    }

    public function testReasoningContentDispatchesOnReasoningCallback(): void
    {
        $parser = new OpenAiStreamParser();
        $contents = [];
        $reasonings = [];

        $parser->ingest(
            $this->buildSseFrame(['choices' => [['delta' => ['reasoning_content' => 'plan']]]])
            . $this->buildSseFrame(['choices' => [['delta' => ['content' => 'answer']]]]),
            static function (string $chunk) use (&$contents): bool {
                $contents[] = $chunk;
                return true;
            },
            static function (string $reason) use (&$reasonings): bool {
                $reasonings[] = $reason;
                return true;
            }
        );

        self::assertSame(['plan'], $reasonings);
        self::assertSame(['answer'], $contents);
        self::assertTrue($parser->hasValidChunk());
    }

    public function testAbortSignalFromContentCallbackStopsIngestion(): void
    {
        $parser = new OpenAiStreamParser();
        $delivered = [];

        $shouldContinue = $parser->ingest(
            $this->buildSseFrame(['choices' => [['delta' => ['content' => 'first']]]])
            . $this->buildSseFrame(['choices' => [['delta' => ['content' => 'second']]]])
            . $this->buildSseFrame(['choices' => [['delta' => ['content' => 'third']]]]),
            static function (string $chunk) use (&$delivered): bool {
                $delivered[] = $chunk;
                return $chunk !== 'second';
            }
        );

        self::assertFalse($shouldContinue, 'parser must report abort to caller');
        self::assertSame(['first', 'second'], $delivered);
    }

    public function testAbortSignalFromReasoningCallbackStopsIngestion(): void
    {
        $parser = new OpenAiStreamParser();
        $delivered = [];

        $shouldContinue = $parser->ingest(
            $this->buildSseFrame(['choices' => [['delta' => ['reasoning_content' => 'leak']]]])
            . $this->buildSseFrame(['choices' => [['delta' => ['content' => 'never']]]]),
            static function (string $chunk) use (&$delivered): bool {
                $delivered[] = $chunk;
                return true;
            },
            static function (string $reason) use (&$delivered): bool {
                $delivered[] = '[r]' . $reason;
                return false;
            }
        );

        self::assertFalse($shouldContinue);
        self::assertSame(['[r]leak'], $delivered);
    }

    public function testFlushTailHandlesDanglingDataLineWithoutNewline(): void
    {
        $parser = new OpenAiStreamParser();
        $chunks = [];
        $contentCallback = static function (string $chunk) use (&$chunks): bool {
            $chunks[] = $chunk;
            return true;
        };

        $parser->ingest(
            "data: " . \json_encode(['choices' => [['delta' => ['content' => 'tail']]]]),
            $contentCallback
        );
        self::assertSame([], $chunks, 'Dangling line must not be emitted before flushTail');

        $parser->flushTail($contentCallback);
        self::assertSame(['tail'], $chunks);
    }

    public function testFlushTailRecognisesDoneMarkerWithoutTrailingNewline(): void
    {
        $parser = new OpenAiStreamParser();
        $parser->ingest('data: [DONE]', static fn(string $c): bool => true);
        self::assertFalse($parser->streamTerminatedNormally());
        $parser->flushTail(static fn(string $c): bool => true);
        self::assertTrue($parser->streamTerminatedNormally());
    }

    public function testRawResponseBufferIsCappedToConfiguredLimit(): void
    {
        $parser = new OpenAiStreamParser(rawBufferLimit: 16);
        $parser->ingest(\str_repeat('A', 100), static fn(string $c): bool => true);

        self::assertLessThanOrEqual(16, \strlen($parser->rawResponseSnapshot()));
    }

    public function testNonDataLinesAreIgnoredSafely(): void
    {
        $parser = new OpenAiStreamParser();
        $chunks = [];

        $parser->ingest(
            ": comment line\nevent: ping\n\n"
            . $this->buildSseFrame(['choices' => [['delta' => ['content' => 'ok']]]]),
            static function (string $chunk) use (&$chunks): bool {
                $chunks[] = $chunk;
                return true;
            }
        );

        self::assertSame(['ok'], $chunks);
    }

    public function testMalformedJsonInDataFrameIsIgnoredWithoutThrowing(): void
    {
        $parser = new OpenAiStreamParser();
        $chunks = [];

        $parser->ingest(
            "data: {not-json\n"
            . $this->buildSseFrame(['choices' => [['delta' => ['content' => 'ok']]]]),
            static function (string $chunk) use (&$chunks): bool {
                $chunks[] = $chunk;
                return true;
            }
        );

        self::assertSame(['ok'], $chunks);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildSseFrame(array $payload): string
    {
        return 'data: ' . \json_encode($payload) . "\n\n";
    }
}

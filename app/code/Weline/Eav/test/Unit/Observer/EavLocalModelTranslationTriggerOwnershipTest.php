<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class EavLocalModelTranslationTriggerOwnershipTest extends TestCase
{
    public function testUnrelatedSaveDoesNotConsumeMemoAndRegisteredModelsStillTrigger(): void
    {
        // 独立进程只加载观察器与最小替身，避免真实数据库和翻译入队。
        $script = <<<'PHP'
namespace Weline\Framework { class Context { public static function hasCurrent(): bool { return true; } } }
namespace Weline\Framework\Runtime {
    class RequestContext {
        public static array $values = [];
        public static function has(string $key): bool { return array_key_exists($key, self::$values); }
        public static function set(string $key, mixed $value): void { self::$values[$key] = $value; }
    }
}
namespace Weline\Framework\DataObject {
    class DataObject {
        public function __construct(private array $data = []) {}
        public function getData(string $key): mixed { return $this->data[$key] ?? null; }
    }
}
namespace Weline\Framework\Event {
    class Event extends \Weline\Framework\DataObject\DataObject {}
    interface ObserverInterface { public function execute(Event &$event): void; }
}
namespace Weline\Eav\Model { class EavEntity {} class EavAttribute {} }
namespace Weline\Eav\Model\EavAttribute { class Set {} class Group {} class Option {} }
namespace Weline\Product\Model\Shard { class AttributeValue {} }
namespace Weline\I18n\Service\LocalModelTranslation {
    final class LocalModelTranslationQueueService {
        public int $calls = 0;
        public function enqueue(string $requestedBy = 'auto', bool $force = false): int { return ++$this->calls; }
    }
}
namespace {
    $root = $argv[1];
    require $root . '/app/code/Weline/Eav/Observer/EavLocalModelTranslationTrigger.php';
    $xml = new \DOMDocument();
    $xml->load($root . '/app/code/Weline/Eav/etc/event.xml');
    $registered = [];
    foreach ($xml->getElementsByTagName('event') as $definition) {
        foreach ($definition->getElementsByTagName('observer') as $observer) {
            if ($observer->getAttribute('instance') === \Weline\Eav\Observer\EavLocalModelTranslationTrigger::class) {
                $registered[] = str_replace('_', '\\', substr($definition->getAttribute('name'), 0, -strlen('_model_save_after')));
            }
        }
    }
    $makeEvent = static fn(object $model): \Weline\Framework\Event\Event => new \Weline\Framework\Event\Event([
        'data' => new \Weline\Framework\DataObject\DataObject(['model' => $model]),
    ]);
    $queue = new \Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationQueueService();
    $trigger = new \Weline\Eav\Observer\EavLocalModelTranslationTrigger($queue);
    $event = $makeEvent(new \Weline\Product\Model\Shard\AttributeValue());
    $trigger->execute($event);
    $result = ['after_unrelated_calls' => $queue->calls, 'after_unrelated_memo' => \Weline\Framework\Runtime\RequestContext::$values];
    $event = $makeEvent(new $registered[0]());
    $trigger->execute($event);
    $result['after_valid_calls'] = $queue->calls;
    $event = $makeEvent(new $registered[1]());
    $trigger->execute($event);
    $result['after_second_valid_calls'] = $queue->calls;
    foreach ($registered as $class) {
        \Weline\Framework\Runtime\RequestContext::$values = [];
        $queue = new \Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationQueueService();
        $trigger = new \Weline\Eav\Observer\EavLocalModelTranslationTrigger($queue);
        $event = $makeEvent(new $class());
        $trigger->execute($event);
        $result['registered_calls'][$class] = $queue->calls;
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
}
PHP;
        $process = proc_open([PHP_BINARY, '-r', $script, dirname(__DIR__, 7)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $result['after_unrelated_calls'], 'Product 保存不能触发全量翻译候选扫描。');
        self::assertSame([], $result['after_unrelated_memo'], '无关模型不能占用后续 EAV 事件的请求 memo。');
        self::assertSame(1, $result['after_valid_calls']);
        self::assertSame(1, $result['after_second_valid_calls']);
        self::assertCount(5, $result['registered_calls']);
        foreach ($result['registered_calls'] as $class => $calls) {
            self::assertSame(1, $calls, $class . ' 必须保留 etc/event.xml 已登记的触发行为。');
        }
    }
}

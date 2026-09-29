<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\View\Template;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Dto\ThemeRenderable;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutEntity\LayoutRelationCompiler;
use Weline\Theme\Service\LayoutEntity\ResolvedLayoutSlots;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityWidgetRenderer;
use Weline\Theme\Service\RuntimeTemplateMaterializer;
use Weline\Theme\Service\ThemeComponentRenderer;
use Weline\Theme\Service\ThemePlaceableRegistry;
use Weline\Theme\Service\ThemeRenderableResolver;

/** Shared real compiler/Template runtime fixture; registry discovery alone is in memory. */
trait ResolvedPhtmlFixture
{
    private ?Context $previous;
    private ThemeComponentRenderer $components;
    private ThemePlaceableRegistry $registry;

    protected function setUp(): void
    {
        $this->previous = Context::getCurrent();
        Context::enter(new Context());
        RequestContext::init();
        $events = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
        $eventName = 'Weline_Framework_Template::before_compile';
        $events->getEventObservers($eventName);
        $xml = simplexml_load_file(dirname(__DIR__, 4) . '/etc/event.xml');
        $local = [];
        foreach ($xml->xpath('//*[local-name()="event" and @name="' . $eventName . '"]/*[local-name()="observer"]') as $observer) {
            $attributes = [];
            foreach ($observer->attributes() as $key => $value) { $attributes[(string)$key] = (string)$value; }
            $local[] = $attributes;
        }
        // Register the current module XML locally without upgrading the running site's registry.
        $property = new \ReflectionProperty($events, 'eventsObservers');
        $property->setValue($events, array_replace($property->getValue($events), [$eventName => $local]));
        $cache = new \ReflectionProperty($events, 'observerCache');
        $value = $cache->getValue($events); unset($value[$eventName]); $cache->setValue($events, $value);
        $template = ObjectManager::getInstance(Template::class);
        $this->components = new ThemeComponentRenderer($template, new RuntimeTemplateMaterializer($template), new ThemeRenderableResolver());
        $this->registry = new class extends ThemePlaceableRegistry {
            public array $definitions = [];
            public function __construct() {}
            public function find(string $module, string $type, string $code, ?WelineTheme $theme = null, string $area = 'frontend'): ?ThemeComponentDefinition
            {
                return $this->definitions[$code] ?? null;
            }
        };
        $this->registry->definitions['label'] = $this->definition('label', '<b><?= htmlspecialchars((string)$this->getData("text")) ?></b>');
        $this->registry->definitions['box'] = $this->definition('box', '<article data-wslot="inside" data-wslot-exclusive="true"><em>default</em></article><!-- registered-native-slot -->', ['inside']);
        $store = (new \ReflectionClass(ThemeLayoutEntityConfigStore::class))->newInstanceWithoutConstructor();
        $renderer = new ThemeLayoutEntityWidgetRenderer($store, $this->registry, $this->components);
        ObjectManager::setInstance(ThemeLayoutEntityWidgetRenderer::class, $renderer);
    }

    protected function tearDown(): void
    {
        if ($this->previous !== null) { Context::enter($this->previous); } else { Context::leave(); }
    }

    private function execute(string $source, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        try { eval('?>' . $source); return (string)ob_get_clean(); }
        catch (\Throwable $e) { ob_end_clean(); throw $e; }
    }

    private function node(string $uid, string $slot, string $text, int $order, string $code = 'label'): array
    {
        return ['node_uid' => str_repeat($uid, 32), 'widget_module' => 'Fixture', 'widget_type' => 'content', 'widget_code' => $code, 'slot_id' => $slot, 'sort_order' => $order, 'config' => ['text' => $text]];
    }

    private function definition(string $code, string $source, array $slots = []): ThemeComponentDefinition
    {
        return new ThemeComponentDefinition(module: 'Fixture', type: 'content', code: $code, name: $code, renderMode: ThemeRenderable::MODE_TEMPLATE_CONTENT, templateContent: $source, slots: $slots);
    }
}

final class ResolvedPhtmlHookTemplate extends Template
{
    public bool $suppliedHook = false;
    public function getHookResult(string $name, bool $forceRefresh = false, bool $preferFallbackOnEmpty = false): \Weline\Framework\Hook\HookRenderResult
    {
        return new \Weline\Framework\Hook\HookRenderResult($this->suppliedHook ? '<strong>HOOK</strong>' : '', false, !$this->suppliedHook);
    }
}

final class ResolvedPhtmlProbeBlock extends \Weline\Framework\DataObject\DataObject
{
    public function toHtml(): string { return '<mark>' . htmlspecialchars((string)$this->getData('text')) . '</mark>'; }
}

final class ResolvedPhtmlDefaultContainerBlock extends \Weline\Framework\DataObject\DataObject
{
    public function toHtml(): string { return '<aside>' . $this->getData('title') . ResolvedLayoutSlots::render('inside') . '</aside>'; }
    public function render(): string { return $this->toHtml(); }
}

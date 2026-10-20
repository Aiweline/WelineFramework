<?php

declare(strict_types=1);

/**
 * S2-A 模板级 v3 编译入口（默认关闭，只由 `template.engine.templates` 白名单命中）。
 *
 * 与影子编译共用 Lexer/Parser/Lower/Emitter，但不做影子归一化/比对。
 * PHP Fragment 内联展开仍复用旧 `Taglib::expandInlineTags()` 迁移桥，P6 前替换。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler;

use Weline\Framework\View\Compiler\Ir\Emitter;
use Weline\Framework\View\Compiler\Ir\Lower;
use Weline\Framework\View\Compiler\Lexer\Lexer;
use Weline\Framework\View\Compiler\Parser\Parser;
use Weline\Framework\View\Compiler\Tag\Adapter\CompiledTagRegistry;
use Weline\Framework\View\Compiler\Tag\Builtin\Interpolation;
use Weline\Framework\View\Compiler\Tag\BuiltinRegistry;
use Weline\Framework\View\Compiler\Tag\Registry;
use Weline\Framework\View\Compiler\Tag\RegistryNameMatcher;
use Weline\Framework\View\Compiler\Tag\TagNameMatcherInterface;
use Weline\Framework\View\Taglib\TemplateTranslationWords;
use Weline\Framework\View\Template;

final class V3Compiler
{
    private static ?Registry $registry = null;
    private static ?TagNameMatcherInterface $matcher = null;

    public static function compile(string $source, string $fileName, Template $template): string
    {
        self::boot();

        if (\defined('PROD') && \PROD) {
            $source = self::removeHtmlComments($source);
        }

        $taglib = $template->getTaglib();
        $phpTransformer = static function (string $raw) use ($taglib, $template, $fileName): string {
            $bound = $template;
            return $taglib->expandInlineTagsForCompiler($raw, $bound, $fileName);
        };

        $resolvedSlotsClass = 'Weline\\Theme\\Service\\LayoutEntity\\ResolvedLayoutSlots';
        $resolvedSlotsKey = null;
        $resolvedSlotsState = null;
        $hadResolvedSlots = false;
        if (\class_exists($resolvedSlotsClass)) {
            try {
                $keyMethod = new \ReflectionMethod($resolvedSlotsClass, 'key');
                $keyMethod->setAccessible(true);
                $resolvedSlotsKey = (string)$keyMethod->invoke(null);
                $hadResolvedSlots = \Weline\Framework\Runtime\RequestContext::has($resolvedSlotsKey);
                $resolvedSlotsState = \Weline\Framework\Runtime\RequestContext::get($resolvedSlotsKey);
            } catch (\Throwable) {
                $resolvedSlotsKey = null;
            }
        }

        $slotClass = 'Weline\\Theme\\Taglib\\Slot';
        $slotKey = 'theme.taglib.registered_slots.v1';
        $slotState = \class_exists($slotClass) ? $slotClass::getRegisteredSlots() : null;
        $hadSlotState = \class_exists(\Weline\Framework\Runtime\RequestContext::class)
            && \Weline\Framework\Runtime\RequestContext::has($slotKey);

        $staticSnapshots = self::snapshotStatics([
            'Weline\\Widget\\Service\\WidgetData' => ['typeValidationCache', 'typeWidgetsCache'],
            'Weline\\Widget\\Service\\WidgetRegistry' => ['staticCachedRegistry', 'staticCachedFileMtime'],
            'Weline\\Widget\\Service\\ParamSchemaRegistry' => ['staticCachedRegistry', 'staticCachedFileMtime'],
        ]);

        $themeDataClass = 'Weline\\Theme\\Helper\\ThemeData';
        $themeStateMethod = null;
        $themeState = null;
        if (\class_exists($themeDataClass)) {
            try {
                $themeStateMethod = new \ReflectionMethod($themeDataClass, 'state');
                $themeStateMethod->setAccessible(true);
                $liveState = $themeStateMethod->invoke(null);
                $themeState = \is_object($liveState) ? clone $liveState : null;
            } catch (\Throwable) {
                $themeState = null;
            }
        }

        $parser = new Parser(new Lexer(), self::$matcher, $phpTransformer);
        $emitter = new Emitter();
        $lower = new Lower(self::$registry, $emitter, new Interpolation(), $template);
        try {
            $v3 = $template->withActiveSourceOrigin(
                $fileName,
                static fn (): string => $emitter->emit($lower->lower($parser->parse($source))),
            );
        } finally {
            self::restoreStatics($staticSnapshots);
            if ($resolvedSlotsKey !== null) {
                if ($hadResolvedSlots) {
                    \Weline\Framework\Runtime\RequestContext::set($resolvedSlotsKey, $resolvedSlotsState);
                } else {
                    \Weline\Framework\Runtime\RequestContext::remove($resolvedSlotsKey);
                }
            }
            if ($slotState !== null) {
                if ($hadSlotState) {
                    \Weline\Framework\Runtime\RequestContext::set($slotKey, $slotState);
                } else {
                    $slotClass::clearRegisteredSlots();
                }
            }
            // 编译期主题资产解析不能污染当前请求的 ThemeData 上下文。
            // 恢复整个请求态对象，避免 initialized/performance/scope 缓存残留。
            if ($themeState !== null && $themeStateMethod !== null) {
                try {
                    $liveState = $themeStateMethod->invoke(null);
                    if (\is_object($liveState)) {
                        $reflection = new \ReflectionObject($themeState);
                        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                            $property->setValue($liveState, $property->getValue($themeState));
                        }
                    }
                } catch (\Throwable) {
                    // 恢复失败不阻断编译；影子/页面验收会暴露。
                }
            }
        }

        if (\class_exists(TemplateTranslationWords::class)) {
            $v3 = TemplateTranslationWords::withRuntimePrefetch($v3);
        }

        return $v3;
    }

    /**
     * @param array<string, list<string>> $map
     * @return array<string, array<string, mixed>>
     */
    private static function snapshotStatics(array $map): array
    {
        $snapshots = [];
        foreach ($map as $class => $properties) {
            if (!\class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            foreach ($properties as $property) {
                if (!$reflection->hasProperty($property)) {
                    continue;
                }
                $prop = $reflection->getProperty($property);
                $prop->setAccessible(true);
                $snapshots[$class][$property] = $prop->getValue();
            }
        }

        return $snapshots;
    }

    /**
     * @param array<string, array<string, mixed>> $snapshots
     */
    private static function restoreStatics(array $snapshots): void
    {
        foreach ($snapshots as $class => $properties) {
            if (!\class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            foreach ($properties as $property => $value) {
                $prop = $reflection->getProperty($property);
                $prop->setAccessible(true);
                $prop->setValue(null, $value);
            }
        }
    }

    private static function boot(): void
    {
        if (self::$registry !== null && self::$matcher !== null) {
            return;
        }
        $modules = (new CompiledTagRegistry(BP . 'generated/taglibs.php'))->build();
        self::$registry = new Registry([...$modules->all(), ...BuiltinRegistry::descriptors()]);
        $names = [];
        foreach (self::$registry->all() as $descriptor) {
            foreach ($descriptor->allNames() as $name) {
                $names[\strtolower($name)] = true;
            }
        }
        self::$matcher = new RegistryNameMatcher(self::$registry, \array_keys($names), \array_keys($names));
    }

    private static function removeHtmlComments(string $content): string
    {
        $result = '';
        $length = \strlen($content);
        $i = 0;
        while ($i < $length) {
            if ($content[$i] === '<' && \substr($content, $i, 4) === '<!--') {
                $end = \strpos($content, '-->', $i + 4);
                if ($end === false) {
                    break;
                }
                $i = $end + 3;
                continue;
            }
            $result .= $content[$i];
            $i++;
        }

        return $result;
    }
}

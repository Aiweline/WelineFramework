<?php
declare(strict_types=1);

namespace Weline\I18n\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\I18n\Helper\JsModuleParser;
use Weline\I18n\Helper\JsTranslationsExtractor;
use Weline\I18n\Helper\JsWordsRegistry;

/**
 * 在模板获取阶段解析 JS 模块声明，提取翻译词
 */
class TemplateFetchFile implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /** @var DataObject|null $fileData */
        $fileData = $event->getData('data');
        if (!$fileData instanceof DataObject) {
            return;
        }

        $filename = $fileData->getData('filename');
        if (empty($filename) || !is_file($filename)) {
            return;
        }

        $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['phtml', 'phtm', 'html', 'htm'], true)) {
            return;
        }

        $content = @file_get_contents($filename);
        if ($content === false || strpos($content, 'declare') === false) {
            return;
        }

        $declaredModules = JsModuleParser::extractDeclaredModules($content);
        if (empty($declaredModules)) {
            return;
        }

        $area = JsModuleParser::detectAreaFromPath($filename);
        $jsWords = JsTranslationsExtractor::extractWordsFromModules($declaredModules, $area);
        if (empty($jsWords)) {
            return;
        }

        JsWordsRegistry::addWords(array_keys($jsWords));
    }
}


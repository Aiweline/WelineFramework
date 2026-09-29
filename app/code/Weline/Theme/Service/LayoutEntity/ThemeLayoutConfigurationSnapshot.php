<?php
declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;
use Weline\Framework\Manager\ObjectManager;
use Weline\Meta\Api\MetaConfigRepositoryInterface;
use Weline\Meta\Api\Data\MetaConfigSearch;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Service\ThemeLayoutScopeNormalizer;

/** Captures existing ThemeData configuration as immutable version input, never a runtime sidecar. */
final class ThemeLayoutConfigurationSnapshot
{
    public function capture(ThemeEditorContext $context): array
    {
        $namespace = 'theme.' . $context->area;
        $normalizer = ObjectManager::getInstance(ThemeLayoutScopeNormalizer::class);
        $scopes = [];
        foreach (array_reverse($context->scope->fallbackStorageScopes) as $scope) {
            foreach (array_reverse($normalizer->readCandidateScopes($normalizer->encodeStorageScope($scope, $context->scope->storeMode))) as $candidate) { $scopes[$candidate] = $candidate; }
        }
        $records = [];
        $repository = ObjectManager::getInstance(MetaConfigRepositoryInterface::class);
        foreach ($scopes as $scope) {
            array_push($records, ...$repository->search(new MetaConfigSearch(namespace:$namespace,scope:$scope,allLocales:true,identifyId:(string)$context->themeId)));
        }
        // Dictionary iteration can span unrelated themes. Only request the
        // configuration words owned by these immutable input records.
        $words = [];
        $keys = [];
        foreach ($records as $record) {
            if (!str_contains($record->configKey, '.param.')) { continue; }
            $key = preg_replace('/\.value$/D', '', $record->configKey) . '.value';
            $keys[$key] = $key;
            if (preg_match('/^(.+)\.param\.([^.]++)(?:\.value)?$/D', $record->configKey, $match)) {
                foreach ($this->leafPaths($this->decode((string)$record->value), $match[2]) as $path) { $keys[$match[1] . '.path.' . $path . '.value'] = $match[1] . '.path.' . $path . '.value'; }
            }
        }
        foreach (ObjectManager::getInstance(\Weline\Meta\Api\MetadataRepositoryInterface::class)->search(
            new \Weline\Meta\Api\Data\MetadataSearch(namespace:'theme', identifyPrefix:$namespace . '.')) as $definition) {
            $resource = substr($definition->identify, strlen($namespace) + 1);
            foreach ((array)($definition->setting['param'] ?? []) as $name => $parameter) {
                $key = $resource . '.param.' . $name . '.value';
                $keys[$key] = $key;
                $default = is_array($parameter) ? ($parameter['default'] ?? null) : $parameter;
                if (is_string($default)) { $default = $this->decode($default); }
                foreach ($this->leafPaths($default, (string)$name) as $path) { $keys[$resource . '.path.' . $path . '.value'] = $resource . '.path.' . $path . '.value'; }
            }
        }
        foreach ($keys as $key) {
            foreach ($scopes as $scope) {
                $word = '@meta::' . $namespace . '.' . $key . ($scope === 'default' ? '' : '|scope:' . $scope);
                $words[$word] = $word;
            }
        }
        $translations = [];
        if ($words !== []) {
            $dictionary = ObjectManager::getInstance(DictionaryRepositoryInterface::class);
            foreach (ObjectManager::getInstance(\Weline\I18n\Api\Localization\LocaleCatalogInterface::class)->list('en_US') as $locale) {
                foreach (array_chunk(array_values($words), 250) as $chunk) { array_push($translations, ...array_values($dictionary->getEntries($chunk, $locale['code']))); }
            }
        }
        return $this->fromRecords($records,$translations,$namespace,array_values($scopes));
    }

    public function fromRecords(array $records, array $translations, string $namespace, array $scopes): array
    {
        $out = ['partial_options'=>['header'=>'default','footer'=>'default','sidebar'=>'default'],'params'=>[],'locale_params'=>[]];
        foreach ($scopes as $scope) {
            foreach ($records as $record) {
                if ($record->namespace !== $namespace || $record->scope !== $scope) { continue; }
                $key = (string)$record->configKey;
                $value = $this->decode((string)$record->value);
                if (in_array($key, ['partials','partials.value'], true) && is_array($value)) { $out['partial_options'] = array_replace($out['partial_options'],$value); continue; }
                if (preg_match('/^partials\.([^.]+)(?:\.value)?$/D',$key,$match) && is_string($value)) { $out['partial_options'][$match[1]]=$value; continue; }
                if (!preg_match('/^(.+)\.param\.(.+?)(?:\.value)?$/D',$key,$match)) { continue; }
                if ($record->locale === null) { $out['params'][$match[1]][$match[2]]=$value; }
                else { $out['locale_params'][$record->locale][$match[1]][$match[2]]=$value; }
            }
            foreach ($translations as $entry) {
                [$word,$entryScope] = array_pad(explode('|scope:',$entry->word,2),2,'default');
                if ($entryScope !== $scope || !str_starts_with($word,'@meta::'.$namespace.'.')) { continue; }
                $key=substr($word,strlen('@meta::'.$namespace.'.'));
                if (preg_match('/^(.+)\.(?:param|path)\.(.+?)\.value$/D',$key,$match)) {
                    $out['locale_params'][$entry->localeCode][$match[1]][$match[2]]=$this->decode($entry->translation);
                }
            }
        }
        return $out;
    }
    private function decode(string $value): mixed
    {
        $decoded=json_decode($value,true);
        return is_array($decoded) ? $decoded : $value;
    }
    private function leafPaths(mixed $value, string $prefix): array
    {
        if (!is_array($value)) { return [$prefix]; }
        $out = [];
        foreach ($value as $key => $child) { array_push($out, ...$this->leafPaths($child, $prefix . '.' . $key)); }
        return $out;
    }
}

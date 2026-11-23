<?php
declare(strict_types=1);

namespace Weline\Agent\Service;

use Weline\Agent\Interface\AgentPluginInterface;
use Weline\Agent\Model\AgentPlugin;
use Weline\Framework\Manager\ObjectManager;

/**
 * 智能体插件扫描器
 *
 * 扫描 app/code/{Module}/extends/module/Weline_Agent/Plugin/*.php，
 * 实现类须实现 AgentPluginInterface；登记/更新 weline_agent_plugin，
 * 已删除的实现标记 missing，disabled 状态保留。
 */
final class PluginScanner
{
    private const PLUGIN_DIR_PATTERN = 'app/code/*/*/extends/module/Weline_Agent/Plugin';

    /**
     * 全量同步：扫描 → upsert → missing 标记
     *
     * @return array{scanned:int, upserted:int, missing:int}
     */
    public function sync(): array
    {
        $found = $this->scan();
        $stats = ['scanned' => 0, 'upserted' => 0, 'missing' => 0];

        try {
            $prototype = ObjectManager::getInstance(AgentPlugin::class);
        } catch (\Throwable) {
            return $stats;
        }

        $seenCodes = [];
        foreach ($found as $item) {
            $stats['scanned']++;
            $code = $item['code'];
            $seenCodes[] = $code;

            try {
                $existing = clone $prototype;
                $existing->reset()->clearQuery()
                    ->where(AgentPlugin::schema_fields_CODE, $code)
                    ->find()->fetch();

                if ($existing->getId()) {
                    $plugin = $existing;
                    $status = (string)$plugin->getData(AgentPlugin::schema_fields_STATUS);
                } else {
                    $plugin = clone $prototype;
                    $plugin->reset()->clearQuery();
                    $status = AgentPlugin::STATUS_ENABLED;
                }

                $plugin->setData(AgentPlugin::schema_fields_CODE, $code);
                $plugin->setData(AgentPlugin::schema_fields_NAME, $item['name']);
                $plugin->setData(AgentPlugin::schema_fields_VERSION, $item['version']);
                $plugin->setData(AgentPlugin::schema_fields_MODULE, $item['module']);
                $plugin->setData(AgentPlugin::schema_fields_CLASS_NAME, $item['class_name']);
                $plugin->setData(
                    AgentPlugin::schema_fields_MANIFEST_JSON,
                    json_encode($item['manifest'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
                $plugin->setData(
                    AgentPlugin::schema_fields_STATUS,
                    $status !== '' ? $status : AgentPlugin::STATUS_ENABLED
                );
                $plugin->setData(AgentPlugin::schema_fields_IS_BUILTIN, 0);
                $plugin->save();
                $stats['upserted']++;
            } catch (\Throwable) {
                continue;
            }
        }

        // 已登记但本次未扫描到 → missing
        try {
            $rows = clone $prototype;
            $rows->reset()->clearQuery()
                ->where(AgentPlugin::schema_fields_STATUS, AgentPlugin::STATUS_MISSING, '!=')
                ->select()->fetchArray();
            foreach ($rows as $row) {
                $rowCode = (string)($row[AgentPlugin::schema_fields_CODE] ?? '');
                if ($rowCode === '' || in_array($rowCode, $seenCodes, true)) {
                    continue;
                }
                $id = (int)($row[AgentPlugin::schema_fields_PLUGIN_ID] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $stale = clone $prototype;
                $stale->load($id);
                $stale->setData(AgentPlugin::schema_fields_STATUS, AgentPlugin::STATUS_MISSING);
                $stale->save();
                $stats['missing']++;
            }
        } catch (\Throwable) {
        }

        return $stats;
    }

    /**
     * 扫描插件实现类（不落库）
     *
     * @return list<array{code:string,name:string,version:string,module:string,class_name:string,manifest:array<string,mixed>}>
     */
    public function scan(): array
    {
        $base = dirname(__DIR__, 3); // app/code
        $pattern = $base . '/*/*/extends/module/Weline_Agent/Plugin/*.php';
        $files = glob($pattern) ?: [];

        $plugins = [];
        foreach ($files as $file) {
            $className = $this->classNameForFile($file);
            if ($className === null || !class_exists($className)) {
                continue;
            }
            try {
                $instance = new $className();
            } catch (\Throwable) {
                continue;
            }
            if (!$instance instanceof AgentPluginInterface) {
                continue;
            }

            $code = trim($instance->getCode());
            if ($code === '' || strlen($code) > 64) {
                continue;
            }

            $plugins[] = [
                'code' => $code,
                'name' => $instance->getName() !== '' ? $instance->getName() : $code,
                'version' => $instance->getVersion() !== '' ? $instance->getVersion() : '1.0.0',
                'module' => $this->moduleForFile($file),
                'class_name' => $className,
                'manifest' => $instance->getManifest(),
            ];
        }

        return $plugins;
    }

    /** 文件路径 → 逻辑类名（Weline\{Module}\Extends\Module\Weline_Agent\Plugin\{Class}） */
    private function classNameForFile(string $file): ?string
    {
        $base = dirname(__DIR__, 3); // app/code
        $rel = ltrim(str_replace('\\', '/', substr($file, strlen($base) + 1)), '/');
        // Weline/Agent/extends/module/Weline_Agent/Plugin/Foo.php
        $parts = explode('/', $rel);
        if (count($parts) < 6) {
            return null;
        }
        $class = substr($parts[count($parts) - 1], 0, -4);
        if ($class === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class)) {
            return null;
        }
        array_pop($parts);
        $ns = implode('\\', array_map(
            static fn(string $seg): string => in_array(strtolower($seg), ['extends', 'module'], true)
                ? ucfirst(strtolower($seg))
                : $seg,
            $parts
        ));
        return $ns . '\\' . $class;
    }

    private function moduleForFile(string $file): string
    {
        $base = dirname(__DIR__, 3);
        $rel = ltrim(str_replace('\\', '/', substr($file, strlen($base) + 1)), '/');
        $parts = explode('/', $rel);
        // Weline/Agent/... → Weline_Agent
        return isset($parts[0], $parts[1]) ? $parts[0] . '_' . $parts[1] : ($parts[0] ?? '');
    }
}

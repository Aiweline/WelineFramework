<?php

declare(strict_types=1);

namespace Weline\Framework\Controller\Extra;

/**
 * 控制器动作的公开路径推导，以 PC 路由生成器为唯一权威口径。
 *
 * 权威实现见 `Weline\Framework\Module\Helper\Data` 的 PC 注册分支：
 * baseRouter = `Handle::pc_DIR`（`Controller`）之后**全部**段做 CamelCase→kebab；
 * action = 方法名按 `/(?=[A-Z])/` 切分，首段若是 HTTP 动词则剥掉，余段 kebab，末尾 `index` 段剥掉。
 *
 * 声明路径必须等于「真实可达路由」：两者一旦漂移，`@Extra` 策略就挂不到请求上，
 * `enabled=false` 会静默失效（实测 `/i18n/frontend/switcher/catalog/get` 与真实
 * `/i18n/frontend/switcher/catalog` 不匹配）。本类只做字符串推导，不读路由表、不反射。
 */
final class DeclarationPath
{
    /**
     * 生成器认可的首段 HTTP 动词（`RequestInterface::METHODS` 的小写形态按 `strtoupper` 比较）。
     *
     * @var list<string>
     */
    private const HTTP_VERBS = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'];

    /** 生成器在路由末尾剥掉的段名。 */
    private const TRAILING_INDEX = 'index';

    /** 生成器用于切出 baseRouterPart 的分隔符（`Handle::pc_DIR`）。 */
    private const PC_DIR = 'Controller';

    /** 命名空间标记段；类名以它开头时也能定位相对段。 */
    private const API_DIR = 'Api';

    /**
     * 类命名空间里 `Controller` 之后的全部段（含后续 `Api` 段），逐段 CamelCase→kebab。
     *
     * 复刻生成器的 `explode(Handle::pc_DIR, $class)` + `array_pop`：
     * 取 `Controller` 之后剩余部分（最后一段非空时取最后一段），不做 `_`→`-`（生成器也不做）。
     */
    public static function relative(string $class): string
    {
        $class = ltrim($class, '\\');
        if ($class === '') {
            return '';
        }
        $parts = explode(self::PC_DIR, $class);
        if (\count($parts) > 1) {
            $tail = (string) end($parts);
            if ($tail === '' && \count($parts) > 2) {
                $tail = (string) $parts[\count($parts) - 2];
            }
        } else {
            // 无 `Controller` 段：退化为第一个 `Api` 段之后（标记段本身不计入）。
            $segments = array_values(array_filter(explode('\\', $class), static fn(string $s): bool => $s !== ''));
            $start = array_search(self::API_DIR, $segments, true);
            if ($start === false) {
                return '';
            }
            $tail = implode('\\', array_slice($segments, (int) $start + 1));
        }

        return self::kebabPath($tail);
    }

    /**
     * 声明路径的规范形式：生成器会为同一动作注册多条键，这是其中「不带 HTTP 动词段」的那条。
     */
    public static function canonical(string $routerPath, string $class, string $methodName): string
    {
        $base = self::basePath($routerPath, $class);
        $action = self::action($methodName)['action'];
        if ($action === '' || $action === self::TRAILING_INDEX) {
            return $base;
        }

        return $base . '/' . $action;
    }

    /**
     * 生成器为同一动作额外注册的可达键（去掉 `::METHOD` 与去连字符别名）。
     *
     * 浏览器/前端可能打到其中任意一条，策略匹配必须同时认，否则 `enabled=false` 会从别名漏网。
     *
     * @return list<string>
     */
    public static function aliases(string $routerPath, string $class, string $methodName): array
    {
        $base = self::basePath($routerPath, $class);
        $canonical = self::canonical($routerPath, $class, $methodName);
        $parsed = self::action($methodName);

        $out = [];
        // 生成器：`$origin_route = str_replace('-', '', $route)` —— 整条路由（含 baseRouter 的连字符）去 `-`。
        $out[] = str_replace('-', '', $canonical);

        if ($parsed['verb'] !== '' && $parsed['action'] !== strtolower($methodName)) {
            // 生成器：`<base>/<整方法名每大写前插 ->`（如 postPhrase → post-phrase）。
            $out[] = $base . '/' . strtolower(ltrim((string) preg_replace('/([A-Z])/', '-$1', $methodName), '-'));
            // 生成器：`<base>/<方法名小写>`（如 postPhrase → postphrase）；与上式重复时只留一条。
            $out[] = $base . '/' . strtolower($methodName);
        }

        $unique = [];
        foreach ($out as $alias) {
            $alias = '/' . trim($alias, '/');
            if ($alias === '/' || $alias === $canonical) {
                continue;
            }
            $unique[$alias] = true;
        }
        $list = array_keys($unique);
        sort($list, SORT_STRING);

        return $list;
    }

    /**
     * 方法名 → HTTP 动词与剩余动作段（均已小写化；动作段不含动词、不含末尾 `index`）。
     *
     * @return array{verb: string, action: string}
     */
    public static function action(string $methodName): array
    {
        $split = preg_split('/(?=[A-Z])/', $methodName);
        if (!\is_array($split) || $split === []) {
            $split = [$methodName];
        }
        if (\count($split) === 1) {
            // 生成器把单段方法名整体当动作段（首段恒为空，不会被当成动词）。
            $split = ['', (string) $split[0]];
        }

        $verb = '';
        $first = (string) $split[array_key_first($split)];
        if (\in_array(strtoupper($first), self::HTTP_VERBS, true)) {
            $verb = strtoupper($first);
            array_shift($split);
        }

        $rule = trim(implode('-', $split), '-');
        if ($verb === '' && \in_array(strtoupper($rule), self::HTTP_VERBS, true)) {
            $verb = strtoupper($rule);
            $rule = '';
        }

        return ['verb' => $verb, 'action' => strtolower($rule)];
    }

    private static function basePath(string $routerPath, string $class): string
    {
        $routerPath = trim($routerPath, '/');
        $relative = self::relative($class);
        $path = '/' . trim($routerPath . ($relative !== '' ? '/' . $relative : ''), '/');

        return $path === '/' ? '/' : $path;
    }

    /**
     * 复刻生成器：对 `\`→`/` 后的 baseRouterPart 按大写切分、前一字符非 `/` 时补 `-`，整体小写。
     *
     * 等价形态：逐个路径段「首字符外每个大写字母前插 `-`」，再整体小写。
     */
    private static function kebabPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $segments = array_values(array_filter(explode('/', $path), static fn(string $s): bool => $s !== ''));
        $out = [];
        foreach ($segments as $segment) {
            $out[] = strtolower((string) preg_replace('/(?<!^)([A-Z])/', '-$1', $segment));
        }

        return implode('/', $out);
    }
}

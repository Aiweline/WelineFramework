<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Interface\PaymentCustomerGuideInterface;

class PaymentCustomerGuideRegistry
{
    public const EXTENSION_PATH = 'extends/module/Weline_Payment/PaymentCustomerGuide/';

    private ?ObjectManager $objectManager = null;

    /** @var (callable(string):string)|null */
    private $urlBuilder;

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $cachedEntries = null;
    private ?int $cachedExtendsMtime = null;

    /**
     * @param (callable(string):string)|null $urlBuilder
     */
    public function __construct(?ObjectManager $objectManager = null, ?callable $urlBuilder = null)
    {
        $this->objectManager = $objectManager;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPublishedEntries(bool $forceReload = false): array
    {
        $entries = array_values($this->getEntries($forceReload));
        usort(
            $entries,
            static fn(array $left, array $right): int => ((int) ($left['sort_order'] ?? 0)) <=> ((int) ($right['sort_order'] ?? 0))
                ?: strcmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''))
        );

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getEntry(string $methodCode, bool $forceReload = false): ?array
    {
        $methodCode = $this->normalizeMethodCode($methodCode);
        if ($methodCode === '') {
            return null;
        }

        return $this->getEntries($forceReload)[$methodCode] ?? null;
    }

    public function buildGuideRoute(string $methodCode): string
    {
        $methodCode = $this->normalizeMethodCode($methodCode);
        if ($methodCode === '') {
            return 'guide/payment';
        }

        return 'guide/payment/' . rawurlencode($methodCode);
    }

    public function buildPolicyRoute(string $methodCode): string
    {
        return $this->buildGuideRoute($methodCode) . '/policy';
    }

    public function buildAgreementRoute(string $methodCode): string
    {
        return $this->buildGuideRoute($methodCode) . '/agreement';
    }

    public function buildGuideUrl(string $methodCode): string
    {
        return $this->storefrontUrl($this->buildGuideRoute($methodCode));
    }

    public function buildPolicyUrl(string $methodCode): string
    {
        return $this->storefrontUrl($this->buildPolicyRoute($methodCode));
    }

    public function buildAgreementUrl(string $methodCode): string
    {
        return $this->storefrontUrl($this->buildAgreementRoute($methodCode));
    }

    private function storefrontUrl(string $route): string
    {
        if ($this->urlBuilder !== null) {
            return (string) ($this->urlBuilder)($route);
        }
        /** @var Url $url */
        $url = $this->om()->getInstance(Url::class);

        return $url->getUrl($route);
    }

    private function om(): ObjectManager
    {
        return $this->objectManager ??= ObjectManager::getInstance();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getEntries(bool $forceReload = false): array
    {
        $currentMtime = ExtendsData::getRegistryFileMtime();
        if (
            !$forceReload
            && $this->cachedEntries !== null
            && $this->cachedExtendsMtime === $currentMtime
        ) {
            return $this->cachedEntries;
        }

        $entries = [];
        foreach ($this->scanGuideDefinitions($forceReload) as $definition) {
            $className = (string) ($definition['class_name'] ?? '');
            if ($className === '' || !class_exists($className)) {
                continue;
            }

            try {
                $guide = $this->om()->getInstance($className);
                if (!$guide instanceof PaymentCustomerGuideInterface) {
                    continue;
                }

                $methodCode = $this->normalizeMethodCode($guide->getMethodCode());
                if ($methodCode === '') {
                    continue;
                }

                if (isset($entries[$methodCode])) {
                    w_log_error('Duplicate payment customer guide ignored: ' . $methodCode . ' (' . $className . ')');
                    continue;
                }

                $sourceModule = (string) ($definition['source_module'] ?? '');
                if ($sourceModule === '') {
                    $sourceModule = $this->resolveModuleNameFromClass($className);
                }

                $guideTemplateCode = $this->normalizeTemplateCode($guide->getGuideTemplateCode(), 'guide');
                $policyTemplateCode = $this->normalizeTemplateCode($guide->getPolicyTemplateCode(), 'policy');
                $agreementTemplateCode = $this->normalizeTemplateCode($guide->getAgreementTemplateCode(), 'agreement');

                $entries[$methodCode] = [
                    'method_code' => $methodCode,
                    'provider_code' => strtolower(trim($guide->getProviderCode())),
                    'title' => (string) $guide->getTitle(),
                    'summary' => (string) $guide->getSummary(),
                    'guide_title' => (string) $guide->getGuideTitle(),
                    'policy_title' => (string) $guide->getPolicyTitle(),
                    'agreement_title' => (string) $guide->getAgreementTitle(),
                    'guide_layout_type' => $this->normalizeLayoutType($guide->getGuideLayoutType(), 'payment_guide'),
                    'policy_layout_type' => $this->normalizeLayoutType($guide->getPolicyLayoutType(), 'payment_guide'),
                    'agreement_layout_type' => $this->normalizeLayoutType($guide->getAgreementLayoutType(), 'payment_guide'),
                    'guide_template' => $this->buildTemplateReference(
                        $sourceModule,
                        $methodCode,
                        $guideTemplateCode
                    ),
                    'policy_template' => $this->buildTemplateReference(
                        $sourceModule,
                        $methodCode,
                        $policyTemplateCode
                    ),
                    'agreement_template' => $this->buildTemplateReference(
                        $sourceModule,
                        $methodCode,
                        $agreementTemplateCode
                    ),
                    'guide_route' => $this->buildGuideRoute($methodCode),
                    'policy_route' => $this->buildPolicyRoute($methodCode),
                    'agreement_route' => $this->buildAgreementRoute($methodCode),
                    'guide_url' => $this->buildGuideUrl($methodCode),
                    'policy_url' => $this->buildPolicyUrl($methodCode),
                    'agreement_url' => $this->buildAgreementUrl($methodCode),
                    'sort_order' => (int) $guide->getSortOrder(),
                    'source_module' => $sourceModule,
                    'class_name' => $className,
                ];
            } catch (\Throwable $exception) {
                w_log_error('Instantiate payment customer guide failed: ' . $className . ', error: ' . $exception->getMessage());
            }
        }

        $this->cachedEntries = $entries;
        $this->cachedExtendsMtime = $currentMtime;

        return $entries;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scanGuideDefinitions(bool $forceReload = false): array
    {
        $definitions = [];

        try {
            $extendedBy = ExtendsData::getExtendedBy('Weline_Payment', $forceReload);
            if ($extendedBy === []) {
                return [];
            }

            $modules = Env::getInstance()->getModuleList();
            foreach ($extendedBy as $sourceModule => $extensions) {
                $sourceModuleInfo = $modules[$sourceModule] ?? null;
                if (empty($sourceModuleInfo) || !($sourceModuleInfo['status'] ?? false)) {
                    continue;
                }

                foreach ((array) $extensions as $extension) {
                    if (($extension['is_sticker_extension'] ?? false) === true) {
                        continue;
                    }

                    $relativePath = (string) ($extension['relative_path'] ?? '');
                    if (!str_starts_with($relativePath, self::EXTENSION_PATH)) {
                        continue;
                    }

                    $sourceFile = (string) ($extension['source_file'] ?? '');
                    if ($sourceFile === '' || !is_file($sourceFile)) {
                        continue;
                    }

                    $className = $this->getClassNameFromFile($sourceFile);
                    if ($className === null) {
                        continue;
                    }

                    require_once $sourceFile;
                    if (!class_exists($className)) {
                        continue;
                    }

                    try {
                        $reflection = new \ReflectionClass($className);
                        if (!$reflection->implementsInterface(PaymentCustomerGuideInterface::class)) {
                            continue;
                        }
                    } catch (\Throwable $exception) {
                        w_log_error('Check payment customer guide failed: ' . $className . ', error: ' . $exception->getMessage());
                        continue;
                    }

                    $definitions[] = [
                        'class_name' => $className,
                        'source_module' => (string) $sourceModule,
                        'source_file' => $sourceFile,
                        'relative_path' => $relativePath,
                    ];
                }
            }
        } catch (\Throwable $exception) {
            w_log_error('Scan payment customer guides failed: ' . $exception->getMessage());
        }

        return $definitions;
    }

    private function buildTemplateReference(string $sourceModule, string $methodCode, string $templateCode): string
    {
        return $sourceModule . '::templates/Frontend/guide/payment/' . $methodCode . '/' . $templateCode . '.phtml';
    }

    private function normalizeMethodCode(string $methodCode): string
    {
        $methodCode = strtolower(trim($methodCode));
        if ($methodCode === '') {
            return '';
        }

        if (!preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $methodCode)) {
            throw new \InvalidArgumentException('payment_customer_guide_method_code_invalid:' . $methodCode);
        }

        return $methodCode;
    }

    private function normalizeTemplateCode(string $templateCode, string $fallback): string
    {
        $templateCode = strtolower(trim($templateCode));
        if ($templateCode === '') {
            return $fallback;
        }

        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $templateCode)) {
            throw new \InvalidArgumentException('payment_customer_guide_template_code_invalid:' . $templateCode);
        }

        return $templateCode;
    }

    private function normalizeLayoutType(string $layoutType, string $fallback): string
    {
        $layoutType = strtolower(trim($layoutType));
        if ($layoutType === '') {
            return $fallback;
        }

        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $layoutType)) {
            throw new \InvalidArgumentException('payment_customer_guide_layout_type_invalid:' . $layoutType);
        }

        return $layoutType;
    }

    private function resolveModuleNameFromClass(string $className): string
    {
        $parts = explode('\\', $className);
        if (count($parts) < 2) {
            return 'Weline_Payment';
        }

        return $parts[0] . '_' . $parts[1];
    }

    private function getClassNameFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        if (!preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch)) {
            return null;
        }

        if (!preg_match('/(?:final\s+)?class\s+(\w+)/', $content, $classMatch)) {
            return null;
        }

        return trim($namespaceMatch[1]) . '\\' . trim($classMatch[1]);
    }
}

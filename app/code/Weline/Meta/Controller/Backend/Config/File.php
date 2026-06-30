<?php
declare(strict_types=1);

namespace Weline\Meta\Controller\Backend\Config;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Meta\Helper\MetaTranslation;
use Weline\Meta\Model\MetaLocal;
use Weline\Meta\Model\Meta as MetaModel;
use Weline\Meta\Model\MetaConfig;
use Weline\Meta\Service\ParamDefinitionNormalizer;

class File extends BackendController
{
    public function index()
    {
        $namespace = trim((string)$this->request->getParam('namespace', ''));
        $area = $this->request->getParam('area', '');
        $scope = $this->request->getParam('scope', 'default');
        $locale = $this->request->getParam('locale', Cookie::getLangLocal() ?? 'zh_Hans_CN');
        $type = $this->request->getParam('type', '');
        $category = $this->request->getParam('category', '');
        $identityId = $this->request->getParam('identity_id');

        // 获取所有可用的命名空间列表
        /** @var MetaModel $metaModel */
        $metaModel = ObjectManager::getInstance(MetaModel::class);
        $namespaces = $metaModel->reset()
            ->fields(MetaModel::schema_fields_NAMESPACE)
            ->where(MetaModel::schema_fields_NAMESPACE, null, 'IS NOT NULL')
            ->where(MetaModel::schema_fields_NAMESPACE, '', '!=')
            ->group(MetaModel::schema_fields_NAMESPACE)
            ->order(MetaModel::schema_fields_NAMESPACE, 'ASC')
            ->select()
            ->fetch();
        
        $namespaceList = [];
        foreach ($namespaces->getItems() as $item) {
            $ns = (string)$item->getData(MetaModel::schema_fields_NAMESPACE);
            if ($ns) {
                $namespaceList[] = $ns;
            }
        }
        
        // 如果没有指定命名空间，默认选择第一个
        if (empty($namespace) && !empty($namespaceList)) {
            $namespace = $namespaceList[0];
        }

        // ===== 根据当前命名空间/区域获取唯一的类型、分类、Scope、语言 =====
        // 1. 类型列表（来自 Meta 表）
        $typeOptions = [];
        if (!empty($namespace)) {
            $typeResult = $metaModel->reset()
                ->fields(MetaModel::schema_fields_META_TYPE)
                ->where(MetaModel::schema_fields_NAMESPACE, $namespace)
                ->where(MetaModel::schema_fields_META_TYPE, null, 'IS NOT NULL')
                ->where(MetaModel::schema_fields_META_TYPE, '', '!=')
                ->group(MetaModel::schema_fields_META_TYPE)
                ->order(MetaModel::schema_fields_META_TYPE, 'ASC')
                ->select()
                ->fetch();

            foreach ($typeResult->getItems() as $item) {
                $t = (string)$item->getData(MetaModel::schema_fields_META_TYPE);
                if ($t && !in_array($t, $typeOptions, true)) {
                    $typeOptions[] = $t;
                }
            }
        }

        // 2. 分类列表（来自 Meta 表）
        $categoryOptions = [];
        if (!empty($namespace)) {
            $categoryResult = $metaModel->reset()
                ->fields(MetaModel::schema_fields_CATEGORY)
                ->where(MetaModel::schema_fields_NAMESPACE, $namespace)
                ->where(MetaModel::schema_fields_CATEGORY, null, 'IS NOT NULL')
                ->where(MetaModel::schema_fields_CATEGORY, '', '!=')
                ->group(MetaModel::schema_fields_CATEGORY)
                ->order(MetaModel::schema_fields_CATEGORY, 'ASC')
                ->select()
                ->fetch();

            foreach ($categoryResult->getItems() as $item) {
                $c = (string)$item->getData(MetaModel::schema_fields_CATEGORY);
                if ($c && !in_array($c, $categoryOptions, true)) {
                    $categoryOptions[] = $c;
                }
            }
        }

        // 3. Scope 和语言列表（来自 MetaConfig 表）
        $scopeOptions = [];
        $localeOptions = [];
        /** @var MetaConfig $metaConfigModel */
        $metaConfigModel = ObjectManager::getInstance(MetaConfig::class);

        if (!empty($namespace)) {
            // Scope 列表
            $scopeResult = $metaConfigModel->reset()
                ->fields(MetaConfig::schema_fields_SCOPE)
                ->where(MetaConfig::schema_fields_NAMESPACE, $namespace)
                ->where(MetaConfig::schema_fields_SCOPE, null, 'IS NOT NULL')
                ->where(MetaConfig::schema_fields_SCOPE, '', '!=')
                ->group(MetaConfig::schema_fields_SCOPE)
                ->order(MetaConfig::schema_fields_SCOPE, 'ASC')
                ->select()
                ->fetch();

            foreach ($scopeResult->getItems() as $item) {
                $s = (string)$item->getData(MetaConfig::schema_fields_SCOPE);
                if ($s && !in_array($s, $scopeOptions, true)) {
                    $scopeOptions[] = $s;
                }
            }

            // 语言列表
            $localeResult = $metaConfigModel->reset()
                ->fields(MetaConfig::schema_fields_LOCALE)
                ->where(MetaConfig::schema_fields_NAMESPACE, $namespace)
                ->where(MetaConfig::schema_fields_LOCALE, null, 'IS NOT NULL')
                ->where(MetaConfig::schema_fields_LOCALE, '', '!=')
                ->group(MetaConfig::schema_fields_LOCALE)
                ->order(MetaConfig::schema_fields_LOCALE, 'ASC')
                ->select()
                ->fetch();

            foreach ($localeResult->getItems() as $item) {
                $l = (string)$item->getData(MetaConfig::schema_fields_LOCALE);
                if ($l && !in_array($l, $localeOptions, true)) {
                    $localeOptions[] = $l;
                }
            }
        }

        // 确保当前已选值出现在选项中
        if ($type && !in_array($type, $typeOptions, true)) {
            $typeOptions[] = $type;
        }
        if ($category && !in_array($category, $categoryOptions, true)) {
            $categoryOptions[] = $category;
        }
        if ($scope && !in_array($scope, $scopeOptions, true)) {
            $scopeOptions[] = $scope;
        }
        if ($locale && !in_array($locale, $localeOptions, true)) {
            $localeOptions[] = $locale;
        }

        sort($typeOptions);
        sort($categoryOptions);
        sort($scopeOptions);
        sort($localeOptions);

        $this->assign('metaContext', [
            'namespace' => $namespace,
            'area' => $area,
            'scope' => $scope,
            'locale' => $locale,
            'type' => $type,
            'category' => $category,
            'identity_id' => $identityId,
        ]);
        $this->assign('namespaceList', $namespaceList);
        $this->assign('typeOptions', $typeOptions);
        $this->assign('categoryOptions', $categoryOptions);
        $this->assign('scopeOptions', $scopeOptions);
        $this->assign('localeOptions', $localeOptions);

        // 显式指定 templates 目录，匹配实际路径：view/templates/Backend/config/file-meta.phtml
        return $this->fetch('Weline_Meta::templates/Backend/config/file-meta.phtml');
    }

    public function tree()
    {
        $namespaceParam = trim((string)$this->request->getParam('namespace', ''));
        if ($namespaceParam === '') {
            return $this->fetchJson($this->error(__('命名空间参数不能为空')));
        }
        
        // 如果传入的是类似 module.area 的格式，提取出真正的 namespace
        $namespace = $namespaceParam;
        if (strpos($namespaceParam, '.') !== false) {
            $parts = explode('.', $namespaceParam, 2);
            $namespace = $parts[0]; // 提取基础命名空间
        }
        
        $area = $this->request->getParam('area');
        $identityId = $this->request->getParam('identity_id');

        /** @var MetaModel $metaModel */
        $metaModel = ObjectManager::getInstance(MetaModel::class);
        $metaModel->reset()->where(MetaModel::schema_fields_NAMESPACE, $namespace);
        
        // 只查询非 field 类型的记录（文件类型）
        $metaModel->where(MetaModel::schema_fields_META_TYPE, 'field', '!=');
        
        // 只查询文件路径不为空的记录（文件类型）
        $metaModel->where(MetaModel::schema_fields_FILE_PATH, null, 'IS NOT NULL');
        $metaModel->where(MetaModel::schema_fields_FILE_PATH, '', '!=');

        if ($area) {
            $metaModel->where(MetaModel::schema_fields_AREA, $area);
        }
        if ($type = $this->request->getParam('type')) {
            $metaModel->where(MetaModel::schema_fields_META_TYPE, $type);
        }
        if ($category = $this->request->getParam('category')) {
            $metaModel->where(MetaModel::schema_fields_CATEGORY, $category);
        }

        $result = $metaModel->select()->fetch();
        $items = [];
        foreach ($result->getItems() as $meta) {
            $filePath = (string)$meta->getData(MetaModel::schema_fields_FILE_PATH);
            $metaIdentify = (string)$meta->getData(MetaModel::schema_fields_META_IDENTIFY);
            $items[] = [
                'meta_id' => (int)$meta->getData(MetaModel::schema_fields_ID),
                'meta_identify' => $metaIdentify,
                'file_path' => $filePath,
                'file_full_path' => (string)$meta->getData(MetaModel::schema_fields_FILE_FULL_PATH),
                'meta_type' => (string)$meta->getData(MetaModel::schema_fields_META_TYPE),
                'area' => (string)$meta->getData(MetaModel::schema_fields_AREA),
                'category' => (string)$meta->getData(MetaModel::schema_fields_CATEGORY),
                'namespace' => (string)$meta->getData(MetaModel::schema_fields_NAMESPACE),
                'title' => $this->guessNodeTitle($filePath, $metaIdentify),
            ];
        }

        return $this->fetchJson($this->success('', [
            'tree' => $this->buildTree($items),
            'filters' => [
                'namespace' => $namespace,
                'area' => $area ?? '',
                'scope' => $this->request->getParam('scope', 'default'),
                'locale' => $this->request->getParam('locale', Cookie::getLangLocal() ?? 'zh_Hans_CN'),
            ],
        ]));
    }

    public function fileMeta()
    {
        $metaIdentify = trim((string)$this->request->getParam('meta_identify'));
        if ($metaIdentify === '') {
            return $this->fetchJson($this->error(__('参数不完整')));
        }

        $scope = $this->request->getParam('scope', 'default');
        $locale = $this->request->getParam('locale');
        $identityId = $this->request->getParam('identity_id');

        // 直接使用 MetaModel 查询，不依赖 ThemeData（因为这是通用的 Meta 配置管理）
        /** @var MetaModel $metaModel */
        $metaModel = ObjectManager::getInstance(MetaModel::class);
        
        // 根据数据库格式：meta_identify 存储的是完整格式（如 theme.backend.components）
        // namespace='theme', meta_type='component', meta_identify='theme.backend.components'
        // 从 meta_identify 中提取 namespace（格式通常是 namespace.area.type 或 namespace.area.type.identify）
        $namespace = '';
        if (strpos($metaIdentify, '.') !== false) {
            $parts = explode('.', $metaIdentify, 2);
            $namespace = $parts[0]; // 提取基础命名空间
        }
        
        // 查询文件类型的 meta（排除 field 类型，因为 field 是字段定义，不是文件配置）
        $metaModel->reset()->where(MetaModel::schema_fields_META_IDENTIFY, $metaIdentify);
        
        // 如果能够提取到 namespace，添加 namespace 条件以提高查询准确性
        if ($namespace) {
            $metaModel->where(MetaModel::schema_fields_NAMESPACE, $namespace);
        }
        
        // 排除 field 类型
        $metaModel->where(MetaModel::schema_fields_META_TYPE, 'field', '!=');
        $metaRecord = $metaModel->find()->fetch();
        
        if (!$metaRecord->getId()) {
            // 如果没找到，尝试不排除 field 类型再查询一次（用于调试）
            $checkModel = ObjectManager::getInstance(MetaModel::class);
            $checkModel->reset()->where(MetaModel::schema_fields_META_IDENTIFY, $metaIdentify);
            if ($namespace) {
                $checkModel->where(MetaModel::schema_fields_NAMESPACE, $namespace);
            }
            $checkRecord = $checkModel->fetch();
            
            if ($checkRecord->getId()) {
                // 如果找到了 field 类型的记录，说明查询条件有问题
                $foundType = $checkRecord->getData(MetaModel::schema_fields_META_TYPE);
                return $this->fetchJson($this->error(__('找到的 Meta 配置类型不正确：%{meta_identify}，期望文件类型，实际为：%{type}', [
                    'meta_identify' => $metaIdentify,
                    'type' => $foundType
                ])));
            }
            
            // 如果完全没找到，返回错误
            return $this->fetchJson($this->error(__('未找到对应的 Meta 配置：%{meta_identify}。请确认该配置已正确扫描到数据库中。', [
                'meta_identify' => $metaIdentify
            ])));
        }
        
        // 从 meta 记录中获取 namespace（这是最准确的方式）
        $namespace = $metaRecord->getData(MetaModel::schema_fields_NAMESPACE);
        if (empty($namespace)) {
            return $this->fetchJson($this->error(__('Meta 配置缺少命名空间：') . $metaIdentify));
        }
        
        // 构建 meta 数组
        $metaDataValue = $metaRecord->getData(MetaModel::schema_fields_META_DATA);
        $settingValue = $metaRecord->getData(MetaModel::schema_fields_SETTING);
        
        $meta = [
            'meta_id' => $metaRecord->getData(MetaModel::schema_fields_ID),
            'meta_identify' => $metaRecord->getData(MetaModel::schema_fields_META_IDENTIFY),
            'namespace' => $namespace,
            'file_path' => $metaRecord->getData(MetaModel::schema_fields_FILE_PATH),
            'file_full_path' => $metaRecord->getData(MetaModel::schema_fields_FILE_FULL_PATH),
            'area' => $metaRecord->getData(MetaModel::schema_fields_AREA),
            'meta_type' => $metaRecord->getData(MetaModel::schema_fields_META_TYPE),
            'category' => $metaRecord->getData(MetaModel::schema_fields_CATEGORY),
            'meta_data' => (!empty($metaDataValue) && is_string($metaDataValue)) ? (json_decode($metaDataValue, true) ?? []) : [],
            'setting' => (!empty($settingValue) && is_string($settingValue)) ? (json_decode($settingValue, true) ?? []) : [],
        ];

        // 获取文件的 name 和 description（从 field 类型记录）
        $name = '';
        $description = '';
        $nameTranslatable = false;
        $descriptionTranslatable = false;

        /** @var MetaModel $metaModel */
        $metaModel = ObjectManager::getInstance(MetaModel::class);
        
        // 查询 name field
        $nameField = $metaModel->reset()
            ->where(MetaModel::schema_fields_META_IDENTIFY, $metaIdentify . '.name')
            ->where(MetaModel::schema_fields_META_TYPE, 'field')
            ->fetch();
        if ($nameField->getId()) {
            $nameMetaDataValue = $nameField->getData(MetaModel::schema_fields_META_DATA);
            $nameMetaData = (!empty($nameMetaDataValue) && is_string($nameMetaDataValue)) ? (json_decode($nameMetaDataValue, true) ?? []) : [];
            $name = $nameMetaData['attributes']['default'] ?? $nameMetaData['attributes']['name'] ?? '';
            $nameTranslatable = !empty($nameMetaData['attributes']['translate']) || !empty($nameMetaData['attributes']['translatable']);
            
            // 如果支持翻译，获取翻译值
            if ($nameTranslatable && $name) {
                $name = MetaTranslation::getTranslatedValueWithScope(
                    $metaIdentify . '.name',
                    $scope,
                    $locale,
                    $name
                );
            }
        }

        // 查询 description field
        $descField = $metaModel->reset()
            ->where(MetaModel::schema_fields_META_IDENTIFY, $metaIdentify . '.description')
            ->where(MetaModel::schema_fields_META_TYPE, 'field')
            ->fetch();
        if ($descField->getId()) {
            $descMetaDataValue = $descField->getData(MetaModel::schema_fields_META_DATA);
            $descMetaData = (!empty($descMetaDataValue) && is_string($descMetaDataValue)) ? (json_decode($descMetaDataValue, true) ?? []) : [];
            $description = $descMetaData['attributes']['default'] ?? $descMetaData['attributes']['name'] ?? '';
            $descriptionTranslatable = !empty($descMetaData['attributes']['translate']) || !empty($descMetaData['attributes']['translatable']);
            
            // 如果支持翻译，获取翻译值
            if ($descriptionTranslatable && $description) {
                $description = MetaTranslation::getTranslatedValueWithScope(
                    $metaIdentify . '.description',
                    $scope,
                    $locale,
                    $description
                );
            }
        }

        // 从 meta 记录的 setting 字段中获取参数定义
        $definitions = $this->normalizeParamDefinitions($meta['setting']['param'] ?? []);
        
        // 查询参数值（从 MetaConfig 表或 MetaTranslation 中获取）
        $values = [];
        $savedParams = []; // 记录哪些参数已保存（通过检查 m_w_meta_config 表）
        if (!empty($definitions) && !empty($namespace)) {
            /** @var \Weline\Meta\Model\MetaConfig $metaConfigModel */
            $metaConfigModel = ObjectManager::getInstance(\Weline\Meta\Model\MetaConfig::class);
            
            $metaId = $meta['meta_id'];
            
            // 如果没有指定 locale，使用当前语言
            if (empty($locale)) {
                $locale = Cookie::getLangLocal() ?? 'zh_Hans_CN';
            }
            
            foreach (array_keys($definitions) as $paramName) {
                $configKey = 'param.' . $paramName . '.value';
                $paramDef = $definitions[$paramName] ?? [];
                $isTranslatable = !empty($paramDef['translate']) || !empty($paramDef['translatable']);
                $defaultValue = is_array($paramDef) ? ($paramDef['default'] ?? null) : $paramDef;
                
                // 检查参数是否已保存：查询 m_w_meta_config 表中是否存在对应 meta_id 的记录
                $checkQuery = $metaConfigModel->reset()
                    ->where(\Weline\Meta\Model\MetaConfig::schema_fields_NAMESPACE, $namespace)
                    ->where(\Weline\Meta\Model\MetaConfig::schema_fields_CONFIG_KEY, $configKey)
                    ->where(\Weline\Meta\Model\MetaConfig::schema_fields_SCOPE, $scope);
                
                if ($metaId) {
                    $checkQuery->where(\Weline\Meta\Model\MetaConfig::schema_fields_META_ID, $metaId);
                } elseif ($metaIdentify) {
                    $checkQuery->where(\Weline\Meta\Model\MetaConfig::schema_fields_META_IDENTIFY, $metaIdentify);
                } elseif ($identityId !== null && $identityId !== '') {
                    $checkQuery->where(\Weline\Meta\Model\MetaConfig::schema_fields_IDENTIFY_ID, (string)$identityId);
                }
                
                $checkRecord = $checkQuery->find()->fetch();
                $savedParams[$paramName] = $checkRecord->getId() ? true : false;
                
                // 获取参数值
                if ($isTranslatable) {
                    // 可翻译的参数：从 MetaTranslation (I18n Dictionary) 读取
                    $translationKey = $metaIdentify . '.param.' . $paramName . '.value';
                    $translatedValue = MetaTranslation::getTranslatedValueWithScope(
                        $translationKey,
                        $scope,
                        $locale,
                        is_scalar($defaultValue) ? (string)$defaultValue : ''
                    );
                    $values[$paramName] = $translatedValue;
                } else {
                    // 不可翻译的参数：从 MetaConfig 读取（locale 为 null 的记录）
                    $configQuery = $metaConfigModel->reset()
                        ->where(\Weline\Meta\Model\MetaConfig::schema_fields_NAMESPACE, $namespace)
                        ->where(\Weline\Meta\Model\MetaConfig::schema_fields_CONFIG_KEY, $configKey)
                        ->where(\Weline\Meta\Model\MetaConfig::schema_fields_SCOPE, $scope)
                        ->where(\Weline\Meta\Model\MetaConfig::schema_fields_LOCALE, null, 'IS NULL');
                    
                    if ($metaId) {
                        $configQuery->where(\Weline\Meta\Model\MetaConfig::schema_fields_META_ID, $metaId);
                    } elseif ($metaIdentify) {
                        $configQuery->where(\Weline\Meta\Model\MetaConfig::schema_fields_META_IDENTIFY, $metaIdentify);
                    } elseif ($identityId !== null && $identityId !== '') {
                        $configQuery->where(\Weline\Meta\Model\MetaConfig::schema_fields_IDENTIFY_ID, (string)$identityId);
                    }
                    
                    $configRecord = $configQuery->find()->fetch();
                    
                    if ($configRecord->getId()) {
                        $values[$paramName] = $configRecord->getData(\Weline\Meta\Model\MetaConfig::schema_fields_CONFIG_VALUE);
                    }
                }
            }
        }

        $params = [];
        foreach ($definitions as $name => $definition) {
            if (!is_array($definition)) {
                $definition = ['default' => $definition];
            }
            $params[] = [
                'name' => $name,
                'label' => $definition['label'] ?? $definition['name'] ?? $name,
                'description' => $definition['description'] ?? '',
                'default' => $definition['default'] ?? null,
                'type' => $definition['type'] ?? 'string',
                'ui_type' => $definition['ui_type'] ?? $definition['input'] ?? $definition['type'] ?? 'text',
                'input' => $definition['input'] ?? $definition['ui_type'] ?? $definition['type'] ?? 'text',
                'options' => $definition['options'] ?? [],
                'required' => !empty($definition['required']),
                'i18n' => !empty($definition['i18n']) || !empty($definition['translate']) || !empty($definition['translatable']),
                'translate' => !empty($definition['i18n']) || !empty($definition['translate']) || !empty($definition['translatable']),
                'translatable' => !empty($definition['i18n']) || !empty($definition['translate']) || !empty($definition['translatable']),
                'value' => $values[$name] ?? ($definition['default'] ?? null),
                'is_saved' => $savedParams[$name] ?? false, // 标记参数是否已保存
            ];
        }

        return $this->fetchJson($this->success('', [
            'meta' => [
                'meta_id' => $meta['meta_id'] ?? null,
                'meta_identify' => $metaIdentify,
                'file_path' => $meta['file_path'] ?? '',
                'file_full_path' => $meta['file_full_path'] ?? '',
                'area' => $meta['area'] ?? '',
                'meta_type' => $meta['meta_type'] ?? '',
                'category' => $meta['category'] ?? '',
                'name' => $name,
                'description' => $description,
                'name_translatable' => $nameTranslatable,
                'description_translatable' => $descriptionTranslatable,
            ],
            'params' => $params,
            'scope' => $scope,
            'locale' => $locale,
        ]));
    }

    public function save()
    {
        $metaIdentify = trim((string)$this->request->getPost('meta_identify'));
        if ($metaIdentify === '') {
            return $this->fetchJson($this->error(__('参数不完整')));
        }

        $params = (array)$this->request->getPost('params', []);
        $scope = $this->request->getPost('scope', 'default');
        $identityId = $this->request->getPost('identity_id');
        $locale = $this->request->getPost('locale') ?: (Cookie::getLangLocal() ?? 'zh_Hans_CN');

        try {
            // 获取 meta 记录以获取 meta_id 和 namespace
            /** @var MetaModel $metaModel */
            $metaModel = ObjectManager::getInstance(MetaModel::class);
            $metaRecord = $metaModel->reset()
                ->where(MetaModel::schema_fields_META_IDENTIFY, $metaIdentify)
                ->where(MetaModel::schema_fields_META_TYPE, 'field', '!=')
                ->find()
                ->fetch();
            
            if (!$metaRecord->getId()) {
                return $this->fetchJson($this->error(__('未找到对应的 Meta 配置：') . $metaIdentify));
            }
            
            // 从 meta 记录中获取 namespace（这是最准确的方式）
            $namespace = $metaRecord->getData(MetaModel::schema_fields_NAMESPACE);
            if (empty($namespace)) {
                return $this->fetchJson($this->error(__('Meta 配置缺少命名空间：') . $metaIdentify));
            }
            
            $metaId = $metaRecord->getData(MetaModel::schema_fields_ID);
            
            // 获取参数定义，判断哪些参数支持翻译
            $settingValue = $metaRecord->getData(MetaModel::schema_fields_SETTING);
            $setting = (!empty($settingValue) && is_string($settingValue)) ? (json_decode($settingValue, true) ?? []) : [];
            $definitions = $this->normalizeParamDefinitions($setting['param'] ?? []);
            
            // 获取当前已保存的配置值（用于增量保存：只保存修改过的参数）
            /** @var \Weline\Meta\Model\MetaConfig $metaConfigModel */
            $metaConfigModel = ObjectManager::getInstance(\Weline\Meta\Model\MetaConfig::class);
            
            $savedCount = 0;
            $skippedCount = 0;
            
            foreach ($params as $paramName => $value) {
                $paramName = trim((string)$paramName);
                if ($paramName === '') {
                    continue;
                }
                
                $configKey = 'param.' . $paramName . '.value';
                $paramDef = $definitions[$paramName] ?? [];
                $isTranslatable = !empty($paramDef['translate']) || !empty($paramDef['translatable']);
                
                // 获取当前值（用于增量保存判断）
                $currentValue = null;
                if ($isTranslatable) {
                    // 对于可翻译参数，从翻译表获取当前值
                    $currentValue = MetaTranslation::getTranslatedValueWithScope(
                        $metaIdentify . '.param.' . $paramName . '.value',
                        $scope,
                        $locale,
                        $paramDef['default'] ?? ''
                    );
                } else {
                    // 对于不可翻译参数，从 MetaConfig 获取当前值
                    $currentValue = $metaConfigModel->getConfig(
                        $identityId,
                        $namespace,
                        $configKey,
                        $scope,
                        null, // locale
                        null, // defaultLocale
                        $metaId,
                        $metaIdentify
                    );
                }
                
                // 增量保存：只保存修改过的参数
                $newValue = (string)$value;
                $currentValueStr = $currentValue !== null ? (string)$currentValue : '';
                
                // 如果值没有变化，跳过保存
                if ($newValue === $currentValueStr) {
                    $skippedCount++;
                    continue;
                }
                
                if ($isTranslatable) {
                    // 支持翻译的参数，保存到 I18n Dictionary（通过 MetaTranslation）
                    MetaTranslation::setTranslatedValueWithScope(
                        $metaIdentify . '.param.' . $paramName . '.value',
                        $newValue,
                        $scope,
                        $locale
                    );
                    $savedCount++;
                } else {
                    // 不支持翻译的参数，保存到 MetaConfig（locale 为 null）
                    try {
                        $metaConfigModel->setConfig(
                            $identityId, // identify_id（实体ID，如主题ID等）
                            $namespace,
                            $configKey,
                            $newValue,
                            $scope,
                            null, // locale
                            $metaId,
                            $metaIdentify
                        );
                        $savedCount++;
                    } catch (\Throwable $e) {
                        // 记录错误但继续处理其他参数
                        w_log_error("MetaConfig save error for {$configKey}: " . $e->getMessage());
                        throw new \Exception(__('保存参数 %{param} 失败：%{error}', [
                            'param' => $paramName,
                            'error' => $e->getMessage()
                        ]));
                    }
                }
            }
            
            $message = __('配置已保存');
            if ($savedCount > 0 || $skippedCount > 0) {
                $message = sprintf(__('配置已保存（已保存：%d，跳过：%d）'), $savedCount, $skippedCount);
            }
            
            return $this->fetchJson($this->success($message));
        } catch (\Throwable $e) {
            return $this->fetchJson($this->error(__('保存失败：') . $e->getMessage()));
        }
    }

    public function paramTranslation()
    {
        $metaIdentify = trim((string)$this->request->getParam('meta_identify'));
        $paramName = trim((string)$this->request->getParam('param'));
        if ($metaIdentify === '' || $paramName === '') {
            return $this->fetchJson($this->error(__('参数不完整')));
        }

        $scope = $this->request->getParam('scope', 'default');
        $currentLocale = Cookie::getLangLocal() ?? 'zh_Hans_CN';

        try {
            // 检查是否是 name 或 description 字段
            if ($paramName === 'name' || $paramName === 'description') {
                // 查询 field 类型的记录
                /** @var MetaModel $metaModel */
                $metaModel = ObjectManager::getInstance(MetaModel::class);
                $fieldMeta = $metaModel->reset()
                    ->where(MetaModel::schema_fields_META_IDENTIFY, $metaIdentify . '.' . $paramName)
                    ->where(MetaModel::schema_fields_META_TYPE, 'field')
                    ->find()
                    ->fetch();
                
                if (!$fieldMeta->getId()) {
                    return $this->fetchJson($this->error(__('未找到对应的字段配置：%{param}', ['param' => $paramName])));
                }

                $fieldMetaDataValue = $fieldMeta->getData(MetaModel::schema_fields_META_DATA);
                $metaData = (!empty($fieldMetaDataValue) && is_string($fieldMetaDataValue)) ? (json_decode($fieldMetaDataValue, true) ?? []) : [];
                $attributes = $metaData['attributes'] ?? [];
                $defaultValue = $attributes['default'] ?? $attributes['name'] ?? '';
                $isTranslatable = !empty($attributes['translate']) || !empty($attributes['translatable']);

                if (!$isTranslatable) {
                    return $this->fetchJson($this->error(__('该字段未启用多语言：%{param}', ['param' => $paramName])));
                }

                $translationKey = $metaIdentify . '.' . $paramName;
            } else {
                // 处理普通参数
                /** @var MetaModel $metaModel */
                $metaModel = ObjectManager::getInstance(MetaModel::class);
                $metaRecord = $metaModel->reset()
                    ->where(MetaModel::schema_fields_META_IDENTIFY, $metaIdentify)
                    ->where(MetaModel::schema_fields_META_TYPE, 'field', '!=')
                    ->find()
                    ->fetch();
                
                if (!$metaRecord->getId()) {
                    return $this->fetchJson($this->error(__('未找到对应的 Meta 配置：%{meta_identify}', ['meta_identify' => $metaIdentify])));
                }
                
                $settingValue = $metaRecord->getData(MetaModel::schema_fields_SETTING);
                $setting = (!empty($settingValue) && is_string($settingValue)) ? (json_decode($settingValue, true) ?? []) : [];
                $definitions = $this->normalizeParamDefinitions($setting['param'] ?? []);
                $definition = $definitions[$paramName] ?? null;
                
                if (!$definition) {
                    return $this->fetchJson($this->error(__('未找到参数定义：%{param}', ['param' => $paramName])));
                }

                $defaultValue = is_array($definition) ? ($definition['default'] ?? null) : $definition;
                $translationKey = $metaIdentify . '.param.' . $paramName . '.value';
                $attributes = is_array($definition) ? $definition : [];
            }

            // 获取所有已安装的语言
            /** @var \Weline\I18n\Model\Locale $localeModel */
            $localeModel = ObjectManager::getInstance(\Weline\I18n\Model\Locale::class);
            $locales = $localeModel->reset()
                ->where(\Weline\I18n\Model\Locale::schema_fields_IS_INSTALL, 1)
                ->order(\Weline\I18n\Model\Locale::schema_fields_CODE, 'ASC')
                ->select()
                ->fetch();
            
            $translations = [];
            $currentLocaleValue = null;
            foreach ($locales->getItems() as $locale) {
                $localeCode = (string)$locale->getData(\Weline\I18n\Model\Locale::schema_fields_CODE);
                if ($localeCode) {
                    // 获取语言名称
                    /** @var \Weline\I18n\Model\Locale\Name $nameModel */
                    $nameModel = ObjectManager::getInstance(\Weline\I18n\Model\Locale\Name::class);
                    $nameRecord = $nameModel->reset()
                        ->where(\Weline\I18n\Model\Locale\Name::schema_fields_LOCALE_CODE, $localeCode)
                        ->where(\Weline\I18n\Model\Locale\Name::schema_fields_DISPLAY_LOCALE_CODE, $currentLocale)
                        ->find()
                        ->fetch();
                    
                    $localeName = $nameRecord->getId() 
                        ? (string)$nameRecord->getData(\Weline\I18n\Model\Locale\Name::schema_fields_DISPLAY_NAME)
                        : $localeCode;
                    
                    // 从 w_meta_local 表获取翻译值
                    /** @var MetaLocal $localModel */
                    $localModel = ObjectManager::getInstance(MetaLocal::class);
                    
                    // 获取 meta_id
                    /** @var MetaModel $metaModelForId */
                    $metaModelForId = ObjectManager::getInstance(MetaModel::class);
                    $metaForId = $metaModelForId->reset()
                        ->where(MetaModel::schema_fields_META_IDENTIFY, $metaIdentify)
                        ->find()
                        ->fetch();
                    $metaId = $metaForId->getId() ? (int)$metaForId->getId() : null;
                    
                    // 确定配置键
                    $configKey = $paramName;
                    if ($paramName !== 'name' && $paramName !== 'description') {
                        $configKey = 'param.' . $paramName;
                    }
                    
                    $translatedValue = '';
                    if ($metaId) {
                        $localModel->reset()
                            ->where(MetaLocal::schema_fields_META_ID, $metaId)
                            ->where(MetaLocal::schema_fields_LOCALE_CODE, $localeCode)
                            ->where(MetaLocal::schema_fields_CONFIG_KEY, $configKey)
                            ->find()
                            ->fetch();
                        
                        if ($localModel->getMetaId()) {
                            $translatedValue = $localModel->getConfigValue() ?? '';
                        }
                    }
                    
                    if ($localeCode === $currentLocale) {
                        $currentLocaleValue = $translatedValue ?: (is_scalar($defaultValue) ? (string)$defaultValue : '');
                    }
                    
                    $translations[] = [
                        'code' => $localeCode,
                        'name' => $localeName,
                        'value' => $translatedValue,
                    ];
                }
            }

            // 默认值使用当前语言的翻译值（如果存在），否则使用参数定义中的默认值
            $finalDefaultValue = $currentLocaleValue ?: (is_scalar($defaultValue) ? (string)$defaultValue : '');

            return $this->fetchJson($this->success('', [
                'meta_identify' => $metaIdentify,
                'param' => $paramName,
                'scope' => $scope,
                'default_value' => $finalDefaultValue,
                'label' => $attributes['name'] ?? $paramName,
                'description' => $attributes['description'] ?? '',
                'translations' => $translations,
            ]));
        } catch (\Throwable $e) {
            return $this->fetchJson($this->error(__('获取翻译数据失败：') . $e->getMessage()));
        }
    }

    public function paramTranslationSave()
    {
        $metaIdentify = trim((string)$this->request->getPost('meta_identify'));
        $paramName = trim((string)$this->request->getPost('param'));
        if ($metaIdentify === '' || $paramName === '') {
            return $this->fetchJson($this->error(__('参数不完整')));
        }

        $scope = $this->request->getPost('scope', 'default');
        $translations = (array)$this->request->getPost('translations', []);

        try {
            // 获取 meta_id
            /** @var MetaModel $metaModel */
            $metaModel = ObjectManager::getInstance(MetaModel::class);
            $meta = $metaModel->reset()
                ->where(MetaModel::schema_fields_META_IDENTIFY, $metaIdentify)
                ->find()
                ->fetch();
            
            if (!$meta->getId()) {
                return $this->fetchJson($this->error(__('未找到对应的 Meta 配置：%{meta_identify}', ['meta_identify' => $metaIdentify])));
            }
            
            $metaId = (int)$meta->getId();
            
            // 确定配置键
            // name/description 直接作为 config_key，参数则为 param.{paramName}
            $configKey = $paramName;
            if ($paramName !== 'name' && $paramName !== 'description') {
                $configKey = 'param.' . $paramName;
            }
            
            // 保存所有语言的翻译到 w_meta_local 表（每个语言一行）
            $savedCount = 0;
            foreach ($translations as $localeCode => $value) {
                $localeCode = trim((string)$localeCode);
                $value = trim((string)$value);
                
                if ($localeCode === '') {
                    continue;
                }
                
                // 如果值为空，跳过（不保存空翻译）
                if ($value === '') {
                    continue;
                }
                
                /** @var MetaLocal $localModel */
                $localModel = ObjectManager::getInstance(MetaLocal::class);
                
                // 加载现有记录（按 meta_id + locale_code + config_key 查找）
                $localModel->reset()
                    ->where(MetaLocal::schema_fields_META_ID, $metaId)
                    ->where(MetaLocal::schema_fields_LOCALE_CODE, $localeCode)
                    ->where(MetaLocal::schema_fields_CONFIG_KEY, $configKey)
                    ->find()
                    ->fetch();
                
                if (!$localModel->getMetaId()) {
                    // 记录不存在，创建新记录
                    $localModel = ObjectManager::make(MetaLocal::class);
                    $localModel->setMetaId($metaId);
                    $localModel->setMetaIdentify($metaIdentify);
                    $localModel->setLocaleCode($localeCode);
                    $localModel->setConfigKey($configKey);
                }
                
                // 设置翻译值
                $localModel->setConfigValue($value);
                
                $localModel->forceCheck()->save();
                $savedCount++;
            }
            
            return $this->fetchJson($this->success(__('翻译已保存（共 %{count} 条）', ['count' => $savedCount])));
        } catch (\Throwable $e) {
            return $this->fetchJson($this->error(__('保存翻译失败：') . $e->getMessage()));
        }
    }

    /**
     * 获取所有已安装的语言列表
     */
    public function getLocales()
    {
        try {
            /** @var \Weline\I18n\Model\Locale $localeModel */
            $localeModel = ObjectManager::getInstance(\Weline\I18n\Model\Locale::class);
            $locales = $localeModel->reset()
                ->where(\Weline\I18n\Model\Locale::schema_fields_IS_INSTALL, 1)
                ->order(\Weline\I18n\Model\Locale::schema_fields_CODE, 'ASC')
                ->select()
                ->fetch();
            
            $localeList = [];
            foreach ($locales->getItems() as $locale) {
                $code = (string)$locale->getData(\Weline\I18n\Model\Locale::schema_fields_CODE);
                if ($code) {
                    // 获取语言名称
                    /** @var \Weline\I18n\Model\Locale\Name $nameModel */
                    $nameModel = ObjectManager::getInstance(\Weline\I18n\Model\Locale\Name::class);
                    $nameRecord = $nameModel->reset()
                        ->where(\Weline\I18n\Model\Locale\Name::schema_fields_LOCALE_CODE, $code)
                        ->where(\Weline\I18n\Model\Locale\Name::schema_fields_DISPLAY_LOCALE_CODE, Cookie::getLangLocal() ?? 'zh_Hans_CN')
                        ->find()
                        ->fetch();
                    
                    $name = $nameRecord->getId() 
                        ? (string)$nameRecord->getData(\Weline\I18n\Model\Locale\Name::schema_fields_DISPLAY_NAME)
                        : $code;
                    
                    $localeList[] = [
                        'code' => $code,
                        'name' => $name,
                    ];
                }
            }
            
            return $this->fetchJson($this->success('', ['locales' => $localeList]));
        } catch (\Throwable $e) {
            return $this->fetchJson($this->error(__('获取语言列表失败：') . $e->getMessage()));
        }
    }

    /**
     * 获取 Meta name/description 的翻译数据
     */
    public function metaNameDescriptionTranslation()
    {
        $metaIdentify = trim((string)$this->request->getParam('meta_identify'));
        $field = trim((string)$this->request->getParam('field')); // name 或 description
        $scope = $this->request->getParam('scope', 'default');
        
        if ($metaIdentify === '' || ($field !== 'name' && $field !== 'description')) {
            return $this->fetchJson($this->error(__('参数不完整')));
        }
        
        try {
            // 获取 meta 记录和 meta_id
            /** @var MetaModel $metaModel */
            $metaModel = ObjectManager::getInstance(MetaModel::class);
            $metaRecord = $metaModel->reset()
                ->where(MetaModel::schema_fields_META_IDENTIFY, $metaIdentify)
                ->find()
                ->fetch();
            
            if (!$metaRecord->getId()) {
                return $this->fetchJson($this->error(__('未找到对应的 Meta 配置：%{meta_identify}', ['meta_identify' => $metaIdentify])));
            }
            
            $metaId = (int)$metaRecord->getId();
            
            // 获取字段的默认值
            $fieldMeta = $metaModel->reset()
                ->where(MetaModel::schema_fields_META_IDENTIFY, $metaIdentify . '.' . $field)
                ->where(MetaModel::schema_fields_META_TYPE, 'field')
                ->find()
                ->fetch();
            
            if (!$fieldMeta->getId()) {
                return $this->fetchJson($this->error(__('未找到对应的字段配置：%{field}', ['field' => $field])));
            }
            
            $fieldMetaDataValue = $fieldMeta->getData(MetaModel::schema_fields_META_DATA);
            $metaData = (!empty($fieldMetaDataValue) && is_string($fieldMetaDataValue)) ? (json_decode($fieldMetaDataValue, true) ?? []) : [];
            $attributes = $metaData['attributes'] ?? [];
            $defaultValue = $attributes['default'] ?? $attributes['name'] ?? '';
            $isTranslatable = !empty($attributes['translate']) || !empty($attributes['translatable']);
            
            if (!$isTranslatable) {
                return $this->fetchJson($this->error(__('该字段未启用多语言：%{field}', ['field' => $field])));
            }
            
            // 获取所有已安装的语言
            /** @var \Weline\I18n\Model\Locale $localeModel */
            $localeModel = ObjectManager::getInstance(\Weline\I18n\Model\Locale::class);
            $locales = $localeModel->reset()
                ->where(\Weline\I18n\Model\Locale::schema_fields_IS_INSTALL, 1)
                ->order(\Weline\I18n\Model\Locale::schema_fields_CODE, 'ASC')
                ->select()
                ->fetch();
            
            $translations = [];
            foreach ($locales->getItems() as $locale) {
                $localeCode = (string)$locale->getData(\Weline\I18n\Model\Locale::schema_fields_CODE);
                if ($localeCode) {
                    // 获取语言名称
                    /** @var \Weline\I18n\Model\Locale\Name $nameModel */
                    $nameModel = ObjectManager::getInstance(\Weline\I18n\Model\Locale\Name::class);
                    $nameRecord = $nameModel->reset()
                        ->where(\Weline\I18n\Model\Locale\Name::schema_fields_LOCALE_CODE, $localeCode)
                        ->where(\Weline\I18n\Model\Locale\Name::schema_fields_DISPLAY_LOCALE_CODE, Cookie::getLangLocal() ?? 'zh_Hans_CN')
                        ->find()
                        ->fetch();
                    
                    $localeName = $nameRecord->getId() 
                        ? (string)$nameRecord->getData(\Weline\I18n\Model\Locale\Name::schema_fields_DISPLAY_NAME)
                        : $localeCode;
                    
                    // 从 w_meta_local 表获取翻译值
                    /** @var MetaLocal $metaLocalModel */
                    $metaLocalModel = ObjectManager::getInstance(MetaLocal::class);
                    $metaLocalModel->reset()
                        ->where(MetaLocal::schema_fields_META_ID, $metaId)
                        ->where(MetaLocal::schema_fields_LOCALE_CODE, $localeCode)
                        ->where(MetaLocal::schema_fields_CONFIG_KEY, $field)
                        ->find()
                        ->fetch();
                    
                    $translatedValue = '';
                    if ($metaLocalModel->getMetaId()) {
                        $translatedValue = $metaLocalModel->getConfigValue() ?? '';
                    }
                    
                    $translations[] = [
                        'code' => $localeCode,
                        'name' => $localeName,
                        'value' => $translatedValue,
                    ];
                }
            }
            
            return $this->fetchJson($this->success('', [
                'meta_identify' => $metaIdentify,
                'field' => $field,
                'scope' => $scope,
                'default_value' => $defaultValue,
                'label' => $attributes['name'] ?? $field,
                'description' => $attributes['description'] ?? '',
                'translations' => $translations,
            ]));
        } catch (\Throwable $e) {
            return $this->fetchJson($this->error(__('获取翻译数据失败：') . $e->getMessage()));
        }
    }

    /**
     * 保存 Meta name/description 的翻译
     */
    public function metaNameDescriptionTranslationSave()
    {
        $metaIdentify = trim((string)$this->request->getPost('meta_identify'));
        $field = trim((string)$this->request->getPost('field')); // name 或 description
        $scope = $this->request->getPost('scope', 'default');
        $translations = (array)$this->request->getPost('translations', []);
        
        if ($metaIdentify === '' || ($field !== 'name' && $field !== 'description')) {
            return $this->fetchJson($this->error(__('参数不完整')));
        }
        
        try {
            // 获取 meta 记录和 meta_id
            /** @var MetaModel $metaModel */
            $metaModel = ObjectManager::getInstance(MetaModel::class);
            $metaRecord = $metaModel->reset()
                ->where(MetaModel::schema_fields_META_IDENTIFY, $metaIdentify)
                ->find()
                ->fetch();
            
            if (!$metaRecord->getId()) {
                return $this->fetchJson($this->error(__('未找到对应的 Meta 配置：%{meta_identify}', ['meta_identify' => $metaIdentify])));
            }
            
            $metaId = (int)$metaRecord->getId();
            
            // 验证字段是否支持翻译
            $fieldMeta = $metaModel->reset()
                ->where(MetaModel::schema_fields_META_IDENTIFY, $metaIdentify . '.' . $field)
                ->where(MetaModel::schema_fields_META_TYPE, 'field')
                ->find()
                ->fetch();
            
            if (!$fieldMeta->getId()) {
                return $this->fetchJson($this->error(__('未找到对应的字段配置：%{field}', ['field' => $field])));
            }
            
            $fieldMetaDataValue = $fieldMeta->getData(MetaModel::schema_fields_META_DATA);
            $metaData = (!empty($fieldMetaDataValue) && is_string($fieldMetaDataValue)) ? (json_decode($fieldMetaDataValue, true) ?? []) : [];
            $attributes = $metaData['attributes'] ?? [];
            $defaultValue = $attributes['default'] ?? $attributes['name'] ?? '';
            $isTranslatable = !empty($attributes['translate']) || !empty($attributes['translatable']);
            
            if (!$isTranslatable) {
                return $this->fetchJson($this->error(__('该字段未启用多语言：%{field}', ['field' => $field])));
            }
            
            // 保存翻译到 w_meta_local 表
            $savedCount = 0;
            foreach ($translations as $localeCode => $value) {
                $localeCode = trim((string)$localeCode);
                $value = trim((string)$value);
                
                if ($localeCode === '') {
                    continue;
                }
                
                // 如果值为空，跳过（不保存空翻译）
                if ($value === '') {
                    continue;
                }
                
                /** @var MetaLocal $metaLocalModel */
                $metaLocalModel = ObjectManager::getInstance(MetaLocal::class);
                
                // 加载现有记录（按 meta_id + locale_code + config_key 查找）
                $metaLocalModel->reset()
                    ->where(MetaLocal::schema_fields_META_ID, $metaId)
                    ->where(MetaLocal::schema_fields_LOCALE_CODE, $localeCode)
                    ->where(MetaLocal::schema_fields_CONFIG_KEY, $field)
                    ->find()
                    ->fetch();
                
                if (!$metaLocalModel->getMetaId()) {
                    // 记录不存在，创建新记录
                    $metaLocalModel = ObjectManager::make(MetaLocal::class);
                    $metaLocalModel->setMetaId($metaId);
                    $metaLocalModel->setMetaIdentify($metaIdentify);
                    $metaLocalModel->setLocaleCode($localeCode);
                    $metaLocalModel->setConfigKey($field);
                }
                
                // 保存翻译值
                $metaLocalModel->setConfigValue($value);
                
                $metaLocalModel->forceCheck()->save();
                $savedCount++;
            }
            
            return $this->fetchJson($this->success(__('翻译已保存（共 %{count} 条）', ['count' => $savedCount])));
        } catch (\Throwable $e) {
            return $this->fetchJson($this->error(__('保存翻译失败：') . $e->getMessage()));
        }
    }

    private function normalizeParamDefinitions(array $definitions): array
    {
        if (empty($definitions)) {
            return [];
        }

        /** @var ParamDefinitionNormalizer $normalizer */
        $normalizer = ObjectManager::getInstance(ParamDefinitionNormalizer::class);
        return $normalizer->normalizeDefinitions($definitions);
    }

    private function guessNodeTitle(string $filePath, string $metaIdentify): string
    {
        if ($filePath) {
            $normalized = str_replace('\\', '/', $filePath);
            $segments = array_filter(explode('/', $normalized));
            if (!empty($segments)) {
                return end($segments);
            }
        }

        $parts = explode('.', $metaIdentify);
        return end($parts) ?: $metaIdentify;
    }

    private function buildTree(array $items): array
    {
        $tree = [];
        $seenIdentifies = []; // 按 meta_identify 去重（因为查询时已排除 field，每个文件应该只有一条记录）
        
        foreach ($items as $item) {
            // 按 meta_identify 去重：同一个逻辑配置只展示一次
            $metaIdentify = $item['meta_identify'];
            if (isset($seenIdentifies[$metaIdentify])) {
                continue;
            }
            $seenIdentifies[$metaIdentify] = true;

            $path = $item['file_path'] ?: str_replace('.', '/', $item['meta_identify']);
            $normalizedPath = str_replace('\\', '/', $path);
            $segments = array_filter(explode('/', $normalizedPath));
            if (empty($segments)) {
                $segments[] = $item['title'];
            }

            $this->insertTreeNode($tree, $segments, $item);
        }

        return $this->treeToArray($tree);
    }

    private function insertTreeNode(array &$tree, array $segments, array $payload, string $prefix = ''): void
    {
        // 如果只剩下最后一个段，直接创建文件节点（最后一个段就是文件名）
        if (count($segments) === 1) {
            $fileName = $segments[0];
            // 使用 meta_identify 作为 key（因为查询时已排除 field，每个文件应该只有一条记录）
            $key = $payload['meta_identify'];
            $tree[$key] = [
                'title' => $fileName,
                'type' => 'file',
                'meta_identify' => $payload['meta_identify'],
                'meta_type' => $payload['meta_type'],
                'area' => $payload['area'],
                'category' => $payload['category'],
                'file_path' => $payload['file_path'],
                'file_full_path' => $payload['file_full_path'],
                'children' => [],
            ];
            return;
        }

        // 如果 segments 为空，说明路径解析有问题，使用 title 作为文件名
        if (empty($segments)) {
            $key = $payload['meta_identify'];
            $tree[$key] = [
                'title' => $payload['title'],
                'type' => 'file',
                'meta_identify' => $payload['meta_identify'],
                'meta_type' => $payload['meta_type'],
                'area' => $payload['area'],
                'category' => $payload['category'],
                'file_path' => $payload['file_path'],
                'file_full_path' => $payload['file_full_path'],
                'children' => [],
            ];
            return;
        }

        // 还有多个段，取出第一个作为目录
        $segment = array_shift($segments);
        $currentPath = $prefix === '' ? $segment : $prefix . '/' . $segment;
        $key = $currentPath ?: $segment;

        if (!isset($tree[$key])) {
            $tree[$key] = [
                'title' => $segment,
                'type' => 'dir',
                'path' => $currentPath,
                'children' => [],
            ];
        }

        $this->insertTreeNode($tree[$key]['children'], $segments, $payload, $currentPath);
    }

    private function treeToArray(array $tree): array
    {
        $result = [];
        foreach ($tree as $node) {
            $children = $node['children'] ?? [];
            if (!empty($children)) {
                $node['children'] = $this->treeToArray($children);
            }
            $result[] = $node;
        }
        return $result;
    }
}


<?php
/**
 * DataTable 综合功能测试控制器
 * 
 * 此控制器用于测试DataTable模块的所有核心功能：
 * 1. 基本表格功能
 * 2. 表单功能
 * 3. 字段类型支持
 * 4. 多模型查询（JOIN）
 * 5. 自动生成功能
 * 6. 属性继承机制
 * 7. 过滤和搜索
 * 8. 排序和分页
 * 9. CRUD操作
 */

namespace Weline\DataTable\Controller\Backend\Test;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Acl\Acl;
use Weline\Backend\Block\ThemeConfig;
use Weline\DataTable\Taglib\Table;
use Weline\DataTable\Taglib\TableHeader;
use Weline\DataTable\Taglib\TableFilter;
use Weline\DataTable\Taglib\Field;
use Weline\DataTable\Taglib\Form;
use Weline\DataTable\Helper\TableContext;

#[Acl('Weline_DataTable::datatable_test_comprehensive', 'DataTable综合测试', 'mdi mdi-table-test', 'DataTable模块综合功能测试中心', 'Weline_DataTable::datatable_module')]
class Comprehensive extends BackendController
{
    /**
     * 初始化主题配置
     */
    public function __init()
    {
        parent::__init();
        $this->initThemeConfig();
    }
    
    /**
     * 初始化主题配置
     */
    private function initThemeConfig()
    {
        /** @var ThemeConfig $themeConfig */
        $themeConfig = ObjectManager::getInstance(ThemeConfig::class);
        
        // 获取主题配置
        $themeMode = $themeConfig->getThemeModel();
        $themeColor = $themeConfig->getThemeConfig('theme-color') ?? '#667eea';
        $layouts = $themeConfig->getLayouts();
        
        // 传递到模板
        $this->assign('themeMode', $themeMode);
        $this->assign('themeColor', $themeColor);
        $this->assign('layouts', $layouts);
        $this->assign('themeConfig', $themeConfig);
    }
    
    /**
     * 综合测试首页 - 测试用例导航
     */
    #[Acl('Weline_DataTable::test_comprehensive_index', '测试首页', 'mdi mdi-home', 'DataTable综合测试首页')]
    public function index()
    {
        $testCases = [
            [
                'name' => '基本表格',
                'description' => '测试基本的表格显示功能，包括数据展示、表头、分页等',
                'url' => $this->_url->getUrl('datatable/test/comprehensive/basic'),
                'icon' => 'table',
                'category' => '基础功能'
            ],
            [
                'name' => '表单功能',
                'description' => '测试表单的增删改查功能，包括字段验证、数据提交等',
                'url' => $this->_url->getUrl('datatable/test/comprehensive/form'),
                'icon' => 'form',
                'category' => '基础功能'
            ],
            [
                'name' => '字段类型',
                'description' => '测试各种字段类型的显示和编辑，包括文本、数字、日期、选择等',
                'url' => $this->_url->getUrl('datatable/test/comprehensive/fieldTypes'),
                'icon' => 'field',
                'category' => '高级功能'
            ],
            [
                'name' => '多模型查询',
                'description' => '测试多表JOIN查询功能，包括LEFT JOIN、INNER JOIN等',
                'url' => $this->_url->getUrl('datatable/test/comprehensive/multiModel'),
                'icon' => 'join',
                'category' => '高级功能'
            ],
            [
                'name' => '自动生成',
                'description' => '测试自动生成表头和过滤器功能',
                'url' => $this->_url->getUrl('datatable/test/comprehensive/autoGeneration'),
                'icon' => 'auto',
                'category' => '高级功能'
            ],
            [
                'name' => '属性继承',
                'description' => '测试子标签从父标签继承属性的机制',
                'url' => $this->_url->getUrl('datatable/test/comprehensive/inheritance'),
                'icon' => 'inherit',
                'category' => '高级功能'
            ],
            [
                'name' => '过滤搜索',
                'description' => '测试表格的过滤和搜索功能',
                'url' => $this->_url->getUrl('datatable/test/comprehensive/filter'),
                'icon' => 'search',
                'category' => '基础功能'
            ],
            [
                'name' => '排序分页',
                'description' => '测试表格的排序和分页功能',
                'url' => $this->_url->getUrl('datatable/test/comprehensive/sorting'),
                'icon' => 'sort',
                'category' => '基础功能'
            ],
            [
                'name' => 'CRUD操作',
                'description' => '测试完整的增删改查操作流程',
                'url' => $this->_url->getUrl('datatable/test/comprehensive/crud'),
                'icon' => 'crud',
                'category' => '完整功能'
            ]
        ];
        
        $this->assign('testCases', $testCases);
        $this->assign('models', $this->getTestModels());
        
        return $this->fetch('Weline_DataTable::templates/Test/Comprehensive/index');
    }
    
    /**
     * 测试基本表格功能
     */
    #[Acl('Weline_DataTable::test_comprehensive_basic', '基本表格测试', 'mdi mdi-table', '测试基本表格功能')]
    public function basic()
    {
        $this->assign('model', 'Weline\\DataTable\\Model\\TestUser');
        $this->assign('title', '基本表格功能测试');
        $this->assign('description', '测试DataTable的基本表格显示功能，包括数据展示、表头、分页等');
        
        return $this->fetch('Weline_DataTable::templates/Test/Comprehensive/basic');

    }
    
    /**
     * 测试表单功能
     */
    #[Acl('Weline_DataTable::test_comprehensive_form', '表单功能测试', 'mdi mdi-form-select', '测试表单功能')]
    public function form()
    {
        $this->assign('model', 'Weline\\DataTable\\Model\\TestUser');
        $this->assign('title', '表单功能测试');
        $this->assign('description', '测试DataTable的表单功能，包括字段验证、数据提交等');
        
        return $this->fetch('Weline_DataTable::templates/Test/Comprehensive/form');

    }
    
    /**
     * 测试字段类型
     */
    #[Acl('Weline_DataTable::test_comprehensive_field_types', '字段类型测试', 'mdi mdi-form-textbox', '测试各种字段类型')]
    public function fieldTypes()
    {
        $this->assign('model', 'Weline\\DataTable\\Model\\TestUser');
        $this->assign('title', '字段类型测试');
        $this->assign('description', '测试DataTable支持的各种字段类型');
        
        return $this->fetch('Weline_DataTable::templates/Test/Comprehensive/fieldTypes');

    }
    
    /**
     * 测试多模型功能
     */
    #[Acl('Weline_DataTable::test_comprehensive_multi_model', '多模型查询测试', 'mdi mdi-table-multiple', '测试多表JOIN查询功能')]
    public function multiModel()
    {
        $this->assign('title', '多模型查询测试');
        $this->assign('description', '测试DataTable的多表JOIN查询功能');
        
        return $this->fetch('Weline_DataTable::templates/Test/Comprehensive/multiModel');

    }
    
    /**
     * 测试自动生成功能
     */
    #[Acl('Weline_DataTable::test_comprehensive_auto_generation', '自动生成测试', 'mdi mdi-auto-fix', '测试自动生成表头和过滤器功能')]
    public function autoGeneration()
    {
        $this->assign('model', 'Weline\\DataTable\\Model\\TestUser');
        $this->assign('title', '自动生成功能测试');
        $this->assign('description', '测试DataTable的自动生成表头和过滤器功能');
        
        return $this->fetch('Weline_DataTable::templates/Test/Comprehensive/autoGeneration');

    }
    
    /**
     * 测试属性继承功能
     */
    #[Acl('Weline_DataTable::test_comprehensive_inheritance', '属性继承测试', 'mdi mdi-inheritance', '测试属性继承机制')]
    public function inheritance()
    {
        $this->assign('model', 'Weline\\DataTable\\Model\\TestUser');
        $this->assign('title', '属性继承功能测试');
        $this->assign('description', '测试DataTable的子标签从父标签继承属性的机制');
        
        return $this->fetch('Weline_DataTable::templates/Test/Comprehensive/inheritance');

    }
    
    /**
     * 测试过滤和搜索功能
     */
    #[Acl('Weline_DataTable::test_comprehensive_filter', '过滤搜索测试', 'mdi mdi-filter', '测试过滤和搜索功能')]
    public function filter()
    {
        $this->assign('model', 'Weline\\DataTable\\Model\\TestUser');
        $this->assign('title', '过滤和搜索功能测试');
        $this->assign('description', '测试DataTable的过滤和搜索功能');
        
        return $this->fetch('Weline_DataTable::templates/Test/Comprehensive/filter');

    }
    
    /**
     * 测试排序和分页功能
     */
    #[Acl('Weline_DataTable::test_comprehensive_sorting', '排序分页测试', 'mdi mdi-sort', '测试排序和分页功能')]
    public function sorting()
    {
        $this->assign('model', 'Weline\\DataTable\\Model\\TestUser');
        $this->assign('title', '排序和分页功能测试');
        $this->assign('description', '测试DataTable的排序和分页功能');
        
        return $this->fetch('Weline_DataTable::templates/Test/Comprehensive/sorting');

    }
    
    /**
     * 测试CRUD操作
     */
    #[Acl('Weline_DataTable::test_comprehensive_crud', 'CRUD操作测试', 'mdi mdi-database-edit', '测试完整的增删改查操作')]
    public function crud()
    {
        $this->assign('model', 'Weline\\DataTable\\Model\\TestUser');
        $this->assign('title', 'CRUD操作测试');
        $this->assign('description', '测试完整的增删改查操作流程');
        
        return $this->fetch('Weline_DataTable::templates/Test/Comprehensive/crud');

    }
    
    /**
     * 验证标签功能API
     */
    #[Acl('Weline_DataTable::test_comprehensive_verify_tags', '验证标签功能', 'mdi mdi-check-circle', '验证标签功能API')]
    public function verifyTags()
    {
        try {
            $results = [
                'tag_registration' => $this->verifyTagRegistration(),
                'table_functionality' => $this->verifyTableFunctionality(),
                'field_validation' => $this->verifyFieldValidation(),
                'context_management' => $this->verifyContextManagement(),
                'attribute_inheritance' => $this->verifyAttributeInheritance(),
                'auto_generation' => $this->verifyAutoGeneration()
            ];
            
            return $this->jsonResponse('标签功能验证结果', $results);
            
        } catch (\Exception $e) {
            return $this->errorResponse('验证失败：' . $e->getMessage());
        }
    }
    
    /**
     * 获取测试模型列表
     */
    private function getTestModels(): array
    {
        return [
            'Weline\\DataTable\\Model\\TestUser' => '测试用户模型',
            'Weline\\DataTable\\Model\\TestProduct' => '测试产品模型',
            'Weline\\DataTable\\Model\\TestOrder' => '测试订单模型',
            'Weline\\DataTable\\Model\\TestUserProfile' => '测试用户资料模型',
            'Weline\\DataTable\\Model\\TestUserAddress' => '测试用户地址模型'
        ];
    }
    
    /**
     * 验证标签注册
     */
    private function verifyTagRegistration()
    {
        $tags = [
            'd-table' => Table::class,
            't-header' => TableHeader::class,
            't-filter' => TableFilter::class,
            'field' => Field::class,
            'd-form' => Form::class
        ];
        
        $results = [];
        
        foreach ($tags as $tagName => $className) {
            if (!class_exists($className)) {
                $results[$tagName] = ['status' => 'error', 'message' => "类 {$className} 不存在"];
                continue;
            }
            
            $requiredMethods = ['name', 'tag', 'attr', 'callback'];
            $missingMethods = [];
            
            foreach ($requiredMethods as $method) {
                if (!method_exists($className, $method)) {
                    $missingMethods[] = $method;
                }
            }
            
            if (!empty($missingMethods)) {
                $results[$tagName] = ['status' => 'error', 'message' => "缺少方法: " . implode(', ', $missingMethods)];
            } else {
                $tagName_check = $className::name();
                $tag_check = $className::tag();
                $attr_check = $className::attr();
                
                if ($tagName_check === $tagName && $tag_check === true && is_array($attr_check)) {
                    $results[$tagName] = ['status' => 'success', 'message' => '标签注册正常'];
                } else {
                    $results[$tagName] = ['status' => 'error', 'message' => '标签配置异常'];
                }
            }
        }
        
        return $results;
    }
    
    /**
     * 验证表格功能
     */
    private function verifyTableFunctionality()
    {
        $results = [];
        
        try {
            $callback = Table::callback();
            
            // 测试必需参数验证
            try {
                $callback('d-table', [], ['', '', ''], []);
                $results['required_params'] = ['status' => 'error', 'message' => '缺少必需参数时应该抛出异常'];
            } catch (\Exception $e) {
                $results['required_params'] = ['status' => 'success', 'message' => '正确验证必需参数'];
            }
            
            // 测试基本功能
            $attributes = [
                'model' => 'Weline\\DataTable\\Model\\TestUser',
                'scope' => 'test-scope'
            ];
            
            $result = $callback('d-table', [], ['', '', ''], $attributes);
            if (is_string($result) && !empty($result)) {
                $results['basic_functionality'] = ['status' => 'success', 'message' => '基本功能正常'];
            } else {
                $results['basic_functionality'] = ['status' => 'error', 'message' => '基本功能异常'];
            }
            
            // 测试多模型配置
            $attributes['model'] = 'Weline\\DataTable\\Model\\TestUser as u, Weline\\DataTable\\Model\\TestOrder as o';
            $attributes['join'] = 'left u.id = o.user_id';
            
            $result = $callback('d-table', [], ['', '', ''], $attributes);
            if (is_string($result) && strpos($result, 'modelConfig') !== false) {
                $results['multi_model'] = ['status' => 'success', 'message' => '多模型配置正常'];
            } else {
                $results['multi_model'] = ['status' => 'error', 'message' => '多模型配置异常'];
            }
            
        } catch (\Exception $e) {
            $results['exception'] = ['status' => 'error', 'message' => $e->getMessage()];
        }
        
        return $results;
    }
    
    /**
     * 验证字段验证
     */
    private function verifyFieldValidation()
    {
        $results = [];
        
        try {
            $callback = Field::callback();
            
            // 设置上下文
            TableContext::pushChildTag('t-filter', 'test-scope', [
                'type' => 't-filter',
                'scope' => 'test-scope'
            ]);
            
            // 测试缺少belong属性
            try {
                $callback('field', [], ['', '', ''], ['name' => 'test_field']);
                $results['belong_validation'] = ['status' => 'error', 'message' => '缺少belong属性时应该抛出异常'];
            } catch (\Exception $e) {
                $results['belong_validation'] = ['status' => 'success', 'message' => '正确验证belong属性'];
            }
            
            // 测试有效字段
            $attributes = [
                'belong' => 't-filter',
                'name' => 'test_field',
                'type' => 'text'
            ];
            
            $result = $callback('field', [], ['', '', ''], $attributes);
            if (is_string($result) && !empty($result)) {
                $results['valid_field'] = ['status' => 'success', 'message' => '基本字段验证正常'];
            } else {
                $results['valid_field'] = ['status' => 'error', 'message' => '基本字段验证异常'];
            }
            
        } catch (\Exception $e) {
            $results['exception'] = ['status' => 'error', 'message' => $e->getMessage()];
        } finally {
            TableContext::clearAll();
        }
        
        return $results;
    }
    
    /**
     * 验证上下文管理
     */
    private function verifyContextManagement()
    {
        $results = [];
        
        try {
            // 清理环境
            TableContext::clearAll();
            
            // 测试设置上下文
            $testContext = [
                'model' => 'Weline\\DataTable\\Model\\TestUser',
                'scope' => 'test-scope',
                'sortable' => true
            ];
            
            TableContext::setTableContext('test-scope', $testContext);
            $results['set_context'] = ['status' => 'success', 'message' => '设置上下文正常'];
            
            // 测试获取上下文
            $retrievedContext = TableContext::getTableContext('test-scope');
            if ($retrievedContext && $retrievedContext['model'] === 'Weline\\DataTable\\Model\\TestUser') {
                $results['get_context'] = ['status' => 'success', 'message' => '获取上下文正常'];
            } else {
                $results['get_context'] = ['status' => 'error', 'message' => '获取上下文异常'];
            }
            
            // 测试推入和弹出标签
            TableContext::pushChildTag('t-header', 'test-header-scope', ['type' => 't-header']);
            $results['push_tag'] = ['status' => 'success', 'message' => '推入子标签正常'];
            
            TableContext::popTag();
            $results['pop_tag'] = ['status' => 'success', 'message' => '弹出子标签正常'];
            
        } catch (\Exception $e) {
            $results['exception'] = ['status' => 'error', 'message' => $e->getMessage()];
        } finally {
            TableContext::clearAll();
        }
        
        return $results;
    }
    
    /**
     * 验证属性继承
     */
    private function verifyAttributeInheritance()
    {
        $results = [];
        
        try {
            // 清理之前的上下文
            TableContext::clearAll();
            
            // 设置父表格上下文
            $parentContext = [
                'type' => 'd-table',
                'scope' => 'parent-scope',
                'model' => 'Weline\\DataTable\\Model\\TestUser',
                'sortable' => true,
                'searchable' => true
            ];
            
            TableContext::pushChildTag('d-table', 'parent-scope', $parentContext);
            
            // 测试属性继承
            $childAttributes = ['scope' => 'child-scope'];
            $inheritedAttributes = TableContext::inheritTableAttributes(
                $childAttributes, 
                'child-scope-header', 
                ['model', 'sortable', 'searchable']
            );
            
            if (isset($inheritedAttributes['model']) && $inheritedAttributes['model'] === 'Weline\\DataTable\\Model\\TestUser') {
                $results['model_inheritance'] = ['status' => 'success', 'message' => 'model继承正常'];
            } else {
                $results['model_inheritance'] = ['status' => 'error', 'message' => 'model继承异常'];
            }
            
            if (isset($inheritedAttributes['sortable']) && $inheritedAttributes['sortable'] === true) {
                $results['sortable_inheritance'] = ['status' => 'success', 'message' => 'sortable继承正常'];
            } else {
                $results['sortable_inheritance'] = ['status' => 'error', 'message' => 'sortable继承异常'];
            }
            
            // 测试scope自动生成
            if (isset($inheritedAttributes['scope']) && strpos($inheritedAttributes['scope'], 'child-scope-header') !== false) {
                $results['scope_generation'] = ['status' => 'success', 'message' => 'scope自动生成正常'];
            } else {
                $results['scope_generation'] = ['status' => 'error', 'message' => 'scope自动生成异常'];
            }
            
        } catch (\Exception $e) {
            $results['exception'] = ['status' => 'error', 'message' => $e->getMessage()];
        } finally {
            TableContext::clearAll();
        }
        
        return $results;
    }
    
    /**
     * 验证自动生成功能
     */
    private function verifyAutoGeneration()
    {
        $results = [];
        
        try {
            $callback = Table::callback();
            
            // 测试空内容自动生成
            $attributes = [
                'model' => 'Weline\\DataTable\\Model\\TestUser',
                'scope' => 'test-auto'
            ];
            
            $result = $callback('d-table', [], ['', '', ''], $attributes);
            
            if (is_string($result)) {
                // 检查是否包含自动生成的标签
                if (strpos($result, 't-filter') !== false && strpos($result, 't-header') !== false) {
                    $results['tag_generation'] = ['status' => 'success', 'message' => '表头和过滤器自动生成正常'];
                } else {
                    $results['tag_generation'] = ['status' => 'error', 'message' => '缺少自动生成的标签'];
                }
                
                // 检查是否包含JavaScript初始化
                if (strpos($result, 'DataTableManager') !== false) {
                    $results['js_initialization'] = ['status' => 'success', 'message' => 'JavaScript初始化正常'];
                } else {
                    $results['js_initialization'] = ['status' => 'error', 'message' => '缺少JavaScript初始化'];
                }
            } else {
                $results['result_type'] = ['status' => 'error', 'message' => '生成结果异常'];
            }
            
        } catch (\Exception $e) {
            $results['exception'] = ['status' => 'error', 'message' => $e->getMessage()];
        }
        
        return $results;
    }
    
    /**
     * JSON响应
     */
    private function jsonResponse($message, $data = [])
    {
        return json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ]);
    }
    
    /**
     * 错误响应
     */
    private function errorResponse($message)
    {
        return json_encode([
            'success' => false,
            'message' => $message,
            'timestamp' => time()
        ]);
    }
}

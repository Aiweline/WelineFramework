<?php

namespace Weline\DataTable\Controller\Backend\Test;

use Weline\Framework\App\Controller\BackendController;
use Weline\DataTable\Model\TestUser;
use Weline\DataTable\Model\TestProduct;
use Weline\DataTable\Model\TestOrder;
use Weline\Framework\Manager\ObjectManager;
use Weline\Taglib\Model\Taglib;

class Verification extends BackendController
{
    /**
     * 验证DataTable功能
     */
    public function verify()
    {
        $results = [];
        
        // 1. 验证模块加载
        $results['module_loaded'] = $this->verifyModuleLoaded();
        
        // 2. 验证标签库注册
        $results['taglib_registered'] = $this->verifyTaglibRegistered();
        
        // 3. 验证模型创建
        $results['models_created'] = $this->verifyModelsCreated();
        
        // 4. 验证API接口
        $results['api_working'] = $this->verifyApiWorking();
        
        // 5. 验证路由配置
        $results['routes_configured'] = $this->verifyRoutesConfigured();
        
        // 6. 验证缓存系统
        $results['cache_working'] = $this->verifyCacheWorking();
        
        // 7. 验证测试数据
        $results['test_data'] = $this->verifyTestData();
        
        // 8. 验证表格功能
        $results['table_functionality'] = $this->verifyTableFunctionality();
        
        return $this->success('DataTable功能验证完成', $results);
    }

    /**
     * 验证模块是否已加载
     */
    private function verifyModuleLoaded(): array
    {
        try {
            $moduleManager = ObjectManager::getInstance(\Weline\Framework\Module\Model\Module::class);
            $module = $moduleManager->where('name', 'Weline_DataTable')->find()->fetch();
            
            if ($module->getId()) {
                return [
                    'status' => 'success',
                    'message' => 'DataTable模块已加载',
                    'version' => $module->getData('version'),
                    'status_text' => $module->getData('status') ? '启用' : '禁用'
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => 'DataTable模块未找到'
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => '验证模块加载失败: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 验证标签库是否已注册
     */
    private function verifyTaglibRegistered(): array
    {
        try {
            $taglibModel = ObjectManager::getInstance(Taglib::class);
            $taglibs = $taglibModel->where('name', 'LIKE', '%DataTable%')->select()->fetchArray();
            
            $expectedTaglibs = ['d-table', 'field', 'd-form', 't-header', 't-filter', 't-body', 't-footer'];
            $foundTaglibs = array_column($taglibs, 'name');
            
            $missingTaglibs = array_diff($expectedTaglibs, $foundTaglibs);
            
            if (empty($missingTaglibs)) {
                return [
                    'status' => 'success',
                    'message' => '所有DataTable标签库已注册',
                    'found_taglibs' => $foundTaglibs
                ];
            } else {
                return [
                    'status' => 'warning',
                    'message' => '部分DataTable标签库未注册',
                    'found_taglibs' => $foundTaglibs,
                    'missing_taglibs' => $missingTaglibs
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => '验证标签库注册失败: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 验证模型是否已创建
     */
    private function verifyModelsCreated(): array
    {
        $results = [];
        $models = [
            'TestUser' => TestUser::class,
            'TestProduct' => TestProduct::class,
            'TestOrder' => TestOrder::class
        ];
        
        foreach ($models as $name => $class) {
            try {
                $model = ObjectManager::getInstance($class);
                $table = $model->getTable();
                
                // 检查表是否存在
                $tableExists = $model->query("SHOW TABLES LIKE '{$table}'")->fetchArray();
                
                if ($tableExists) {
                    $results[$name] = [
                        'status' => 'success',
                        'message' => "模型 {$name} 已创建",
                        'table' => $table,
                        'class' => $class
                    ];
                } else {
                    $results[$name] = [
                        'status' => 'error',
                        'message' => "模型 {$name} 表不存在",
                        'table' => $table
                    ];
                }
            } catch (\Exception $e) {
                $results[$name] = [
                    'status' => 'error',
                    'message' => "验证模型 {$name} 失败: " . $e->getMessage()
                ];
            }
        }
        
        return $results;
    }

    /**
     * 验证API接口是否工作
     */
    private function verifyApiWorking(): array
    {
        try {
            // 检查API控制器是否存在
            $apiController = 'Weline\DataTable\Api\Rest\V1\DataTable';
            if (class_exists($apiController)) {
                return [
                    'status' => 'success',
                    'message' => 'DataTable API控制器已加载',
                    'controller' => $apiController
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => 'DataTable API控制器未找到'
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => '验证API接口失败: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 验证路由配置
     */
    private function verifyRoutesConfigured(): array
    {
        try {
            $routesFile = BP . 'app/code/Weline/DataTable/etc/routes.xml';
            if (file_exists($routesFile)) {
                $content = file_get_contents($routesFile);
                if (strpos($content, 'datatable') !== false) {
                    return [
                        'status' => 'success',
                        'message' => 'DataTable路由配置正确',
                        'file' => $routesFile
                    ];
                } else {
                    return [
                        'status' => 'warning',
                        'message' => 'DataTable路由配置可能有问题',
                        'file' => $routesFile
                    ];
                }
            } else {
                return [
                    'status' => 'error',
                    'message' => 'DataTable路由配置文件不存在'
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => '验证路由配置失败: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 验证缓存系统
     */
    private function verifyCacheWorking(): array
    {
        try {
            // 检查缓存目录是否存在
            $cacheDir = BP . 'var/cache';
            if (is_dir($cacheDir) && is_writable($cacheDir)) {
                return [
                    'status' => 'success',
                    'message' => '缓存系统工作正常',
                    'cache_dir' => $cacheDir,
                    'writable' => true
                ];
            } else {
                return [
                    'status' => 'warning',
                    'message' => '缓存目录可能有问题',
                    'cache_dir' => $cacheDir,
                    'writable' => is_writable($cacheDir)
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => '验证缓存系统失败: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 验证测试数据
     */
    private function verifyTestData(): array
    {
        $results = [];
        $models = [
            'TestUser' => TestUser::class,
            'TestProduct' => TestProduct::class,
            'TestOrder' => TestOrder::class
        ];
        
        foreach ($models as $name => $class) {
            try {
                $model = ObjectManager::getInstance($class);
                $count = $model->count();
                
                $results[$name] = [
                    'status' => 'success',
                    'message' => "模型 {$name} 有 {$count} 条记录",
                    'count' => $count
                ];
            } catch (\Exception $e) {
                $results[$name] = [
                    'status' => 'error',
                    'message' => "验证测试数据失败: " . $e->getMessage()
                ];
            }
        }
        
        return $results;
    }

    /**
     * 验证表格功能
     */
    private function verifyTableFunctionality(): array
    {
        $results = [];
        
        // 检查Table标签类
        try {
            $tableClass = 'Weline\DataTable\Taglib\Table';
            if (class_exists($tableClass)) {
                $results['table_tag'] = [
                    'status' => 'success',
                    'message' => 'Table标签类已加载',
                    'class' => $tableClass
                ];
            } else {
                $results['table_tag'] = [
                    'status' => 'error',
                    'message' => 'Table标签类未找到'
                ];
            }
        } catch (\Exception $e) {
            $results['table_tag'] = [
                'status' => 'error',
                'message' => '验证Table标签失败: ' . $e->getMessage()
            ];
        }
        
        // 检查Field标签类
        try {
            $fieldClass = 'Weline\DataTable\Taglib\Field';
            if (class_exists($fieldClass)) {
                $results['field_tag'] = [
                    'status' => 'success',
                    'message' => 'Field标签类已加载',
                    'class' => $fieldClass
                ];
            } else {
                $results['field_tag'] = [
                    'status' => 'error',
                    'message' => 'Field标签类未找到'
                ];
            }
        } catch (\Exception $e) {
            $results['field_tag'] = [
                'status' => 'error',
                'message' => '验证Field标签失败: ' . $e->getMessage()
            ];
        }
        
        // 检查Form标签类
        try {
            $formClass = 'Weline\DataTable\Taglib\Form';
            if (class_exists($formClass)) {
                $results['form_tag'] = [
                    'status' => 'success',
                    'message' => 'Form标签类已加载',
                    'class' => $formClass
                ];
            } else {
                $results['form_tag'] = [
                    'status' => 'error',
                    'message' => 'Form标签类未找到'
                ];
            }
        } catch (\Exception $e) {
            $results['form_tag'] = [
                'status' => 'error',
                'message' => '验证Form标签失败: ' . $e->getMessage()
            ];
        }
        
        // 检查TableContext助手类
        try {
            $contextClass = 'Weline\DataTable\Helper\TableContext';
            if (class_exists($contextClass)) {
                $results['table_context'] = [
                    'status' => 'success',
                    'message' => 'TableContext助手类已加载',
                    'class' => $contextClass
                ];
            } else {
                $results['table_context'] = [
                    'status' => 'error',
                    'message' => 'TableContext助手类未找到'
                ];
            }
        } catch (\Exception $e) {
            $results['table_context'] = [
                'status' => 'error',
                'message' => '验证TableContext失败: ' . $e->getMessage()
            ];
        }
        
        return $results;
    }

    /**
     * 生成验证报告
     */
    public function report()
    {
        $verificationResults = $this->verify();
        $data = $verificationResults['data'];
        
        $this->assign('title', 'DataTable 功能验证报告');
        $this->assign('results', $data);
        
        return $this->fetch('Weline_DataTable::test/verification-report.phtml');
    }
} 
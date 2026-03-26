<?php

declare(strict_types=1);

namespace WeShop\Filters\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

/**
 * 筛选URL服务
 * 
 * 处理筛选相关的URL参数
 */
class FilterUrlService
{
    /**
     * @var Request
     */
    private Request $request;
    
    /**
     * @var array 保留的URL参数（不属于筛选）
     */
    private array $reservedParams = ['page', 'limit', 'sort', 'order', 'q', 'id', 'handle'];
    
    public function __construct(Request $request)
    {
        $this->request = $request;
    }
    
    /**
     * 从URL获取筛选参数
     * 
     * @return array
     */
    public function getFilterParams(): array
    {
        $params = $this->request->getQuery();
        $filterParams = [];
        
        foreach ($params as $key => $value) {
            if (in_array($key, $this->reservedParams, true)) {
                continue;
            }
            
            // 解析逗号分隔的多值
            if (is_string($value) && strpos($value, ',') !== false) {
                $filterParams[$key] = explode(',', $value);
            } else {
                $filterParams[$key] = $value;
            }
        }
        
        return $filterParams;
    }
    
    /**
     * 构建筛选URL
     * 
     * @param array $filterParams 筛选参数
     * @param array $additionalParams 额外参数
     * @return string
     */
    public function buildFilterUrl(array $filterParams, array $additionalParams = []): string
    {
        $baseUrl = $this->getBaseUrl();
        
        // 合并保留的参数
        $params = $this->getReservedParamValues();
        
        // 添加筛选参数
        foreach ($filterParams as $key => $values) {
            if (is_array($values)) {
                $params[$key] = implode(',', $values);
            } else {
                $params[$key] = $values;
            }
        }
        
        // 添加额外参数
        $params = array_merge($params, $additionalParams);
        
        // 移除空值
        $params = array_filter($params, function ($value) {
            return $value !== '' && $value !== null;
        });
        
        if (empty($params)) {
            return $baseUrl;
        }
        
        return $baseUrl . '?' . http_build_query($params);
    }
    
    /**
     * 获取添加筛选的URL
     * 
     * @param string $filterCode 筛选代码
     * @param string $value 筛选值
     * @return string
     */
    public function getAddFilterUrl(string $filterCode, string $value): string
    {
        $currentParams = $this->getFilterParams();
        
        // 如果已存在，添加到数组
        if (isset($currentParams[$filterCode])) {
            $existingValues = is_array($currentParams[$filterCode]) 
                ? $currentParams[$filterCode] 
                : [$currentParams[$filterCode]];
            
            if (!in_array($value, $existingValues, true)) {
                $existingValues[] = $value;
            }
            $currentParams[$filterCode] = $existingValues;
        } else {
            $currentParams[$filterCode] = $value;
        }
        
        // 重置页码
        return $this->buildFilterUrl($currentParams, ['page' => null]);
    }
    
    /**
     * 获取移除筛选的URL
     * 
     * @param string $filterCode 筛选代码
     * @param string|null $value 筛选值（为null时移除整个筛选）
     * @return string
     */
    public function getRemoveFilterUrl(string $filterCode, ?string $value = null): string
    {
        $currentParams = $this->getFilterParams();
        
        if (!isset($currentParams[$filterCode])) {
            return $this->buildFilterUrl($currentParams);
        }
        
        if ($value === null) {
            // 移除整个筛选
            unset($currentParams[$filterCode]);
        } else {
            // 移除特定值
            $existingValues = is_array($currentParams[$filterCode]) 
                ? $currentParams[$filterCode] 
                : [$currentParams[$filterCode]];
            
            $existingValues = array_filter($existingValues, function ($v) use ($value) {
                return $v !== $value;
            });
            
            if (empty($existingValues)) {
                unset($currentParams[$filterCode]);
            } else {
                $currentParams[$filterCode] = array_values($existingValues);
            }
        }
        
        // 重置页码
        return $this->buildFilterUrl($currentParams, ['page' => null]);
    }
    
    /**
     * 获取清除所有筛选的URL
     * 
     * @param int|null $categoryId
     * @return string
     */
    public function getClearAllUrl(?int $categoryId = null): string
    {
        return $this->buildFilterUrl([], ['page' => null]);
    }
    
    /**
     * 获取切换筛选值的URL（已选则移除，未选则添加）
     * 
     * @param string $filterCode
     * @param string $value
     * @return string
     */
    public function getToggleFilterUrl(string $filterCode, string $value): string
    {
        $currentParams = $this->getFilterParams();
        
        if (isset($currentParams[$filterCode])) {
            $existingValues = is_array($currentParams[$filterCode]) 
                ? $currentParams[$filterCode] 
                : [$currentParams[$filterCode]];
            
            if (in_array($value, $existingValues, true)) {
                return $this->getRemoveFilterUrl($filterCode, $value);
            }
        }
        
        return $this->getAddFilterUrl($filterCode, $value);
    }
    
    /**
     * 检查筛选值是否已选中
     * 
     * @param string $filterCode
     * @param string $value
     * @return bool
     */
    public function isFilterValueSelected(string $filterCode, string $value): bool
    {
        $currentParams = $this->getFilterParams();
        
        if (!isset($currentParams[$filterCode])) {
            return false;
        }
        
        $existingValues = is_array($currentParams[$filterCode]) 
            ? $currentParams[$filterCode] 
            : [$currentParams[$filterCode]];
        
        return in_array($value, $existingValues, true);
    }
    
    /**
     * 获取基础URL（不含查询参数）
     * 
     * @return string
     */
    private function getBaseUrl(): string
    {
        $uri = $this->request->getUri();
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            return substr($uri, 0, $pos);
        }
        return $uri;
    }
    
    /**
     * 获取保留参数的当前值
     * 
     * @return array
     */
    private function getReservedParamValues(): array
    {
        $params = [];
        foreach ($this->reservedParams as $param) {
            $value = $this->request->getQuery($param);
            if ($value !== null && $value !== '') {
                $params[$param] = $value;
            }
        }
        return $params;
    }
    
    /**
     * 添加保留参数
     * 
     * @param string $param
     * @return self
     */
    public function addReservedParam(string $param): self
    {
        if (!in_array($param, $this->reservedParams, true)) {
            $this->reservedParams[] = $param;
        }
        return $this;
    }
}

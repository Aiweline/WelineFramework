/**
 * WeShop Product - 价格计算 WASM 模块头文件
 * 
 * @author Aiweline
 * @version 1.0.0
 */

#ifndef PRICE_CALCULATOR_H
#define PRICE_CALCULATOR_H

#ifdef __cplusplus
extern "C" {
#endif

/**
 * 价格组成部分结构
 */
typedef struct {
    double base;        // 基础价格
    double shipping;   // 配送费
    double tax;        // 税费
    double discount;   // 折扣金额
    double other;      // 其他费用
} PriceComponents;

/**
 * 计算总价
 */
double calculate_total_price(PriceComponents* components);

/**
 * 应用价格调整
 */
void apply_price_adjustment(PriceComponents* components, int type, double amount);

/**
 * 计算基础价格
 */
double calculate_base_price(double unitPrice, int quantity);

/**
 * 格式化价格
 */
double format_price(double price);

/**
 * 验证价格数据
 */
int validate_price_components(PriceComponents* components);

#ifdef __cplusplus
}
#endif

#endif // PRICE_CALCULATOR_H

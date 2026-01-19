/**
 * WeShop Product - 价格计算 WASM 模块
 * 
 * 提供高性能的价格计算功能
 * 
 * @author Aiweline
 * @version 1.0.0
 */

#include <stdint.h>
#include <math.h>

#ifdef __cplusplus
extern "C" {
#endif

/**
 * 价格组成部分结构
 */
typedef struct {
    double base;        // 基础价格
    double shipping;    // 配送费
    double tax;         // 税费
    double discount;    // 折扣金额
    double other;       // 其他费用
} PriceComponents;

/**
 * 计算总价
 * 
 * @param components 价格组成部分
 * @return 总价
 */
double calculate_total_price(PriceComponents* components) {
    double total = components->base 
                  + components->shipping 
                  + components->tax 
                  + components->other 
                  - components->discount;
    
    // 确保总价不为负数
    return total > 0.0 ? total : 0.0;
}

/**
 * 应用价格调整
 * 
 * @param components 价格组成部分
 * @param type 调整类型 (0=shipping, 1=tax, 2=discount, 3=other)
 * @param amount 调整金额（正数表示增加，负数表示减少）
 */
void apply_price_adjustment(PriceComponents* components, int type, double amount) {
    switch (type) {
        case 0: // shipping
            components->shipping += amount;
            break;
        case 1: // tax
            components->tax += amount;
            break;
        case 2: // discount
            components->discount += amount;
            break;
        case 3: // other
            components->other += amount;
            break;
        default:
            components->other += amount;
            break;
    }
    
    // 确保各部分不为负数（折扣除外，因为折扣本身就是负数）
    if (components->shipping < 0.0) components->shipping = 0.0;
    if (components->tax < 0.0) components->tax = 0.0;
    if (components->other < 0.0) components->other = 0.0;
}

/**
 * 计算基础价格（数量 × 单价）
 * 
 * @param unitPrice 单价
 * @param quantity 数量
 * @return 基础价格
 */
double calculate_base_price(double unitPrice, int quantity) {
    return unitPrice * (double)quantity;
}

/**
 * 格式化价格（保留两位小数）
 * 
 * @param price 价格
 * @return 格式化后的价格
 */
double format_price(double price) {
    return round(price * 100.0) / 100.0;
}

/**
 * 验证价格数据
 * 
 * @param components 价格组成部分
 * @return 1=有效, 0=无效
 */
int validate_price_components(PriceComponents* components) {
    if (components->base < 0.0) return 0;
    if (components->shipping < 0.0) return 0;
    if (components->tax < 0.0) return 0;
    if (components->other < 0.0) return 0;
    return 1;
}

#ifdef __cplusplus
}
#endif

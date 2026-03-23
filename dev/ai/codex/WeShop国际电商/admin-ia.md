# Admin IA

计划映射：

- `shop_group`: Store, Product, Catalog, Filters, Search, Inventory, Price, Cms
- `order_group`: Cart, Checkout, Order, Invoice, RMA
- `customer_group`: Customer, Address, Membership, Subscription, B2B, Notification, Wishlist, RecentlyViewed
- `marketing_group`: Promotion, GiftCard, Affiliate, Social, Analytics
- `shipping_group`: Shipping, Logistics, Compliance
- `payment_group`: Payment, Tax
- `data_tools_group`: ImportExport, Report

Execution note:

- `Payment` backend IA should own payment method registry/config, provider status, callback audit, and future gateway credentials under `payment_group`.

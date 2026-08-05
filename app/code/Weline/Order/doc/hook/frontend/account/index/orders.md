Use `Weline_Order::frontend::account::index::orders` to inject storefront order UI
into the official `Weline_Customer` account index host. It must stay under
`account.sidebar` / `account.sidebar.content`; do not create a standalone account page.

The default Order implementation groups rows by `CheckoutGroup`. It expands child
Orders when refund, invoice, fulfillment, or Order statuses diverge, and exposes
customer-safe labels from current `RefundCase`, `OrderInvoice`, and
`FulfillmentAction` facts. Storefront overrides may change presentation, but must
preserve the same official account host, accessibility labels, and current-source
semantics.

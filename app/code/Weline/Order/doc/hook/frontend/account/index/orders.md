Use `Weline_Order::frontend::account::index::orders` to inject storefront order UI
into the official `Weline_Customer` account index host. It must stay under
`account.sidebar` / `account.sidebar.content`; do not create a standalone account page.

The default Order implementation groups rows by `CheckoutGroup`. It expands child
Orders when refund, invoice, fulfillment, or Order statuses diverge, and exposes
customer-safe labels from current `RefundCase`, `OrderInvoice`, and
`FulfillmentAction` facts. Storefront overrides may change presentation, but must
preserve the same official account host, accessibility labels, and current-source
semantics.

Under WLS, the Hook must keep all render state request-scoped. Do not use `$GLOBALS`,
static flags, or another process-global marker to suppress duplicate rendering: a
Worker serves multiple users and requests. Detail lookup must continue to authorize
the requested Order/CheckoutGroup UUID against the current customer and website.

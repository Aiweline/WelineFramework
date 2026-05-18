# WeShop_Checkout::order_created

Dispatched after WeShop checkout creates an order.

## Payload

- `order`: created order model or payload.
- `order_id`: created order identifier when available.
- `customer_id`: logged-in customer id, or `0` for guest checkout.
- `is_guest_checkout`: whether the order was placed as a guest.
- `guest_email`: guest email address when guest checkout is used.
- `checkout_data`: normalized checkout data.
- `notification_channels`: selected customer notification channel codes.

## Boundary

Checkout owns collecting form fields and dispatching this event. Notification modules listen to this event and decide how to route customer notifications.

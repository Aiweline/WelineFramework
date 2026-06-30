# Weline_Checkout::frontend::layouts::checkout::notification-preferences

Use this hook to render checkout notification-channel preferences after the checkout identity section.

The checkout module owns only the host. Notification modules provide the concrete UI and submit ordinary checkout form fields such as `notification_channels[]`.

Implementations must not make Checkout call notification services directly. Persisted customer preferences belong to the notification/customer module, and order delivery should be handled from checkout events.

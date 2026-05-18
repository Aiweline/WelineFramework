<?php

declare(strict_types=1);

return [
    'WeShop_Notification::customer::order_notification_sent' => [
        'description' => 'Customer order notification delivery has been processed.',
        'data' => [
            'customer_id' => 'Customer ID',
            'order_id' => 'Order ID',
            'channels' => 'Delivered channel list',
        ],
    ],
];

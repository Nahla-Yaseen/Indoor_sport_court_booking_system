<?php
define('PAYHERE_MODE', 'sandbox');

define('PAYHERE_MERCHANT_ID',     '1236830');
define('PAYHERE_MERCHANT_SECRET', 'MTEyOTAyMjI1MTQyODgxODQ4MzMzNDMyOTQ0OTIwMTM3MTE5MTEx');

define('PAYHERE_CHECKOUT_URL',
    PAYHERE_MODE === 'sandbox'
        ? 'https://sandbox.payhere.lk/pay/checkout'
        : 'https://www.payhere.lk/pay/checkout'
);

// Application base URL
define('APP_BASE_URL', 'http://localhost/myproject');
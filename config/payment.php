<?php
return [
    'provider' => getenv('PAYMENT_PROVIDER') ?: 'sandbox',
    'currency' => getenv('PAYMENT_CURRENCY') ?: 'INR',
    'sandbox' => (getenv('PAYMENT_SANDBOX') ?: '1') === '1',
    'merchant_name' => getenv('PAYMENT_MERCHANT_NAME') ?: 'School ERP',
    'public_key' => getenv('PAYMENT_PUBLIC_KEY') ?: '',
    'secret_key' => getenv('PAYMENT_SECRET_KEY') ?: '',
];

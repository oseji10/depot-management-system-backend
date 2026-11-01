<?php

return [
    'secret' => env('PAYSTACK_SECRET_KEY'),
    'public' => env('PAYSTACK_PUBLIC_KEY'),
    // Where Paystack sends the customer back after paying, e.g. https://carolken.dpms.com.ng/subscription/
    'callback_url' => env('PAYSTACK_CALLBACK_URL'),
];

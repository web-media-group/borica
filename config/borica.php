<?php

declare(strict_types=1);

return [

    /*
     * Terminal and merchant, as issued by the bank. Different between the
     * development gateway and production — along with the keys.
     */
    'terminal' => env('BORICA_TERMINAL', ''),
    'merchant_id' => env('BORICA_MERCHANT_ID', ''),
    'merchant_name' => env('BORICA_MERCHANT_NAME', env('APP_NAME', '')),
    'merchant_url' => env('BORICA_MERCHANT_URL', env('APP_URL', '')),
    'email' => env('BORICA_EMAIL', ''),

    'url' => env('BORICA_URL', 'https://3dsgate-dev.borica.bg/cgi-bin/cgi_link'),

    /*
     * Where the gateway sends the customer back. Borica does not always honour
     * a per-transaction BACKREF, so this endpoint must be able to recognise a
     * transaction belonging to another flow.
     */
    'backref_url' => env('BORICA_BACKREF_URL', ''),

    /*
     * Absolute paths, outside the repository and outside the web root.
     *
     * Paths rather than inline PEM on purpose: a key pasted into an
     * environment variable loses its newlines through most deployment tooling,
     * and openssl then reports only "cannot get private key".
     */
    'private_key' => env('BORICA_PRIVATE_KEY', ''),
    'public_key' => env('BORICA_PUBLIC_KEY', ''),
    'private_key_password' => env('BORICA_PRIVATE_KEY_PASSWORD', ''),

    /*
     * The terminal is issued for one currency and the gateway rejects any
     * other with RC -11. It is also the value a callback is checked against,
     * so a wrong setting here would quietly weaken that check — which is why
     * it is not read from the environment.
     */
    'currency' => 'EUR',

    /*
     * Written to the country field of the request. Not the cardholder's.
     */
    'country' => 'BG',

    /*
     * Every request, response and callback into a table. Payments are
     * reconciled against the bank months later, and a log channel rotates
     * away long before that conversation happens.
     */
    'logging' => [
        'database' => env('BORICA_LOG_DATABASE', true),
        'channel' => env('BORICA_LOG_CHANNEL', 'stack'),
    ],

    /*
     * Seconds. The gateway is occasionally slow under load; a short timeout
     * turns a successful payment into an unknown one.
     */
    'timeout' => env('BORICA_TIMEOUT', 60),

];

<?php
return [
    'MSG91_AUTH_KEY' => env('MSG91_AUTH_KEY', '450989AZnXXzHCbpaB6979bcd0P1'),
    'MSG91_SENDER_ID' => env('MSG91_SENDER_ID', 'ODBUUS'),
    'templates' => [
        'Documents_never_uploaded' => '6a9bc4ed52caa15ac00dd902',
    ],
    'FROM_EMAIL' => 'emailer@mail.odbus.in',
    'EMAIL_TEMPLATE_IDS' => [
        'WALLET_RECHARGE_SUCCESS' => 'odbus_agent_05_wallet_recharge_success',
        'WALLET_NOT_FUNDED' => 'odbus_agent_04_wallet_not_funded_offer_expired',
        'WALLET_NOT_FUNDED_OFFER_LIVE' => 'odbus_agent_03_wallet_not_funded_offer_live',
        'AGENT_ACTIVATED' => 'odbus_agent_02_welcome_activated_2'
    ]
];

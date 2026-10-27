<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Logging;

/**
 * What of a request or an answer may be kept. An allow-list, not a deny-list: a field the gateway
 * adds tomorrow stays out until someone decides it is safe.
 *
 * Never kept: the card number (the masked one keeps its last four digits), the token, the
 * signature, M_INFO (the cardholder's e-mail, name and address). The token's presence is kept as
 * a flag, which is what reconciliation needs.
 */
final class Redactor
{
    public const KEPT = [
        'TERMINAL', 'MERCHANT', 'TRTYPE', 'TRAN_TRTYPE', 'ORDER', 'AMOUNT', 'CURRENCY', 'DESC',
        'ACTION', 'RC', 'STATUSMSG', 'APPROVAL', 'RRN', 'INT_REF', 'PARES_STATUS', 'ECI',
        'TIMESTAMP', 'NONCE', 'MK_TOKEN', 'MERCH_TRAN_STATE', 'MERCH_RN_ID', 'CARD_BRAND',
        'BACKREF', 'LANG', 'AD.CUST_BOR_ORDER_ID',
    ];

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, string>
     */
    public static function fields(array $fields): array
    {
        $kept = [];
        foreach (self::KEPT as $name) {
            if (isset($fields[$name]) && is_scalar($fields[$name]) && (string) $fields[$name] !== '') {
                $kept[$name] = (string) $fields[$name];
            }
        }

        if (isset($fields['CARD']) && is_scalar($fields['CARD']) && preg_match('/(\d{4})\s*$/', (string) $fields['CARD'], $m)) {
            $kept['CARD'] = '****'.$m[1];
        }
        if (! empty($fields['MERCH_TOKEN_ID'])) {
            $kept['MERCH_TOKEN_ID'] = '[present]';
        }

        return $kept;
    }
}

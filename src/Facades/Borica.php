<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Facades;

use Illuminate\Support\Facades\Facade;
use IvoWmg\Borica\Responses\Response;

/**
 * @method static array<string, string> registration(string $trtype, string $order, string $amount, string $description, ?\IvoWmg\Borica\MerchantInfo $info = null, ?string $backref = null)
 * @method static array<string, string> initialTokenized(string $order, string $amount, string $description, ?\IvoWmg\Borica\MerchantInfo $info = null, ?string $backref = null, ?string $merchantReference = null)
 * @method static Response charge(string $token, string $order, string $amount, string $description, ?\IvoWmg\Borica\MerchantInfo $info = null, ?string $merchantReference = null)
 * @method static Response complete(string $order, string $amount, string $rrn, string $internalReference)
 * @method static Response reverse(string $order, string $amount, string $rrn, string $internalReference, ?string $token = null)
 * @method static Response refund(string $order, string $amount, string $rrn, string $internalReference)
 * @method static Response status(string $order, string $originalTrtype = '1')
 * @method static Response revokeToken(string $token, string $order)
 * @method static Response response(array $data)
 *
 * @see \IvoWmg\Borica\Borica
 */
class Borica extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'borica';
    }
}

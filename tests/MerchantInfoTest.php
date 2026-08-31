<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Tests;

use IvoWmg\Borica\MerchantInfo;
use IvoWmg\Borica\Support\Latin;
use PHPUnit\Framework\TestCase;

class MerchantInfoTest extends TestCase
{
    public function test_it_carries_exactly_the_four_expected_fields(): void
    {
        $info = new MerchantInfo('a@b.bg', 'Ivan Petrov', 'Vitosha 1', 'Vitosha 2');

        $this->assertSame(
            ['email', 'cardholderName', 'billAddrLine1', 'shipAddrLine1'],
            array_keys($info->toArray())
        );
    }

    /** Cyrillic in these fields is answered with RC -19. */
    public function test_cyrillic_is_transliterated(): void
    {
        $info = new MerchantInfo('a@b.bg', 'Иван Петров', 'бул. Витоша 1');

        $this->assertSame('Ivan Petrov', $info->toArray()['cardholderName']);
        $this->assertSame('bul. Vitosha 1', $info->toArray()['billAddrLine1']);
    }

    /** Characters the table does not cover survive transliteration and fail just as hard. */
    public function test_leftover_non_ascii_is_stripped(): void
    {
        $this->assertSame('No 5', Latin::convert('№ 5'));
        $this->assertSame('Sofiya - Tsentar', Latin::convert('София — Център'));
        $this->assertSame('"Vitosha"', Latin::convert('„Витоша"'));
        $this->assertSame('', Latin::convert('日本語'));
    }

    public function test_shipping_falls_back_to_billing(): void
    {
        $info = new MerchantInfo('a@b.bg', 'Ivan', 'Vitosha 1');

        $this->assertSame('Vitosha 1', $info->toArray()['shipAddrLine1']);
    }

    /** Truncation happens after conversion, when the byte count has settled. */
    public function test_fields_are_truncated_to_their_limits(): void
    {
        $info = new MerchantInfo('a@b.bg', str_repeat('Щ', 40), str_repeat('я', 40));

        $this->assertLessThanOrEqual(
            MerchantInfo::CARDHOLDER_NAME_MAX,
            strlen($info->toArray()['cardholderName'])
        );
        $this->assertLessThanOrEqual(
            MerchantInfo::ADDRESS_MAX,
            strlen($info->toArray()['billAddrLine1'])
        );
    }

    public function test_it_round_trips_through_base64(): void
    {
        $info = new MerchantInfo('a@b.bg', 'Ivan Petrov', 'Vitosha 1');

        $this->assertSame($info->toArray(), MerchantInfo::decode($info->encode()));
    }

    public function test_decoding_rubbish_yields_an_empty_array(): void
    {
        $this->assertSame([], MerchantInfo::decode('not base64 at all !!'));
        $this->assertSame([], MerchantInfo::decode(base64_encode('{not json')));
    }

    public function test_empty_values_are_dropped_rather_than_sent_blank(): void
    {
        $info = new MerchantInfo('a@b.bg', '日本語', 'Vitosha 1');

        $this->assertArrayNotHasKey('cardholderName', $info->toArray());
    }

    public function test_extra_fields_are_opt_in(): void
    {
        $info = (new MerchantInfo('a@b.bg', 'Ivan', 'Vitosha 1'))
            ->with('threeDSRequestorChallengeInd', '01');

        $this->assertSame('01', $info->toArray()['threeDSRequestorChallengeInd']);
    }
}

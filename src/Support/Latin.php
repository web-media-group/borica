<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Support;

/**
 * Cyrillic to Latin, then anything left that is not ASCII is dropped.
 *
 * The 3DS directory server rejects non-Latin characters in the cardholder and
 * address fields of M_INFO, and answers RC -19 without naming the field. A
 * Bulgarian shop hits this on its first real customer.
 *
 * Transliterating is not enough on its own: characters like № and — survive
 * most tables and fail just as hard, so the pass ends by stripping whatever
 * is still outside ASCII.
 */
final class Latin
{
    /** @var array<string, string> */
    private const MAP = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l',
        'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's',
        'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sht', 'ъ' => 'a', 'ь' => 'y', 'ю' => 'yu', 'я' => 'ya',
        'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Е' => 'E',
        'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I', 'Й' => 'Y', 'К' => 'K', 'Л' => 'L',
        'М' => 'M', 'Н' => 'N', 'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S',
        'Т' => 'T', 'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ц' => 'Ts', 'Ч' => 'Ch',
        'Ш' => 'Sh', 'Щ' => 'Sht', 'Ъ' => 'A', 'Ь' => 'Y', 'Ю' => 'Yu', 'Я' => 'Ya',
    ];

    /**
     * Punctuation that survives the letter table and would otherwise be
     * dropped silently. № in particular appears in almost every Bulgarian
     * street address, and losing it turns "Vitosha No 5" into "Vitosha 5".
     *
     * @var array<string, string>
     */
    private const SYMBOLS = [
        '№' => 'No ', '—' => '-', '–' => '-', '−' => '-',
        '„' => '"', '“' => '"', '”' => '"', '«' => '"', '»' => '"',
        '’' => "'", '‘' => "'", '…' => '...', "\u{00A0}" => ' ',
    ];

    /**
     * @param  int|null  $maxLength  Truncate after conversion — the byte count
     *                               only settles once the Cyrillic is gone.
     */
    public static function convert(?string $value, ?int $maxLength = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = strtr($value, self::SYMBOLS + self::MAP);

        // Whatever both tables missed — accents, other alphabets, emoji.
        $value = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        if ($maxLength !== null && $value !== '') {
            $value = substr($value, 0, $maxLength);
        }

        return $value;
    }
}

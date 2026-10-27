<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Logging;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The `borica_logs` table (publish the migration). One row per exchange: the fields that matter
 * for reconciliation in columns, the rest of the redacted fields as JSON.
 */
final class DatabaseLog implements Logs
{
    public function __construct(private readonly ConnectionInterface $db, private readonly string $table = 'borica_logs') {}

    public function record(string $direction, array $fields, bool $verified = true): void
    {
        try {
            $now = date('Y-m-d H:i:s');
            $this->db->table($this->table)->insert([
                'direction' => $direction,
                'trtype' => $fields['TRTYPE'] ?? null,
                'order' => $fields['ORDER'] ?? null,
                'rc' => $fields['RC'] ?? null,
                'action' => $fields['ACTION'] ?? null,
                'amount' => isset($fields['AMOUNT']) && is_numeric($fields['AMOUNT']) ? $fields['AMOUNT'] : null,
                'currency' => $fields['CURRENCY'] ?? null,
                'rrn' => $fields['RRN'] ?? null,
                'int_ref' => $fields['INT_REF'] ?? null,
                'payload' => json_encode($fields + ($direction === self::REQUEST ? [] : ['verified' => $verified ? 'yes' : 'no']), JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $e) {
            Log::warning('Borica: the exchange was not logged', ['error' => $e->getMessage(), 'order' => $fields['ORDER'] ?? null]);
        }
    }

    /** Rows older than the given number of days are deleted (reconciliation goes back about a year). */
    public function prune(int $days): int
    {
        return $this->db->table($this->table)->where('created_at', '<', date('Y-m-d H:i:s', time() - $days * 86400))->delete();
    }
}

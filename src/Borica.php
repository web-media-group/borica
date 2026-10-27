<?php

declare(strict_types=1);

namespace IvoWmg\Borica;

use IvoWmg\Borica\Exceptions\BoricaException;
use IvoWmg\Borica\Logging\Logs;
use IvoWmg\Borica\Logging\Redactor;
use IvoWmg\Borica\Responses\Response;
use IvoWmg\Borica\Responses\ResponseParser;
use IvoWmg\Borica\Signature\SignatureBuilder;
use IvoWmg\Borica\Signature\Signs;

/**
 * The gateway.
 *
 * Browser-delivered requests ([[registration]], [[initialTokenized]]) return a
 * field array for an auto-submitting form; the customer goes to their bank and
 * the result arrives at BACKREF. Server-to-server calls ([[complete]],
 * [[reverse]], [[refund]], [[status]], [[charge]]) answer directly.
 */
class Borica
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly Signs $signer,
        private readonly ?HttpClient $http = null,
        private readonly ?Logs $log = null,
    ) {}

    // ── Browser-delivered ────────────────────────────────────────────────────

    /**
     * A one-off payment: sale or pre-authorization.
     *
     * @return array<string, string> POST these to the gateway URL.
     */
    public function registration(
        string $trtype,
        string $order,
        string $amount,
        string $description,
        ?MerchantInfo $info = null,
        ?string $backref = null,
    ): array {
        return $this->logged($this->signed($this->baseFields($trtype, $order, $amount, $description, $info, $backref)));
    }

    /**
     * The customer-present transaction that establishes a stored credential.
     *
     * MK_TOKEN asks for a token; MERCH_TRAN_STATE 'S' says save it. There is
     * no token to sign with yet, so the RFU slot stays a bare dash — the
     * gateway returns MERCH_TOKEN_ID in the callback.
     *
     * @return array<string, string>
     */
    public function initialTokenized(
        string $order,
        string $amount,
        string $description,
        ?MerchantInfo $info = null,
        ?string $backref = null,
        ?string $merchantReference = null,
    ): array {
        $fields = $this->baseFields(Trtype::SALE, $order, $amount, $description, $info, $backref);

        $fields['MK_TOKEN'] = 'MERCH';
        $fields['MERCH_TRAN_STATE'] = 'S';
        $fields['MERCH_RN_ID'] = $merchantReference ?? $this->merchantReference($order);

        return $this->logged($this->signed($fields));
    }

    // ── Server-to-server ─────────────────────────────────────────────────────

    /**
     * Charge a stored credential. No customer, no browser.
     *
     * The token goes into the signature length-prefixed. Signing this with the
     * bare dash that every other request uses is answered RC -17.
     */
    public function charge(
        string $token,
        string $order,
        string $amount,
        string $description,
        ?MerchantInfo $info = null,
        ?string $merchantReference = null,
    ): Response {
        $fields = $this->baseFields(Trtype::SALE, $order, $amount, $description, $info);

        $fields['MERCH_TOKEN_ID'] = $token;
        $fields['MERCH_TRAN_STATE'] = 'M';
        $fields['MERCH_RN_ID'] = $merchantReference ?? $this->merchantReference($order);

        return $this->send($this->signed($fields, $token));
    }

    /**
     * Complete a pre-authorization.
     *
     * The amount may be lower than the one authorized — a shop that ships less
     * than was ordered captures less — but never higher.
     */
    public function complete(string $order, string $amount, string $rrn, string $internalReference): Response
    {
        return $this->send($this->signed(
            $this->managementFields(Trtype::COMPLETE, $order, $amount, $rrn, $internalReference)
        ));
    }

    /**
     * Release a pre-authorization. Must carry the full authorized amount.
     */
    public function reverse(string $order, string $amount, string $rrn, string $internalReference, ?string $token = null): Response
    {
        return $this->send($this->signed(
            $this->managementFields(Trtype::REVERSAL, $order, $amount, $rrn, $internalReference),
            $token
        ));
    }

    /**
     * Refund a completed transaction, in full or in part.
     *
     * Note the absent token even when the original was tokenized: the
     * specification's Table 3 suggests otherwise, but the gateway rejects the
     * tokenized form here. Established by trying both.
     */
    public function refund(string $order, string $amount, string $rrn, string $internalReference): Response
    {
        return $this->send($this->signed(
            $this->managementFields(Trtype::REFUND, $order, $amount, $rrn, $internalReference)
        ));
    }

    /**
     * Ask what happened to a transaction.
     *
     * The one request signed over four fields with no RFU. Use it whenever a
     * callback did not arrive, and before cancelling anything on a duplicate.
     */
    public function status(string $order, string $originalTrtype = Trtype::SALE): Response
    {
        $fields = [
            'TERMINAL' => $this->config['terminal'],
            'TRTYPE' => Trtype::STATUS_CHECK,
            'ORDER' => $order,
            'TRAN_TRTYPE' => $originalTrtype,
            'NONCE' => $this->nonce(),
        ];

        return $this->send($this->signed($fields));
    }

    /** Withdraw a stored credential — when a subscription ends. */
    public function revokeToken(string $token, string $order): Response
    {
        $fields = [
            'TERMINAL' => $this->config['terminal'],
            'TRTYPE' => Trtype::TOKEN_REVOKE,
            'ORDER' => $order,
            'MERCH_TOKEN_ID' => $token,
            'TIMESTAMP' => $this->timestamp(),
            'NONCE' => $this->nonce(),
        ];

        return $this->send($this->signed($fields, $token));
    }

    // ── Incoming ─────────────────────────────────────────────────────────────

    /**
     * Verify a callback.
     *
     * Throws rather than returning unverified when the key cannot be read: an
     * unverifiable callback is an unauthenticated one, and a deployment that
     * has lost its public key must fail loudly rather than accept payments it
     * cannot check.
     *
     * @param  array<string, mixed>  $data
     */
    public function response(array $data): Response
    {
        $response = $this->verified($data);
        $this->log?->record(Logs::CALLBACK, Redactor::fields($data), $response->isVerified());

        return $response;
    }

    /** @param  array<string, mixed>  $data */
    private function verified(array $data): Response
    {
        $pSign = (string) ($data['P_SIGN'] ?? '');

        if ($pSign === '') {
            return new Response($data, false);
        }

        return new Response(
            $data,
            $this->signer->verify(SignatureBuilder::responseSource($data), $pSign)
        );
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function baseFields(
        string $trtype,
        string $order,
        string $amount,
        string $description,
        ?MerchantInfo $info,
        ?string $backref = null,
    ): array {
        if (! OrderReference::isValid($order)) {
            throw new BoricaException(
                'ORDER must be exactly six digits, got "'.$order.'". '
                .'Use OrderReference::fromId() and plan the reverse lookup — see the README.'
            );
        }

        $fields = [
            'TERMINAL' => $this->config['terminal'],
            'TRTYPE' => $trtype,
            'AMOUNT' => $this->amount($amount),
            'CURRENCY' => $this->config['currency'],
            'ORDER' => $order,
            'DESC' => mb_substr($description, 0, 50),
            'MERCHANT' => $this->config['merchant_id'],
            'MERCH_NAME' => $this->config['merchant_name'],
            'MERCH_URL' => $this->config['merchant_url'],
            'EMAIL' => $this->config['email'],
            'COUNTRY' => $this->config['country'],
            'MERCH_GMT' => substr(date('O'), 0, 3),
            'BACKREF' => $backref ?: $this->config['backref_url'],
            'ADDENDUM' => 'AD,TD',
            'AD.CUST_BOR_ORDER_ID' => $order,
            // Generated at submit, never at render: the gateway allows fifteen
            // minutes of drift and a page left open blows past it (RC -20).
            'TIMESTAMP' => $this->timestamp(),
            'NONCE' => $this->nonce(),
        ];

        if ($info instanceof MerchantInfo) {
            $fields['M_INFO'] = $info->encode();
        }

        return array_filter($fields, static fn ($v): bool => $v !== '' && $v !== null);
    }

    /** @return array<string, string> */
    private function managementFields(
        string $trtype,
        string $order,
        string $amount,
        string $rrn,
        string $internalReference,
    ): array {
        return [
            'TERMINAL' => $this->config['terminal'],
            'TRTYPE' => $trtype,
            'AMOUNT' => $this->amount($amount),
            'CURRENCY' => $this->config['currency'],
            'ORDER' => $order,
            'RRN' => $rrn,
            'INT_REF' => $internalReference,
            'MERCHANT' => $this->config['merchant_id'],
            'TIMESTAMP' => $this->timestamp(),
            'NONCE' => $this->nonce(),
        ];
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    private function signed(array $fields, ?string $token = null): array
    {
        $fields['P_SIGN'] = $this->signer->sign(
            SignatureBuilder::requestSource($fields, $token)
        );

        return $fields;
    }

    /** @param array<string, string> $fields */
    private function send(array $fields): Response
    {
        $this->logged($fields);

        if (! $this->http instanceof HttpClient) {
            throw new BoricaException(
                'No HTTP client configured; server-to-server calls are unavailable. '
                .'In Laravel this is wired by the service provider.'
            );
        }

        $body = $this->http->post((string) $this->config['url'], $fields);

        $data = ResponseParser::parse($body);
        $response = $this->verified($data);
        $this->log?->record(Logs::RESPONSE, Redactor::fields($data), $response->isVerified());

        return $response;
    }

    /**
     * A request, kept redacted (the form the browser posts is logged when it is built).
     *
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    private function logged(array $fields): array
    {
        $this->log?->record(Logs::REQUEST, Redactor::fields($fields));

        return $fields;
    }

    /** Two decimals, dot separator. RC -10 otherwise. */
    private function amount(string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /** UTC. Fifteen minutes of tolerance, per the interface document. */
    private function timestamp(): string
    {
        return gmdate('YmdHis');
    }

    private function nonce(): string
    {
        return strtoupper(bin2hex(random_bytes(16)));
    }

    /** 16 characters, per the field definition. */
    private function merchantReference(string $order): string
    {
        return substr(str_pad($order.date('ymdHis'), 16, '0'), 0, 16);
    }
}

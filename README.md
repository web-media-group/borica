# Borica

Borica APGW (EMV 3DS) card payments for PHP and Laravel — and, more usefully,
a written record of the failures the specification does not warn about.

Two production integrations reached the same conclusions independently, one a
shop on pre-authorization, one a subscription service on tokenized recurring
payments. The protocol layer here is tested byte-for-byte against the worked
examples in Appendix 1 of P-OM-41 v5.0.

The repository holds three things:

| | | |
|---|---|---|
| **package** | `src/`, `config/`, `database/` | the code |
| **skill** | `skill/` | the same knowledge for an AI assistant |
| **this file** | | how to use either |

---

## Install

```bash
composer require ivo-wmg/borica
```

Laravel discovers the provider. Publish the config and the log table:

```bash
php artisan vendor:publish --tag=borica-config
php artisan vendor:publish --tag=borica-migrations
php artisan migrate
```

```env
BORICA_TERMINAL=
BORICA_MERCHANT_ID=
BORICA_URL=https://3dsgate-dev.borica.bg/cgi-bin/cgi_link
BORICA_BACKREF_URL=https://example.com/payment/borica/callback
BORICA_PRIVATE_KEY=/absolute/path/outside/the/web/root/terminal.key
BORICA_PUBLIC_KEY=/absolute/path/outside/the/web/root/apgw.cer
BORICA_PRIVATE_KEY_PASSWORD=
```

Keys belong outside the repository and outside the web root. The public key is
Borica's, used to verify their callbacks; the private key is yours, used to
sign your requests.

---

## The part worth reading

`P_SIGN` is RSA-SHA256 over a string where each field is its **byte length**
followed by its value, and an empty field is a **bare `-`**:

```
8V18000011141.003BGN6113920142020101308393232D41AAAFC7F8119A3BB7C4868E0B256F9-
│└─TERMINAL─┘│││└AMOUNT│└CUR┘│└ORDER┘ │└──TIMESTAMP─┘ │└─────────NONCE────────┘│
8            1 1 4      3     6        14              32                       └─ RFU
```

That final `-` is the RFU slot. **On a merchant-initiated transaction it holds
`MERCH_TOKEN_ID` instead, length-prefixed.** Signing an MIT with the bare dash
that works everywhere else returns RC -17 — "terminal denied access" — which
reads as a certificate problem and is not one.

```php
use IvoWmg\Borica\Signature\SignatureBuilder;

SignatureBuilder::requestSource($data);          // one-off, or the initial CIT
SignatureBuilder::requestSource($data, $token);  // every subsequent MIT
SignatureBuilder::responseSource($post);         // reads the token back out
```

---

## Usage

### A one-off payment

```php
use IvoWmg\Borica\Facades\Borica;
use IvoWmg\Borica\OrderReference;
use IvoWmg\Borica\MerchantInfo;
use IvoWmg\Borica\Trtype;

$fields = Borica::registration(
    trtype: Trtype::PREAUTH,                       // or Trtype::SALE
    order: OrderReference::fromId($order->id),
    amount: '25.00',
    description: 'Order '.$order->number,
    info: new MerchantInfo(
        email: $order->email,
        cardholderName: $order->customer_name,     // transliterated for you
        billingAddress: $order->address,
    ),
);
```

`$fields` is an array to POST to the gateway from an auto-submitting form. The
browser goes to the bank; your callback hears the result.

### The callback

```php
public function callback(Request $request)
{
    $response = Borica::response($request->all());   // verifies P_SIGN, throws if it cannot

    if (! $response->isVerified()) {
        return redirect()->route('checkout')->with('error', __('Payment could not be verified.'));
    }

    ProcessBoricaCallback::dispatch($request->all());

    return redirect()->route('checkout.success');
}
```

Verify, dispatch, redirect. Nothing else belongs here — see below.

### Completing and reversing a pre-authorization

```php
Borica::complete($order, amount: '23.40');   // may be less than authorized, never more
Borica::reverse($order);                     // must be the full authorized amount
Borica::refund($order, amount: '10.00');     // against a completed transaction
Borica::status($order);                      // TRTYPE 90
```

### Recurring payments

```php
// 1. Customer present. The callback returns MERCH_TOKEN_ID — store it encrypted.
$fields = Borica::initialTokenized(order: $ref, amount: '0.01', info: $info);

// 2. No customer. Server to server, as often as you like.
$result = Borica::charge(token: $subscription->token, order: $ref, amount: '19.99');

// 3. When they cancel.
Borica::revokeToken($subscription->token);
```

---

## Rules the package cannot enforce for you

1. **Verify every callback, and fail closed.** A missing public key is an
   error, never a skipped check.
2. **A valid signature proves the sender, not the amount.** Compare AMOUNT and
   CURRENCY to what you asked for.
3. **Return from the callback immediately.** Do the work in a queued job.
   Anything slow here times out and Borica retries, giving you the payment
   twice.
4. **Never cancel on an in-progress code.** `-31`, `-33`, `-40` mean the
   customer is still at their bank.
5. **Never downgrade a successful authorization.** Late duplicate declines
   arrive.
6. **Make every write idempotent.** Callbacks repeat.
7. **`ORDER` is six digits.** Plan the reverse lookup before you need it.
8. **Never log the POST body.** It can carry a PAN.

`skill/reference/traps.md` explains what each of these costs when ignored.

---

## The skill

`skill/` is a [Claude Code skill](https://docs.claude.com/en/docs/claude-code/skills)
holding the same knowledge in a form an AI assistant will pick up on its own
when it sees Borica work.

**Use it in one project:**

```bash
git clone https://github.com/ivo-wmg/borica.git /tmp/borica
cp -r /tmp/borica/skill ~/your-project/.claude/skills/borica
```

**Use it everywhere:**

```bash
cp -r /tmp/borica/skill ~/.claude/skills/borica
```

The assistant loads it by its description when a task mentions Borica, APGW,
`P_SIGN`, `MAC_GENERAL`, `TRTYPE` or `MERCH_TOKEN_ID`. Nothing needs invoking
by hand.

**With another tool.** The files are plain Markdown with no Claude-specific
syntax beyond the frontmatter. Point any assistant that reads files at
`skill/SKILL.md` and it will work — for Cursor, `.cursorrules`; for Copilot,
`.github/copilot-instructions.md`; otherwise paste `skill/reference/traps.md`
into the conversation before starting.

**What it contains**

| file | |
|---|---|
| `SKILL.md` | the signing rules, the decisions to settle first, a checklist |
| `reference/traps.md` | every trap, with what it costs — read before writing signing code |
| `reference/rc-codes.md` | one authoritative RC table and what to do per class |

---

## Testing

```bash
composer install
vendor/bin/phpunit
```

The signature tests reproduce both Appendix 1 examples byte for byte. If you
change `SignatureBuilder`, those must still pass — they are the only check on
this string that does not need a live terminal.

---

## Going live

The dev gateway is open and useful. Production needs a different host, a
different terminal id, and different keys. The certificate comes from B-Trust
separately from the terminal credentials, and neither works without the other.
On both integrations behind this package, waiting for those was longer than
writing the code.

---

## Licence

MIT. Not affiliated with or endorsed by Borica AD. Field definitions belong to
their specification; what is here is the experience of implementing against it.

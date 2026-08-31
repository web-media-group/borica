# Traps

Each of these cost real time in production. None is stated plainly in P-OM-41.
Sources: two independent Bulgarian integrations, one a shop on pre-auth with
gift cards, one a subscription service on tokenized recurring payments.

---

## Signing

### The dash is not one rule but three

An empty field inside the string is `-` with **no** length prefix. The RFU slot
at the end is `-` as a literal. And a status request has no such slot at all.

```
empty field   …3BGN-6113920…      not …3BGN06113920…
RFU slot      …{NONCE}-           not …{NONCE}1-
TRTYPE 90     …{NONCE}            nothing after
```

The specification does say this — "the meaning of field RFU in the symbol
string is one byte 0x2D" — but in a note under a table, and every wrong variant
fails identically with no diagnostic.

### MERCH_TOKEN_ID takes the RFU slot on MIT

Length-prefixed when present, bare dash when not. The failure mode is RC -17,
"terminal denied access to e-Gateway", which sounds like the certificate is
wrong. It is not; the string is.

The same applies to **verifying** a response that echoes a token back.

Refund (TRTYPE 24) is the exception found empirically: it signs with the bare
dash even for a tokenized original, contradicting the doc's Table 3. Reversal
(TRTYPE 22) does include the token. This was discovered by trying both.

### The length prefix counts bytes

Use `strlen`, not `mb_strlen`. Every signed field is ASCII by protocol so the
two agree today, but a multi-byte character reaching a signed field would sign
a wrong length and fail with nothing in the response to explain it.

### Keys must be loaded from a file

`openssl_pkey_get_private('file://'.$path, $password)`. A PEM pasted into an
environment variable loses its newlines through most deployment tooling and
openssl then reports only "cannot get private key".

The public key ships as a certificate (`.cer`) in some packs and a bare PEM in
others. `openssl_pkey_get_public` reads either.

---

## The ORDER field

### Six digits, and no more

Order 1 000 001 goes out as `000001` and comes back the same. A callback
handler that does `Order::find($post['ORDER'])` works until the millionth
order and then attaches payments to the wrong ones.

Reverse it by remainder, scoped to the reconciliation window, newest first:

```sql
WHERE id % 1000000 = :truncated
  AND created_at >= NOW() - INTERVAL 30 DAY
ORDER BY id DESC
LIMIT 1
```

Truncation is only safe because the gateway reconciles about 30 days back. Two
orders can share a remainder, but not inside one window unless the shop takes a
million orders a month.

### Anything else numbering into that space will collide

Gift cards, subscriptions, invoices — if they derive a reference from their own
row id, two records eventually produce the same six digits and the callback
picks one arbitrarily, activating the wrong card or crediting the wrong buyer.

Allocate references randomly from the six-digit space instead, put a unique
index behind them, and retry on violation. A collision here is not recoverable
after the fact: choosing the wrong record cannot be undone by resolving the
order correctly later.

### BACKREF is a request, not a guarantee

A per-transaction BACKREF is sometimes ignored and the callback arrives at the
default endpoint instead. Every callback endpoint must therefore be able to
recognise a transaction that "belongs" to another flow, or hand it on.

---

## Responses

### The RC field carries two unrelated code families

Negative codes come from the gateway and describe the request. Two-digit codes
come from the card issuer and describe the card. `00` is the only success.

### ACTION=1 with RC=00 is a success

It means duplicate. The money moved. Treating it as failure cancels a paid
order.

### Three codes mean "still running"

`-31` transaction already in progress, `-33` cardholder authentication in
progress, `-40` client-side form in progress. A customer taking their time on
the bank's page produces these. Cancelling on them leaves the authorization to
succeed a minute later against an order that no longer exists — money held, no
order to fulfil.

**`-24` is not one of them**, though it is easy to assume so. Per the official
list it is "transaction context data mismatch" — wrong currency, or a
transaction older than 24 hours. One production system treats it as in-progress
and leaves genuinely broken payments pending forever.

### Duplicates need asking, not deciding

`-21` "already executed" and `-32` "duplicate declined". The original may have
succeeded. Ask TRTYPE 90 before doing anything; if the status check itself
fails or returns -17, leave the order pending for a human rather than
cancelling.

### A valid signature says nothing about the amount

It proves the message came from Borica. It does not prove the message concerns
this order for this amount. Compare AMOUNT and CURRENCY against what was
requested, and treat a mismatch as suspicious rather than as either success or
failure — auto-cancelling a mismatch is its own bug.

### Responses are not reliably JSON

Server-to-server replies have arrived as JSON, as JSON wrapped in a `<div>`,
and as a query string. Parse defensively in that order.

---

## Money and state

### Never downgrade a successful authorization

Callbacks duplicate and arrive late. A decline landing after a success must not
cancel the order, refund the wallet and email the customer.

### Idempotency is not optional

Key the payment row on something stable — order, payment type, transaction
type — and use an upsert. Guard the status transition and fire events only when
the status actually changed, or a retried job sends a second confirmation
email.

### Paid-but-cancelled must be loud, not clever

If a success arrives for an order already cancelled, the card is authorized and
the order is not. Do not silently revive it: stock, capacity and any wallet
debit were already released, so reviving can oversell. Log it at critical and
make a human decide.

### Complete and reverse have opposite amount rules

TRTYPE 21 complete may be for **less** than authorized, never more. TRTYPE 22
reversal must be for the **full** authorized amount.

### Decimal precision bites on comparison

Borica works to 2 decimals. A schema storing `decimal(x,3)` produces false
mismatches — 40.674 against 40.670 — when checking whether a capture exceeds
its authorization. Round both sides to 2 before comparing.

### Abandoned payments hold real resources

Stock, delivery capacity and wallet debits are typically committed when the
order is created, before the card is authorized. A customer who closes the
browser on the payment page leaves all of it locked with nothing to release it.
A scheduled sweep must cancel these and explicitly dispatch whatever event the
release logic listens on.

Do not auto-cancel an order that has a payment link: the customer may click it
days later.

---

## 3DS and M_INFO

### Latin only

The directory server, not Borica, refuses Cyrillic in the cardholder and
address fields, answering RC -19 without naming the field. Transliterate — and
then strip whatever the table missed, because `№`, em dashes and typographic
quotes survive most transliteration tables and fail just as hard.

### Four fields

`email`, `cardholderName`, `billAddrLine1`, `shipAddrLine1`. Both integrations
reached this shape only after repeatedly adding fields, failing, and removing
them again. Mastercard requirement 209 made the address fields mandatory;
additional keys are accepted by the JSON and have caused rejections in
practice.

Lengths: cardholder name 45, address lines 50. Truncate **after**
transliteration, when the byte count has settled.

### TIMESTAMP tolerates 15 minutes

UTC, `gmdate('YmdHis')`. The code list says an hour for RC -20; the interface
document says 15 minutes, and the interface document is the one being enforced.

Generate it when the request is submitted. A payment page rendered and left
open produces a stale timestamp and RC -20.

---

## Tokenization (MIT)

### The flow

1. **CIT** — the customer is present, TRTYPE 1, with `MK_TOKEN=MERCH` and
   `MERCH_TRAN_STATE=S` (save). The callback returns `MERCH_TOKEN_ID`.
2. **MIT** — no customer, TRTYPE 1 again with `MERCH_TOKEN_ID` and
   `MERCH_TRAN_STATE=M` (merchant-initiated), sent server-to-server. `MK_TOKEN`
   is absent.
3. **Revoke** — TRTYPE 82.

There is no distinct "recurring" TRTYPE. `MERCH_TRAN_STATE` is what separates
saving a credential from using one.

### Trials

A 0.00 authorization was not accepted; the working pattern is a real 0.01
charge refunded immediately. Budget for the refund path from the start.

### Store the token encrypted, and widen the column

Tokens exceed 255 characters once encrypted at rest. And do not log the sign
string at debug level — it contains the token in clear, defeating the
encryption.

### Extend from the period end, not from now

Renewing a subscription from `now()` silently shortens every period by the time
between due date and successful charge.

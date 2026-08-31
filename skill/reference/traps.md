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

### Four codes mean "nothing has been decided"

`-40` client-side form in progress, `-33` cardholder authentication in
progress, `-31` transaction already in progress, and `-24`. A customer taking
their time on the bank's page produces these. Cancelling leaves the
authorization to succeed a minute later against an order that no longer
exists — money held, no order to fulfil.

`-24` belongs in that list on evidence, not on the specification, which calls
it "transaction context data mismatch" and offers a wrong currency or an age
over 24 hours. Across 221 production occurrences neither was true: it arrived
**one to three minutes after the order**, carrying the placeholders the gateway
uses when it holds nothing at all — `CURRENCY=USD`, blank `AMOUNT`, empty
`STATUSMSG`. It means "I have no such transaction yet".

Of the 131 orders that saw it, **seven went on to pay successfully**. A system
that cancels on `-24` would have taken their money and cancelled their orders.

### USD and 0.00 are not a currency bug

A status check for a transaction the gateway has not registered comes back with
`CURRENCY=USD` and `AMOUNT=0.00`. It is filler, not a real transaction in
dollars — across 3600 outgoing requests in one shop, not one was in anything
but EUR. Do not chase it, and do not compare amounts on a reply that carries
an in-progress code.

### ACTION is more useful than RC, and undocumented

The specification tabulates RC and leaves ACTION alone, but ACTION is what
separates a decline from an error:

| ACTION | |
|---|---|
| `0` | approved |
| `1` | duplicate of an approved transaction — still approved |
| `2` | declined by the issuer, or expired |
| `3` | refused before the issuer — gateway or 3DS |

### -19 is the customer, not your M_INFO

Malformed M_INFO does land on `-19`, and during development that is usually
what it is. In production it almost never is: of 1007 occurrences in one shop,
955 carried "Authentication failed." with `PARES_STATUS=N` — the cardholder
entered the wrong code or gave up at their bank. It is the single commonest
failure there is. Do not go looking at your address fields when live traffic
starts producing it.

### -20 is an abandoned page, not a clock

The published code list explains `-20` as the merchant's clock drifting from
the gateway's. It is also what you get when a customer opens the payment page
and walks away: it arrives 15 to 20 minutes after the order, with `ACTION=2`
and the message "Expired transaction." Same fifteen-minute window, entirely
different cause, and the ordinary one.

If `-20` appears on the *first* request of every payment, the clock is wrong.
If it appears 15 minutes in on some payments, those customers left.

### -17 means two different things

On a request it is almost always a signature failure — the RFU tail first of
all. On a status check it usually means the gateway will not discuss that
transaction: 90 such replies in one shop carried an empty message and no
amount, while thousands of other requests signed identically went through.

The test is proportion. If every request fails with `-17`, the signature is
wrong. If a handful do, it is not.

### Duplicates need asking, not deciding

`-21` "already executed" and `-32` "duplicate declined". The original may have
succeeded. Ask TRTYPE 90 before doing anything; if the status check itself
fails or returns `-17`, leave the order pending for a human rather than
cancelling.

### Some real codes are not in the published list

`-48` "System error." turns up in live traffic and appears nowhere in the code
list. Treat an unknown negative code as a failure, log it verbatim, and do not
assume the table is complete.

### -30 may never appear at all

"Declined as fraud" was not seen once in roughly 6000 logged replies over five
months. Two production systems carried contradictory descriptions for it —
"session expired" in one, "reversed transaction" in another — which is what
happens when a table is filled in from guesswork rather than from traffic.

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

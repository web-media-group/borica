---
name: borica
description: Integrate Borica APGW (Bulgarian card payments, EMV 3DS) — signing, callbacks, pre-auth and capture, tokenized recurring payments (MIT), and the failures the specification does not warn about. Use when work involves Borica, APGW, 3dsgate, P_SIGN, MAC_GENERAL, TRTYPE, MERCH_TOKEN_ID, or a Bulgarian card gateway.
---

# Borica APGW

The specification (P-OM-41) is 55 pages and accurate. Read it for field
definitions. This skill covers what it does not say — the failures that cost
two production integrations several months between them.

Read `reference/traps.md` before writing any signing code. Read
`reference/rc-codes.md` before writing any code that reacts to a response.

## The one thing that breaks everything

`P_SIGN` is RSA-SHA256 over a string where **each field is its byte length
followed by its value**, and an empty field is a **bare `-` with no length
prefix**.

```
8V18000011141.003BGN6113920142020101308393232D41AAAFC7F8119A3BB7C4868E0B256F9-
│└─TERMINAL─┘│││└AMOUNT│└CUR┘│└ORDER┘ │└──TIMESTAMP─┘ │└─────────NONCE────────┘│
8            1 1 4      3     6        14              32                       └─ RFU
```

Field order, from Table 10:

| | request | response |
|---|---|---|
| TRTYPE 1, 12, 21, 22, 24 | TERMINAL, TRTYPE, AMOUNT, CURRENCY, ORDER, TIMESTAMP, NONCE, **RFU** | ACTION, RC, APPROVAL, TERMINAL, TRTYPE, AMOUNT, CURRENCY, ORDER, RRN, INT_REF, PARES_STATUS, ECI, TIMESTAMP, NONCE, **RFU** |
| TRTYPE 90 | TERMINAL, TRTYPE, ORDER, NONCE — **no RFU** | same as above, RFU included |

**The RFU slot is not always RFU.** On a merchant-initiated transaction it
carries `MERCH_TOKEN_ID`, length-prefixed like any other field. Sign an MIT
with the bare dash that works everywhere else and the gateway answers RC -17,
"terminal denied access" — which reads as a certificate problem and sends you
to the wrong place for a week.

```
no token   …{NONCE}-
token      …{NONCE}36ABCDEF-1234-6789-ABCD-EF0123456789
```

A default of `'-'` threaded into the length-prefixing branch produces `1-`,
which is a different string from `-`. This is a real bug that shipped.

## Use the package rather than rewriting this

`ivo-wmg/borica` implements the above with the two worked examples from
Appendix 1 as tests. Prefer it:

```php
use IvoWmg\Borica\Signature\SignatureBuilder;
use IvoWmg\Borica\Signature\Signer;

$source = SignatureBuilder::requestSource($data);              // one-off / CIT
$source = SignatureBuilder::requestSource($data, $token);      // MIT
$pSign  = $signer->sign($source);

$ok = $signer->verify(SignatureBuilder::responseSource($post), $post['P_SIGN']);
```

If the project cannot take a dependency, copy `src/Signature/SignatureBuilder.php`
and its test. Do not reimplement from the PDF; the dash rules are easy to get
subtly wrong and the gateway never tells you which part is wrong.

## Decisions to settle before writing code

**Sale or pre-authorization.** TRTYPE 1 takes the money now. TRTYPE 12 holds it
until a TRTYPE 21 completes it, which is what a shop that charges on dispatch
needs — and it means every authorization now owes the customer either a
completion or a reversal. Changing later needs the bank.

**The ORDER field is six digits.** Not "usually enough" — exactly six. Order
1 000 001 arrives back as `000001`. Decide now how the callback finds its
order; see `reference/traps.md`.

**Who else shares the six-digit space.** Gift cards, subscriptions and orders
drawing references from the same range will collide, and the callback then
picks the wrong record.

## Rules that are not negotiable

1. **Verify the signature on every callback, and refuse to proceed without the
   public key.** A missing key must be an error, never a skipped check.
2. **A valid signature proves the sender, not the amount.** Compare AMOUNT and
   CURRENCY against what you asked for. Without it, a genuinely signed response
   for 1.00 authorizes an order of 100.00.
3. **Return from the callback immediately.** Verify, dispatch a job, redirect.
   Database writes and emails in the HTTP request will time out and Borica will
   retry, giving you the same payment twice.
4. **Never cancel on an in-progress RC.** `-31`, `-33`, `-40` mean the customer
   is still at their bank. Cancelling then leaves money held against a
   cancelled order. `-21` and `-32` mean "already ran" — ask for status first.
5. **Never downgrade a successful authorization.** A late or duplicated decline
   must not cancel an order whose card was charged.
6. **Make every write idempotent.** Callbacks arrive more than once. Key the
   payment row on something stable and guard the status transition, or the
   customer gets two confirmation emails.
7. **TIMESTAMP is UTC and tolerates 15 minutes.** Generate it when the request
   is submitted, never when a page is rendered.
8. **Latin only in M_INFO.** Cyrillic is refused with RC -19 by the directory
   server, not by Borica.
9. **Never log the POST body.** It can carry a PAN. Log named fields.

## Checklist for a new integration

- [ ] Sale or pre-auth decided, and the completion path built if pre-auth
- [ ] Signature covered by tests using the Appendix 1 examples
- [ ] Callback verifies signature, then amount, then currency
- [ ] Callback returns inside a few milliseconds; work happens in a job
- [ ] Order lookup handles six-digit truncation and scopes to 30 days
- [ ] In-progress and duplicate RCs branch away from the cancel path
- [ ] A scheduled reconciliation asks TRTYPE 90 about anything still pending
- [ ] Abandoned payments release stock and capacity on a timer
- [ ] Keys outside the repository and outside the web root
- [ ] Dedicated log channel; no PAN, no token, no full POST body

## Going live

The dev gateway is `https://3dsgate-dev.borica.bg/cgi-bin/cgi_link`; production
is a different host, a different terminal, and **different keys**. The
certificate arrives from B-Trust separately from the terminal, and neither is
usable without the other. Expect this to be the long pole in the schedule
rather than the code.

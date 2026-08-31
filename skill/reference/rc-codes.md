# RC codes

From the official code list (P-OM-41). Two unrelated families arrive in the
same field.

**Negative** — from the gateway, about the request.
**Two-digit** — from the card issuer through the acquirer, about the card.
`00` is the only success.

## What to do, by class

| Class | Codes | Action |
|---|---|---|
| Success | `00` | Authorize. `ACTION=1` alongside it means duplicate — still a success. |
| Not decided yet | `-24`, `-31`, `-33`, `-40` | Nothing. **Never cancel.** |
| Duplicate | `-21`, `-32` | Ask TRTYPE 90 first. Cancel only on a definite negative answer. |
| Everything else | | Definitive refusal. Safe to cancel and release. |

The classification matters more than the wording. A code meaning "still
running" answered as a decline cancels an order whose authorization then
succeeds — money held against nothing.

**ACTION says more than RC about what to do**, and the specification does not
tabulate it. From production:

| ACTION | |
|---|---|
| `0` | approved |
| `1` | duplicate of an approved transaction — **still approved** |
| `2` | declined by the issuer (`05`, `51`, `54`, `57`, `61`, `62`) or expired (`-20`) |
| `3` | refused before the issuer — the gateway or 3DS |
| `6`, `7`, `21` | rare; seen only beside `-20` and `-17` |

## Why -24 is in the "not decided" row

The specification calls `-24` "transaction context data mismatch" and offers a
wrong currency or an age over 24 hours. Neither survives contact with the data.
Across 221 occurrences it arrived **one to three minutes** after the order, with
the placeholders the gateway uses when it holds nothing at all — `CURRENCY=USD`,
blank `AMOUNT`, empty `STATUSMSG`. It means "I have no such transaction yet".

Of the 131 orders that saw it, **seven went on to pay successfully**. Cancelling
on `-24` would have taken their money and cancelled their orders.

## Gateway codes

| RC | Meaning | Note |
|---|---|---|
| `00` | Approved | |
| `-1` | A mandatory request field is not filled in | |
| `-2` | Bad CGI request | A field name is wrong, or a value is malformed |
| `-3` | Acquirer host does not respond, or bad response template | |
| `-4` | No connection to the acquirer host | |
| `-5` | Acquirer host connection failed during processing | |
| `-6` | e-Gateway configuration error | Their side; contact Borica |
| `-7` | Acquirer host response is invalid | |
| `-10` | Error in the AMOUNT field | Check formatting: `1.00`, not `1,00` or `1` |
| `-11` | Error in the CURRENCY field | Must match the terminal's currency |
| `-12` | Error in the MERCHANT field | |
| `-13` | Unexpected referrer IP address | Production IP not registered |
| `-15` | Error in the RRN field | |
| `-16` | Another transaction is running on this terminal | Retry shortly |
| `-17` | Terminal denied access to e-Gateway | On a *request* this is **usually a P_SIGN failure** — first suspect the RFU / MERCH_TOKEN_ID tail. On a *status check* it more often means the gateway will not discuss that transaction; 90 such replies carried an empty message and no amount. If every request fails with it, the signature is wrong; if only some do, it is not. |
| `-19` | Authentication failed | The **cardholder** failed 3DS — by far the commonest failure in production (1007 of ~6000 logged replies), message "Authentication failed." with `PARES_STATUS=N`. Malformed M_INFO also lands here, but that is a development-time problem, not a live one. |
| `-20` | Expired transaction | In production this is an **abandoned payment page**, arriving 15-20 minutes after the order with `ACTION=2`. Same fifteen-minute window as the TIMESTAMP tolerance, so a stale timestamp lands here too — but the ordinary cause is a customer who walked away. |
| `-21` | The transaction has already been executed | May have succeeded — check status |
| `-22` | Transaction contains invalid authentication information | |
| `-23` | Invalid transaction context | |
| `-24` | The gateway holds no such transaction yet | Arrives minutes after the order, with empty placeholders. **Not** a failure — see above. |
| `-25` | Cancelled by the cardholder | Message: "Transaction Cancelled by Cardholder." |
| `-26` | Invalid action BIN | |
| `-27` | Invalid merchant name | |
| `-28` | Invalid incoming addendum | Check `AD.CUST_BOR_ORDER_ID` |
| `-29` | Invalid or duplicate authentication reference | |
| `-30` | Transaction declined as fraud | Never once seen in ~6000 logged replies. |
| `-31` | Transaction already in progress | **In progress.** Message: "Transaction in progress." |
| `-32` | Duplicate declined transaction | |
| `-33` | Cardholder authentication in progress | **In progress.** Message: "Client 3D-Secure authentication in progress." |
| `-40` | Client side transaction form in progress | **In progress.** The commonest reply of all after `00` — the customer has not submitted the form. Carries `CURRENCY=USD`, `AMOUNT=0.00` as placeholders. |

## Issuer codes

Two-digit codes are ISO-8583 responses passed through. `65` is worth handling
by name — it is a soft decline requiring strong customer authentication, which
on a merchant-initiated transaction means the credential needs re-establishing
with the customer present rather than retrying.

Seen in production, with `ACTION=2`: `05` declined, `51` insufficient funds,
`54` expired card, `57` not permitted, `61` exceeds limit, `62` restricted card.

Do not build a large table of these. Show the customer a generic "the card was
declined, please try another" and log the code.

## Disagreeing tables are a smell

One production system carried three separate RC tables — customer-facing,
admin, and CLI — which had drifted apart. `-19` was "authentication failed" in
one and "error formatting P_SIGN" in another (that is `-17`); `-30` was
"session expired" in one and "reversed transaction" in another (it is neither).

Keep one table. `IvoWmg\Borica\ResponseCode` is that table, and where the
specification and the gateway disagree it follows the gateway.

Also absent from the published list but real: `-48` "System error."


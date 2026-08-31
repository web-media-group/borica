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
| In progress | `-31`, `-33`, `-40` | Nothing. The customer is still at their bank. **Never cancel.** |
| Duplicate | `-21`, `-32` | Ask TRTYPE 90 first. Cancel only on a definite negative answer. |
| Everything else | | Definitive refusal. Safe to cancel and release. |

The classification matters more than the wording. A code meaning "still
running" answered as a decline cancels an order whose authorization then
succeeds — money held against nothing.

## Gateway codes

| RC | Meaning | Note |
|---|---|---|
| `00` | Approved | |
| `-1` | A mandatory request field is not filled in | |
| `-2` | CGI request validation failed | A field name is wrong |
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
| `-17` | Terminal denied access to e-Gateway | **Usually a P_SIGN failure**, not a permissions problem. First suspect: the RFU / MERCH_TOKEN_ID tail. |
| `-19` | Error in the authentication request, or authentication failed | Commonly non-Latin characters in M_INFO |
| `-20` | TIMESTAMP too far from gateway time | Tolerance is 15 minutes. Generate at submit, not at render. |
| `-21` | The transaction has already been executed | May have succeeded — check status |
| `-22` | Transaction contains invalid authentication information | |
| `-23` | Invalid transaction context | |
| `-24` | Transaction context data mismatch | Wrong currency, or older than 24h. **Not** an in-progress state. |
| `-25` | Confirmation cancelled by the cardholder | |
| `-26` | Invalid action BIN | |
| `-27` | Invalid merchant name | |
| `-28` | Invalid incoming addendum | Check `AD.CUST_BOR_ORDER_ID` |
| `-29` | Invalid or duplicate authentication reference | |
| `-30` | Transaction declined as fraud | |
| `-31` | Transaction already in progress | **In progress** |
| `-32` | Duplicate declined transaction | |
| `-33` | Cardholder authentication in progress | **In progress** |
| `-40` | Client side transaction form in progress | **In progress** |

## Issuer codes

Two-digit codes are ISO-8583 responses passed through. `65` is worth handling
by name — it is a soft decline requiring strong customer authentication, which
on a merchant-initiated transaction means the credential needs re-establishing
with the customer present rather than retrying.

Do not build a large table of these. Show the customer a generic "the card was
declined, please try another" and log the code.

## Disagreeing tables are a smell

One production system carried three separate RC tables — customer-facing,
admin, and CLI — which had drifted apart. `-19` was "authentication failed" in
one and "error formatting P_SIGN" in another (that is `-17`); `-30` was
"session expired" in one and "reversed transaction" in another (it is neither).

Keep one table. `IvoWmg\Borica\ResponseCode` is that table.

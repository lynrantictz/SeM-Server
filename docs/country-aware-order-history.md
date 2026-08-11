# Country-aware order-history phone contract

The backend uses a canonical E.164 phone string at application storage and
lookup boundaries. The stored representation includes the leading `+`, for
example `+255758483019` and `+254758483019`. The country calling code is part
of the identity; national digits alone are never compared across countries.

## Client endpoint

The current Client contract was verified read-only in
`services/verificationService.ts` and `app/(menu)/page.tsx`: the service calls
`apiGet("/phone/${phone}/verify", {})`, while the landing form strips spaces,
parentheses, and hyphens, accepts 9–15 digits, and navigates to
`/phone/{number}`. No Client or Business repository files are changed by this
backend change.

The existing Client path remains supported:

```http
GET /api/v1/phone/{phone}/verify
```

`{phone}` should be URL-safe canonical E.164. Both forms below are accepted
for compatibility with the current Client, which sends digits in the path:

```text
+255758483019
255758483019
```

The existing Tanzanian local form is also accepted and interpreted as TZ:

```text
0758483019 -> +255758483019
```

A number from another country must carry its country calling code. For
example, `+254758483019` (or `254758483019`) is a different customer from the
Tanzanian number above, even though the national digits match. If a future
Client sends a national number instead, it may pass `?country=TZ` (the legacy
`countryCode` query name is also accepted); canonical E.164 is preferred.

### Responses

- `200`: `data` is an array of matching orders and `message` is
  `Orders retrieved successfully`. The matching customer phone is omitted
  from this public history response.
- `404`: no customer/order matches the canonical number.
- `422`: the phone syntax or optional country value is invalid.

The backend does not return OTP values. OTP hashes are not included in order
responses and new phone flows do not log OTPs or phone numbers.

## Storage and migration

New customers store the canonical value in `customers.phone` and the additive
`customers.phone_e164` column. `phone_e164` is unique and is the authoritative
lookup key; it is hidden from API serialization. The original `phone` value is
retained during the additive migration for rollback and compatibility. The
migration backfills recognizable legacy Tanzanian values without deleting or
rewriting the original column. Unresolved legacy rows remain supported by the
TZ-only compatibility lookup and do not become matches for another country's
E.164 number.

Order creation, order phone changes, verification records, and history lookup
all pass through the same normalizer. The order's business country is used
when a local number is supplied during order creation or phone change.

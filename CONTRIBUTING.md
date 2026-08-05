# Contributing

## Setup

The plugin is plain PHP loaded by WordPress and the Vik framework — there is no
build step and no package manager. Everything CI runs, you can run locally:

```bash
# Lint every PHP file (matches the CI matrix, PHP 7.2–8.4)
find . -name '*.php' -exec php -l {} \;

# Verify the signing contract against the shared golden vectors
php tests/signature-check.php
```

Run both before opening a PR. The signature check must print
`ALL SIGNATURE CHECKS PASSED`.

## The one thing to get right: signature parity

The gateway's core job is reproducing a byte-exact, order-sensitive HMAC that the
PayLink server rebuilds independently:

```
signature = base64( hmac_sha256( implode('', ordered_values), hash_token ) )
```

There are two signatures, signed by opposite rules.

### Requests sign by opt-in

`buildSignedFields()` in `paylink.php` lists exactly the fields that are signed,
**in order**, skipping any that are empty:

```
first_name, last_name, email, order_title, order_amount,
[address, city, country, state,] currency,
[redirection_url, webhook_url, order_details]
```

`buildRecurringFields()` does the same for `/recurring/init`:

```
first_name, last_name, email, order_title, order_amount, currency,
cadence_interval, cadence_count, [total_cycles,] consent_text,
external_reference, [redirection_url, webhook_url]
```

These orders must match the PayLink **v2** endpoints, documented in the
[integration reference](https://pay.getpayin.com/docs/payment_integration/index.html),
and mirror the field registry in the official SDKs (e.g.
`paylink-php`'s `FieldOrders`). `token`, `signature`, `payment_mode`, and
`installments*` are sent but **not** signed. Nothing in PHP checks the order for
you — a wrong order compiles and lints cleanly but is rejected by the server.

### Webhooks verify by opt-out

`verifyCallbackSignature()` rebuilds `success, invoice_id, invoice_status,
message`, then appends `mandate_id, external_reference, subscription_status`
**only when the payload carries them** (detected with `array_key_exists`). Fields
the server sends but deliberately does not sign — `event`, `event_triggered_at`,
`timezone`, `auth_code`, `refund_amount`, `refund_currency` — must **not** be
added to the ordered list. Getting this backwards breaks verification for every
webhook carrying that field.

### Golden vectors

`tests/signature-check.php` drives the plugin's **real** `signValues()` and
`verifyCallbackSignature()` (through reflection, behind lightweight Joomla shims)
against fixed golden values shared with every other PayLink SDK
(`paylink-php`, `-js`, `-python`, `-dotnet`, `-java`). Adding or reordering a
signed field without updating a golden case leaves that field's position
untested. When you touch a signed field, update the vectors in the same change.

## Style

- Keep the code compatible with **PHP 7.2** (the CI lint matrix enforces this).
- Docblocks over inline comments — explain *why*, not *what*. No comments inside
  method bodies; a docblock above the member is the convention.
- Never round monetary values; only the wire amount is formatted to 2 decimals.
- Verify signatures with `hash_equals` and **fail closed**.
- Never log or echo the `hash_token`.

## Releasing

1. Bump the version so all three agree: the `Version:` header in
   `vikpaylink.php`, the `VIKPAYLINKVERSION` constant, and a new `## [x.y.z]`
   section in `CHANGELOG.md`.
2. Merge to `main`.
3. Push a tag `v<version>`. The release workflow runs CI, builds the distributable
   `vikpaylink` plugin ZIP, and publishes a GitHub Release with the changelog
   notes.

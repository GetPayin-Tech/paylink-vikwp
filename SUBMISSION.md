# VikWP / E4J submission packet

Everything E4J needs to review and list **GetPayIn for VikWP** as a payment
gateway on vikwp.com. This file is for the vendor review only — it is
export-ignored from the distributed plugin ZIP.

## What it is

A payment gateway plugin that lets Vik merchants collect payments through
**GetPayIn** (getpayin.com). It follows the `JPayment` framework exactly: an
abstract `AbstractPaylinkPayment extends JPayment` with a thin
`Vik{Component}PaylinkPayment` subclass per app. Payment name: `paylink`.

Card data never touches the merchant server — the payer is redirected to
GetPayIn's hosted, PCI-compliant checkout and the order is confirmed from a signed
webhook.

## Supported Vik apps

VikBooking, VikRentCar, VikRentItems, VikAppointments, VikRestaurants — one shared
gateway class, registered per app through the framework hooks
(`get_supported_payments_{app}`, `load_payment_gateway_{app}`, and for VikBooking
`payment_on_after_validation_vikbooking` to route the return URL).

## Payment capabilities

- Hosted checkout redirect with an idempotency key per order
- Capture, or Authorize and capture later
- Fixed installments (2–24)
- Recurring subscriptions (mandate via `/api/v2/integration/recurring/init`)
- Billing address + order details forwarded to prefill checkout
- Signed, fail-closed webhook confirmation (`hash_equals`)

## Requirements

- WordPress 5.6+, PHP 7.2+ (cURL, JSON)
- Any one of the five Vik apps
- A GetPayIn account with an integration (auth token + hash token)

## Security summary

- `hash_token` is a signing secret: server-side only, never sent to the browser,
  never logged. Used only in `hash_hmac`.
- Request signing and webhook verification use HMAC-SHA256 and are byte-exact with
  the official GetPayIn SDKs (verified by `tests/signature-check.php` against shared
  golden vectors — runs in CI on every push).
- Outbound calls require HTTPS on the integration's registered Origin domain.
- See `SECURITY.md` for the full policy and private disclosure channel.

## How to review / test

1. Install into a WordPress site with any Vik app.
2. Add the **GetPayIn** gateway under that app's *Payments* screen; enter test
   integration tokens.
3. Place an order → redirected to GetPayIn checkout → pay → webhook flips the order
   to paid.
4. Static verification without a live site:
   ```bash
   find . -name '*.php' -exec php -l {} \;
   php tests/signature-check.php
   ```

## Source, license, releases

- Repository: https://github.com/GetPayin-Tech/paylink-vikwp
- License: GPL-2.0-or-later (matches the WordPress/Vik framework it extends)
- Distribution: GitHub Release ZIP (`vikpaylink-<version>.zip`), folder-prefixed
  `vikpaylink/`, dev files excluded. Built and published automatically on `v*`
  tags by `.github/workflows/release.yml`.
- Latest: see https://github.com/GetPayin-Tech/paylink-vikwp/releases/latest

## Contact

- Support: support@getpayin.com
- Security: tech@getpayin.com

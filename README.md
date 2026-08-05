# GetPayIn for VikWP

![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-21759b)
![VikWP](https://img.shields.io/badge/VikWP-E4J-2a6fdb)
![PHP](https://img.shields.io/badge/PHP-7.2%2B-777bb4)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green)

GetPayIn payment gateway for the **VikWP** (E4J) plugins. It sends customers to
GetPayIn's hosted, PCI-compliant checkout (Apple Pay, Google Pay, Visa,
Mastercard), then confirms the booking from a signed webhook — so card data
never touches your site. Built on the GetPayIn **v2** integration API, it computes
the order-sensitive HMAC-SHA256 signatures for you and keeps them in lockstep
with the server.

Works with all five Vik plugins:

- **VikBooking**
- **VikRentCar**
- **VikRentItems**
- **VikAppointments**
- **VikRestaurants**

## Features

- Hosted checkout redirect (`beginTransaction` → GetPayIn) with idempotent invoice creation
- Capture now, or **authorize** and capture later from the dashboard
- Fixed **installments** (2–24) on the hosted checkout
- **Recurring subscriptions** — creates a mandate and charges the order total every cycle
- **Billing address** and order details forwarded to prefill the checkout
- Signed **webhook** verification, fail-closed (`hash_equals`)
- Per-request **return & webhook URLs** — no dashboard round-trip
- One shared gateway class across every Vik component

> **Card data never reaches your server.** Payment is completed on GetPayIn's
> hosted checkout. Your `hash_token` is a signing secret — keep it on the server
> and out of logs; the plugin never sends it to the browser.

## Requirements

- WordPress **5.6+**
- Any Vik plugin: VikBooking, VikRentCar, VikRentItems, VikAppointments, or VikRestaurants
- PHP **7.2+** with the cURL extension
- A GetPayIn account with an **integration** (auth token + hash token)

## Installation

1. Download the latest release ZIP (or `git clone` this repository into a folder
   named `vikpaylink`).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the ZIP, and
   **Install** then **Activate**.
3. The GetPayIn gateway now appears in the payment list of every installed Vik
   plugin.

## Configuration

In your Vik plugin, open **Payments** (e.g. VikBooking → *Payments*), add or edit
the **GetPayIn** gateway, and fill in:

| Field | Description |
| --- | --- |
| **Auth token** | Your integration's public token (`token` sent with the checkout request). |
| **Hash token** | Your integration's signing secret. Used server-side only to sign requests and verify webhooks — never exposed to the browser. |
| **Base URL** | GetPayIn host. Defaults to `https://pay.getpayin.com`. |
| **Payment action** | `Capture` (charge immediately) or `Authorize` (hold now, capture later). |
| **Installments** | `Yes` to offer fixed installments, with the **Number of installments** (2–24). Requires installments enabled on your account. |
| **Payment type** | `One-off` for a single payment, or `Recurring subscription` to create a mandate. |
| **Recurring interval / count / total cycles / consent text** | For recurring: the billing period (`month`/`week`/`day`/`year`), how many intervals between charges, an optional cap on the number of charges, and the consent statement shown to the payer. |

> Whether you use **test** or **live** credentials is decided by which tokens you
> enter above — there is no separate test switch. Your integration's registered
> **Origin** domain must match this site's domain, and the return/webhook URLs
> must be **HTTPS**.

## How it works

1. **Checkout** — When the customer confirms an order, `beginTransaction()` builds
   a signed **v2** request and POSTs it with an `Idempotency-Key` derived from the
   order. One-off payments go to `{base_url}/api/v2/integration/init`; recurring
   payments go to `{base_url}/api/v2/integration/recurring/init` (which also
   returns a `mandate_id`). The customer is redirected to the returned
   `checkout_url`.
2. **Payment** — The customer pays on GetPayIn's hosted checkout.
3. **Confirmation** — GetPayIn calls the plugin's webhook. The plugin verifies the
   body signature (fail-closed) and, on `success=1` with a paid/authorized status,
   marks the order paid via `JPaymentStatus::verified()` + `paid()`.

Because the webhook does not carry the order amount, the plugin persists the
order total to a short-lived transient at checkout and restores it when
confirming — no rounding is applied to the stored total.

## Signing

The plugin uses the same HMAC-SHA256 contract as every other GetPayIn SDK
(`paylink-php`, `paylink-js`, `paylink-python`, `paylink-dotnet`, `paylink-java`)
and the WooCommerce plugin:

```
signature = base64( hmac_sha256( implode('', ordered_values), hash_token ) )
```

- **Request (v2 init)** signs, in order: `first_name, last_name, email,
  order_title, order_amount, [address, city, country, state,] currency,
  [redirection_url, webhook_url, order_details]`. Optional fields are skipped when
  empty; `payment_mode` and `installments*` are sent but **not** signed.
- **Recurring init** signs, in order: `first_name, last_name, email, order_title,
  order_amount, currency, cadence_interval, cadence_count, [total_cycles,]
  consent_text, external_reference, [redirection_url, webhook_url]`.
- **Webhook** verifies, in order: `success, invoice_id, invoice_status, message`
  (plus `mandate_id, external_reference, subscription_status` when present).

## Development

The Joomla-style framework classes (`JPayment`, `JLoader`, `JFactory`, `JModel`,
`JRoute`, `JPaymentStatus`) are provided by a live VikWP install, so runtime
testing means installing the plugin into a WordPress site with a Vik plugin.

Static checks that run anywhere:

```bash
# Lint every PHP file
find . -name '*.php' -exec php -l {} \;

# Verify the signing contract against the shared golden vectors
php tests/signature-check.php
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) — in particular the note on signed-field
ordering, which is the one thing that must stay in lockstep with the server.

Security issues: see [SECURITY.md](SECURITY.md). Please do not open a public
issue for a vulnerability.

## License

[GPL-2.0-or-later](LICENSE).

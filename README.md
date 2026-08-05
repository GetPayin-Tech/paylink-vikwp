# @getpayin-tech/paylink-vikwp

![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-21759b)
![VikWP](https://img.shields.io/badge/VikWP-E4J-2a6fdb)
![PHP](https://img.shields.io/badge/PHP-7.2%2B-777bb4)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green)

PayLink payment gateway for the **VikWP** (E4J) plugins. It sends customers to
PayLink's hosted, PCI-compliant checkout (Apple Pay, Google Pay, Visa,
Mastercard), then confirms the booking from a signed webhook — so card data
never touches your site. Built on the PayLink **v2** integration API, it computes
the order-sensitive HMAC-SHA256 signatures for you and keeps them in lockstep
with the server.

Works with all five Vik plugins:

- **VikBooking**
- **VikRentCar**
- **VikRentItems**
- **VikAppointments**
- **VikRestaurants**

## Features

- Hosted checkout redirect (`beginTransaction` → PayLink) with idempotent invoice creation
- Capture now, or **authorize** and capture later from the dashboard
- Signed **webhook** verification, fail-closed (`hash_equals`)
- Per-request **return & webhook URLs** — no dashboard round-trip
- **Test mode** toggle for sandbox tokens
- One shared gateway class across every Vik component

> **Card data never reaches your server.** Payment is completed on PayLink's
> hosted checkout. Your `hash_token` is a signing secret — keep it on the server
> and out of logs; the plugin never sends it to the browser.

## Requirements

- WordPress **5.6+**
- Any Vik plugin: VikBooking, VikRentCar, VikRentItems, VikAppointments, or VikRestaurants
- PHP **7.2+** with the cURL extension
- A PayLink account with an **integration** (auth token + hash token)

## Installation

1. Download the latest release ZIP (or `git clone` this repository into a folder
   named `vikpaylink`).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the ZIP, and
   **Install** then **Activate**.
3. The PayLink gateway now appears in the payment list of every installed Vik
   plugin.

## Configuration

In your Vik plugin, open **Payments** (e.g. VikBooking → *Payments*), add or edit
the **PayLink** gateway, and fill in:

| Field | Description |
| --- | --- |
| **Auth token** | Your integration's public token (`token` sent with the checkout request). |
| **Hash token** | Your integration's signing secret. Used server-side only to sign requests and verify webhooks — never exposed to the browser. |
| **Base URL** | PayLink host. Defaults to `https://pay.getpayin.com`. |
| **Payment action** | `Capture` (charge immediately) or `Authorize` (hold now, capture later). |
| **Test mode** | Use `Yes` while integrating with sandbox tokens. |

> Your integration's registered **Origin** domain must match this site's domain,
> and the return/webhook URLs must be **HTTPS**.

## How it works

1. **Checkout** — When the customer confirms an order, `beginTransaction()` builds
   a signed **v2** init request and POSTs it to `{base_url}/api/v2/integration/init`
   with an `Idempotency-Key` derived from the order. The customer is redirected to
   the returned `checkout_url`.
2. **Payment** — The customer pays on PayLink's hosted checkout.
3. **Confirmation** — PayLink calls the plugin's webhook. The plugin verifies the
   body signature (fail-closed) and, on `success=1` with a paid/authorized status,
   marks the order paid via `JPaymentStatus::verified()` + `paid()`.

Because the webhook does not carry the order amount, the plugin persists the
order total to a short-lived transient at checkout and restores it when
confirming — no rounding is applied to the stored total.

## Signing

The plugin uses the same HMAC-SHA256 contract as every other PayLink SDK
(`paylink-php`, `paylink-js`, `paylink-python`, `paylink-dotnet`, `paylink-java`)
and the WooCommerce plugin:

```
signature = base64( hmac_sha256( implode('', ordered_values), hash_token ) )
```

- **Request (v2 init)** signs, in order: `first_name, last_name, email,
  order_title, order_amount, currency, redirection_url, webhook_url`.
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
```

## License

[GPL-2.0-or-later](LICENSE).

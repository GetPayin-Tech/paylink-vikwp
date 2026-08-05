# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.1]

### Fixed

- **API Base URL without a scheme** (e.g. `pay.getpayin.com`) no longer breaks checkout. cURL defaulted to `http://`, the server redirected to `https://`, and the un-followed redirect surfaced as "We could not start the GetPayIn checkout." The base URL now gets `https://` prepended when the scheme is missing.

### Added

- Diagnostic logging on checkout/webhook HTTP failures (cURL error, HTTP status + response body, or a missing `checkout_url`) via `error_log`, so failures are debuggable. The hash token and request body are never logged.
- The init/recurring response now also accepts a `url` key as an alias for `checkout_url`.

## [1.2.0]

### Changed

- Rebranded the plugin display name and all user-facing copy from "PayLink" to **GetPayIn** (plugin name, admin labels, checkout messages, README). Internal identifiers — the `paylink` gateway id, class names, text domain `vikpaylink`, and the repository — are unchanged, so existing configurations keep working.

## [1.1.1]

### Added

- `languages/vikpaylink.pot` translation template and completed plugin headers (`Plugin URI`, `Requires at least`, `Requires PHP`, `Update URI`) for marketplace/distribution readiness.

### Fixed

- The logo admin label passed an empty string through `__()`, which gettext reserves for the catalog header; it is now a plain empty string.

## [1.1.0]

### Added

- **Installments** — offer fixed 2–24 installments on the hosted checkout (sent unsigned, alongside `payment_mode`).
- **Recurring subscriptions** — a new `Recurring subscription` payment type creates a mandate via `/api/v2/integration/recurring/init` (cadence interval/count, optional total cycles, consent text) and confirms the setup charge; the returned `mandate_id` is captured from the webhook.
- **Billing address + order details** — `address`, `city`, `country`, `state`, and `order_details` are now forwarded (in the correct signed position, skipped when empty) to prefill the checkout.

### Changed

- Signed request builder now mirrors the full server field order shared with the official GetPayIn SDKs; new golden vectors and builder-order assertions cover it.

### Removed

- The no-op **Test mode** field. Test vs live is determined by which tokens you enter, so the toggle did nothing.

## [1.0.0]

First public release of the GetPayIn payment gateway for the VikWP (E4J) plugins.

### Added

- Support for all five Vik plugins: VikBooking, VikRentCar, VikRentItems, VikAppointments, VikRestaurants.
- Hosted GetPayIn checkout via the v2 integration API (`/api/v2/integration/init`) with an idempotency key per order.
- Capture or Authorize payment actions, plus a test-mode toggle for sandbox credentials.
- Fail-closed HMAC-SHA256 webhook verification (`hash_equals`); the `hash_token` signing secret never reaches the browser.
- Order total persisted to a transient so the amount-less webhook can confirm the exact total.
- CI (multi-version `php -l` lint + golden-vector signature self-check) and a release workflow that builds the distributable plugin ZIP.

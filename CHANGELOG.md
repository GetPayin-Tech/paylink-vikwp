# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0]

First public release of the PayLink payment gateway for the VikWP (E4J) plugins.

### Added

- Support for all five Vik plugins: VikBooking, VikRentCar, VikRentItems, VikAppointments, VikRestaurants.
- Hosted PayLink checkout via the v2 integration API (`/api/v2/integration/init`) with an idempotency key per order.
- Capture or Authorize payment actions, plus a test-mode toggle for sandbox credentials.
- Fail-closed HMAC-SHA256 webhook verification (`hash_equals`); the `hash_token` signing secret never reaches the browser.
- Order total persisted to a transient so the amount-less webhook can confirm the exact total.
- CI (multi-version `php -l` lint + golden-vector signature self-check) and a release workflow that builds the distributable plugin ZIP.

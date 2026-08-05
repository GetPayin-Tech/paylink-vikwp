# Security Policy

## Reporting a vulnerability

**Do not open a public issue for a security problem.**

Report it privately via [GitHub's private vulnerability reporting](https://github.com/GetPayin-Tech/paylink-vikwp/security/advisories/new), or email **tech@getpayin.com**.

Please include the plugin version, the Vik plugin and version, WordPress and PHP versions, and a reproduction if you have one. We aim to acknowledge within 3 business days.

## Supported versions

The latest release receives security fixes.

## Handling credentials

- **`hash_token` is a signing secret.** It is stored in your Vik plugin's payment configuration and is used only on the server — to sign requests and verify webhooks. Anyone holding it can forge requests and webhooks for your integration. The plugin never sends it to the browser and never logs it; keep it that way in any code you add.
- **`auth_token`** identifies the integration and is sent on every request. It is not secret, but it is not a substitute for the signing secret either.

## What this plugin does — and does not — do for you

- **Card data never touches your server.** Payment is completed on PayLink's hosted checkout; the plugin only redirects to it and confirms the signed webhook. Do not add code that collects raw card data in the Vik checkout.
- **Webhook replay protection.** PayLink webhook signatures carry no timestamp or nonce, so a valid payload stays valid forever. Signature verification proves authenticity, not freshness. The gateway confirms an order once and Vik ignores repeat confirmations; if you extend the callback handling, key your own idempotency on `invoice_id`.
- **Transport security.** Your integration's return and webhook URLs must be **HTTPS** on the registered Origin domain. The plugin does not downgrade or bypass TLS verification when calling PayLink.

<!--
Thanks for contributing to the PayLink VikWP plugin. Keep the description focused
on what changes and why. Delete any section that does not apply.
-->

## What & why

<!-- What does this PR change, and what problem does it solve? Link issues with "Closes #123". -->

## Type of change

- [ ] Bug fix (non-breaking change that fixes an issue)
- [ ] New feature (non-breaking change that adds functionality)
- [ ] Breaking change (fix or feature that changes existing behavior)
- [ ] Chore / tooling / docs (no runtime behavior change)

## Checklist

- [ ] `php -l` passes on every changed PHP file (kept compatible with PHP 7.2)
- [ ] `php tests/signature-check.php` prints `ALL SIGNATURE CHECKS PASSED`
- [ ] Docblocks updated; no comments added inside method bodies
- [ ] No `hash_token` or card data is logged, committed, or added to a fixture

## Signature parity

<!--
Only if this PR touches a signed request field or the webhook verification order.
The plugin must reproduce the server's byte-exact HMAC, so field order matters.
-->

- [ ] Not applicable — this PR does not touch signed request/webhook fields
- [ ] `buildSignedFields()` order matches the PayLink v2 `integration/init` endpoint
- [ ] `verifyCallbackSignature()` opt-out list is correct and the golden vectors pass

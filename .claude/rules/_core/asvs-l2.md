# OWASP ASVS v5.0 — Level 2 controls

These are the verification requirements that **must hold** for every change shipped from this repository. They are distilled from OWASP ASVS v5.0 for a payment module that runs inside merchants' Gambio shops. A change that cannot satisfy an applicable item does not get committed.

This module handles payment flows, merchant API credentials and shopper PII (names, addresses, emails sent to Mollie). Level 2 is the minimum. Card data never touches the shop, because it is tokenised by Mollie Components in the browser. Keep it that way.

The "Enforcement here" column maps each control to this codebase. PHP details are in `.claude/rules/languages/php/CLAUDE.md`.

---

## V1 — Encoding and Sanitization

| ID | Requirement | Enforcement here |
|---|---|---|
| 1.1 | Output encoding chosen for the **sink context** (HTML, attribute, JS, CSS, URL). | Smarty templates (`Admin/Html`, `Shop/Html`, `Shop/Templates`, `Shop/Themes`) rely on Gambio's default escaping. Never add `nofilter` / `unescape` for data that is not a trusted text phrase. PHP-built HTML uses `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`. |
| 1.2 | All untrusted input is treated as untrusted across **all** sinks (SQL, OS commands, templates, file paths). | `$_GET`, `$_POST`, `$_FILES`, `$_SESSION` values written from requests, webhook bodies and Mollie API payloads are untrusted until validated. |
| 1.3 | SQL uses parameter binding or ORM bindings. | Use the CodeIgniter query builder (`StaticGXCoreLoader::getDatabaseQueryBuilder()`) with bindings. Use `xtc_db_query` only where Gambio dictates it, and then only with `xtc_db_input()`-escaped or cast values. **Never** interpolate request data into SQL. |
| 1.4 | OS commands are never built from input. | No `exec`, `shell_exec`, `system`, `passthru` or backticks in module code. |

## V2 — Validation and Business Logic

| ID | Requirement | Enforcement here |
|---|---|---|
| 2.1 | Server-side allow-list validation for every untrusted field. | Cast IDs (`(int)`), validate amounts as decimal strings with two decimals, allow-list enum-like values (API method, status keys, payment codes). Browser JS validation is never enough. |
| 2.2 | Malformed payloads are rejected with a generic error; input is not echoed. | Admin JSON endpoints return a generic message and log the details. The webhook answers with an HTTP status only. |
| 2.3 | Business invariants are enforced server-side. | Amounts sent to Mollie come from the Gambio order (`OrderMapper`), never from the browser. Refund and capture amounts are checked against Mollie's `availableForRefund` / capturable amount. Order status transitions follow `OrderTransitionService`. |
| 2.4 | Numeric values are formatted locale-independently. | Money goes to Mollie as a `"%.2F"`-style string with a `.` separator and no thousands separator. |

## V3 — Web Frontend Security

| ID | Requirement | Enforcement here |
|---|---|---|
| 3.1 | Third-party scripts only from required, known origins. | The only external script is `https://js.mollie.com/v1/mollie.js` (Mollie Components). Adding another origin is a design decision and needs an ADR in `DESIGN.md`. |
| 3.2 | No secrets in browser code. | API keys never reach templates or JS. Only the profile ID and test-mode flag go to Mollie Components. |
| 3.3 | Response headers (CSP, HSTS, nosniff) | These are owned by the merchant's Gambio installation or server, not by this module. Don't break a strict CSP: no inline `eval`, no `new Function`. |

## V4 — API and Web Service

| ID | Requirement | Enforcement here |
|---|---|---|
| 4.1 | Every state-changing endpoint accepts only the intended HTTP method. | Admin actions that change state (refund, capture, config save, upload) accept POST only. |
| 4.2 | Admin endpoints are behind Gambio admin authentication. | Admin controllers extend `AdminHttpViewController`. Never expose admin functionality through a shop (`HttpViewController`) controller. |
| 4.3 | State-changing admin requests are protected against CSRF. | New or changed state-changing admin actions validate an anti-CSRF token. Confirm the mechanism for the supported GX versions before use; never assume it from memory. |
| 4.4 | Inbound webhooks are authenticated. | Mollie webhooks carry only an `id`. Authenticity comes from fetching the resource back from the Mollie API with the merchant's key, and the POST body is never trusted. The handler must stay idempotent, because Mollie retries on non-2xx responses. |
| 4.5 | Public (shop) endpoints that act on an order verify ownership. | Redirect and payment-link endpoints check that the order belongs to the session customer, or carry an unguessable signed token. |

## V5 — File Handling

| ID | Requirement | Enforcement here |
|---|---|---|
| 5.1 | Uploaded file type and size are validated server-side (extension allow-list + detected MIME type). | Applies to every `$_FILES` handler (for example the payment-method logo upload). |
| 5.2 | Uploaded files are never executable. | Allow image types only. Never keep a user-supplied extension that the web server could execute. |
| 5.3 | User-supplied file names are never used as filesystem paths. | Generate the target name server-side. `basename()` alone is not enough. |

## V6/V7 — Authentication and Session Management

| ID | Requirement | Enforcement here |
|---|---|---|
| 6.1 | This module adds no authentication of its own. | Shopper and admin authentication belong to Gambio. Use Gambio's session and customer context (`$_SESSION['customer_id']`); never build a parallel login. |
| 7.1 | Session data is minimal and single-use where possible. | Checkout transport keys (`mollie_issuer`, `mollie_card_token`, `mollie_customer_id`, `<code>_error`) are consumed once and cleared. Never store API keys or full payment payloads in the session. |

## V8 — Authorization

| ID | Requirement | Enforcement here |
|---|---|---|
| 8.1 | Authorization decisions are made server-side, on the resource being accessed. | A shop endpoint that takes `order_id` checks that the order belongs to the current customer. |
| 8.2 | No client-supplied identity or amount is trusted. | Customer ID comes from the session, and amounts from the order or Mollie. |
| 8.3 | Default deny. | Unknown actions or methods return an error, never a fall-through to a state change. |

## V11 — Cryptography

| ID | Requirement | Enforcement here |
|---|---|---|
| 11.1 | Tokens that protect a resource are unguessable. | Use `hash_hmac('sha256', …, <per-shop secret>)` or `random_bytes`-based tokens (with a polyfill below PHP 7). Never plain `md5`/`sha1` of IDs. |
| 11.2 | Token comparison is constant-time. | `hash_equals` (PHP ≥ 5.6; polyfill for the 5.4 floor). |
| 11.3 | Security randomness comes from a CSPRNG. | `random_bytes` / `openssl_random_pseudo_bytes`. Never `rand`, `mt_rand` or `uniqid` for security. |

## V12 — Secure Communication

| ID | Requirement | Enforcement here |
|---|---|---|
| 12.1 | Outbound calls use TLS with certificate verification on. | All Mollie calls go through the core `Proxy` + `CurlHttpClient` over `https://api.mollie.com/v2/`. Never disable `CURLOPT_SSL_VERIFYPEER` / `VERIFYHOST`. |
| 12.2 | Outbound destinations are fixed. | No request-controlled URLs for server-side HTTP calls (SSRF). The only outbound hosts are the Mollie API and the GitHub raw version file. |

## V13 — Configuration

| ID | Requirement | Enforcement here |
|---|---|---|
| 13.1 | No secrets in source control. | API keys live only in the merchant's database (`mollie_entity`). No keys in code, fixtures, tests, docs or commits, and that includes test keys. |
| 13.2 | Debug output is off by default. | Debug information (`Components/Debug`, `MollieSupportController`) is admin-only and never exposes API keys. |
| 13.3 | Dependencies are pinned and reviewed. | `composer.lock` + committed `vendor/` are the shipped truth. Update them together and review the diff of `vendor/mollie/integration-core`. |

## V14 — Data Protection

| ID | Requirement | Enforcement here |
|---|---|---|
| 14.1 | No secrets or PII in logs. | `FileLog('mollie')` entries never contain API keys, full request/response bodies with addresses or emails, or card tokens. Log IDs (`tr_…`, order ID) instead. |
| 14.2 | Only the data Mollie needs is sent. | `OrderMapper` sends what the Payments API requires (lines, amounts, addresses for the methods that need them). Don't add fields "just in case". |
| 14.3 | Stored credentials are protected. | Treat `mollie_entity` configuration rows as secret. Never render stored keys back in full in the admin UI or logs. |

---

## Verification checklist applied to every change

Before declaring a change done, the implementer (human or AI) walks through:

1. **Input**: every untrusted field (`$_GET`/`$_POST`/`$_FILES`/webhook/Mollie payload) is validated or cast server-side.
2. **Sinks**: SQL goes through the query builder with bindings, output is escaped for its context, and files are validated.
3. **AuthZ**: admin functionality is only in admin controllers, shop endpoints check order ownership, and state changes are POST with CSRF protection.
4. **Crypto / secrets**: there are no keys in source, tokens are HMAC/CSPRNG-based, and comparisons are constant-time.
5. **Logging**: there are no API keys, PII or card tokens in `FileLog`.
6. **Webhook**: the resource is fetched from Mollie, the handler is idempotent, and it returns the correct HTTP status for retries.
7. **Errors**: messages shown to shoppers and admins are generic, with no stack traces or raw API errors in shop output.

A change that cannot answer "yes" to every applicable item is not ready for review.

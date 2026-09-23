---
paths:
  - "GXModules/Mollie/Mollie/Admin/Javascripts/**/*.js"
  - "GXModules/Mollie/Mollie/Shop/Javascripts/**/*.js"
---

# JavaScript Security Rules (browser code of the Gambio module)

These rules cover the module's **browser-only** JavaScript. There is no server-side JS and no build
step: the files are served as-is by Gambio.
- `Admin/Javascripts/`: the admin config page, the order dashboard and the refund/capture/payment-link
  popups, using `Mollie.HttpService` (`mollie-http.js`) for AJAX.
- `Shop/Javascripts/`: checkout (Mollie Components card fields, issuer selection, Apple Pay
  detection).

## Prerequisites

- `.claude/rules/_core/owasp-2025.md`: core web security
- `.claude/rules/_core/asvs-l2.md`: the verification standard and checklist for this repo
- `.claude/rules/languages/php/CLAUDE.md`: the server side of every endpoint these scripts call

---

## DOM Output

### Rule: Put data into the DOM as text; never as HTML

**Level**: `strict`

**When**: Rendering anything that comes from an AJAX response, a `data-*` attribute, a form field or
the URL. That includes notification messages and descriptions, order numbers, amounts, Mollie error
texts and customer data.

**Do**:
```javascript
let cell = document.createElement('div');
cell.classList.add(columnClass);
cell.textContent = notification.message;          // text, never parsed as HTML
row.append(cell);
```

**Don't**:
```javascript
cell.innerHTML = notification.message;            // stored XSS if the text contains markup
element.insertAdjacentHTML('beforeend', response.description);
```

**Why**: Messages can carry parameters that come from the Mollie API or the order. In the admin
area, an XSS runs with the shop administrator's session.

`innerHTML` is allowed only for static markup written in the script itself, or for server-rendered
translations that PHP has already escaped. Say so in a comment where it's used.

**Refs**: OWASP A05:2025, CWE-79, ASVS 1.1

---

## Code Execution

### Rule: No dynamic code

**Level**: `strict`

**When**: Always.

**Don't**: `eval`, `new Function`, `setTimeout` / `setInterval` with a string argument, `document.write`,
or `javascript:` URLs.

**Why**: These break a strict Content-Security-Policy in the merchant's shop and turn any injected
string into code.

**Refs**: OWASP A05:2025, CWE-95

---

## AJAX Requests (admin)

### Rule: Use `Mollie.HttpService` with server-provided, same-origin URLs

**Level**: `strict`

**When**: Calling admin endpoints (`admin.php?do=Mollie…`).

**Do**:
- Call through `Mollie.HttpService` (`get` / `post`) so every request is handled the same way.
- Take endpoint URLs from what the server rendered (`data-*` attributes or template variables).
  Never build them from `location` or user input.
- Send state changes (refund, capture, config, upload) as POST, and include the anti-CSRF token the
  server provides for that request.
- Handle a non-JSON or empty error response (catch the rejected promise), and show a generic message.

**Don't**:
- Put API keys, tokens or other secrets in query strings.
- Call cross-origin URLs from admin scripts.

**Refs**: OWASP A01:2025, CWE-352, ASVS 4.1, 4.3

---

## Checkout: Card Data and Mollie Components

### Rule: Card data stays inside Mollie Components; submit only the token

**Level**: `strict`

**When**: Changing `mollie-components.js`, `mollie-credit-card.js` or the checkout templates that load them.

**Do**:
- Load `mollie.js` only from `https://js.mollie.com/v1/mollie.js`.
- Initialize with only the profile ID, locale and test-mode flag, read from the wrapper's `data-*`
  attributes.
- Get the card token with `mollie.createToken()` and put only that token into the checkout form.

**Don't**:
- Read, copy, log, store or send card numbers, CVC or expiry. The shop must never see them.
- Add other third-party scripts or origins to checkout without a documented decision in `DESIGN.md`.
- Keep the token longer than the form submit (no `localStorage` / `sessionStorage` / cookies).

**Why**: This keeps card data out of the merchant's shop and PCI scope.

**Refs**: OWASP A04:2025, A08:2025, CWE-311, ASVS 14.2

---

## Secrets and Personal Data

### Rule: No secrets in browser code; no payment or customer data in browser storage or the console

**Level**: `strict`

**When**: Always.

**Do**: Only non-secret configuration reaches the browser (profile ID, test-mode flag, locale, endpoint
URLs).

**Don't**:
- Put API keys (live or test) in JS, `data-*` attributes or inline scripts.
- Store order, customer or payment data in `localStorage` / `sessionStorage`.
- Use `console.log` with tokens, responses containing customer data, or keys. Remove debug logging
  before committing.

**Refs**: OWASP A04:2025, A09:2025, CWE-200, CWE-532, ASVS 13.1, 14.1

---

## Navigation

### Rule: Redirect only to server-provided URLs

**Level**: `warning`

**When**: Setting `location`, `location.href` or `window.open`, or following a URL from a response.

**Do**: Use `location.reload()`, or a URL that the server rendered or returned for this action.

**Don't**: Redirect to a value taken from the query string, the hash or a form field (open redirect).

**Refs**: OWASP A01:2025, CWE-601

---

## Conventions That Protect Correctness

### Rule: Respect the global namespaces and the existing syntax level

**Level**: `warning`

**When**: Adding or changing a script.

**Do**:
- Admin scripts attach to `window.Mollie` (`var Mollie = window.Mollie || {};`).
- Shop scripts use `window.MollieComponents` and **never** define `window.Mollie`, because on checkout
  `Mollie` is the global function from `mollie.js`.
- Keep to the syntax already used in these files (ES2017: `let`/`const`, arrow functions, `async`/`await`,
  destructuring). There is no transpiler and no bundler, and ES modules (`import`/`export`) are not
  used. Anything newer is a deliberate decision.

**Don't**: Pull in libraries from a CDN, or add a build step, without a decision in the plan.

**Why**: Overwriting `window.Mollie` on checkout breaks Mollie Components. Syntax the merchant's
shoppers' browsers can't parse breaks the whole checkout script.

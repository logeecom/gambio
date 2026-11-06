# Research: repeated order submit (double click) finishes the checkout (CS-8633)

> **Retroactive.** Written on 2026-11-06 after the implementation on branch `feature/CS-8633_5`
> (commit `87c6fe3`). Ticket: *double-clicking "Order with obligation to pay" completes the checkout
> and sends the order confirmation email before the customer has completed the payment. This
> behaviour originates from Gambio's checkout flow.*

## What existed

- Mollie payment modules set `tmpOrders = true` (`includes/modules/payment/mollie/mollie.php`), so
  Gambio creates the order first and then calls `payment_action()`, which redirects to Mollie.
- `mollie::before_process()` returned `false` and did nothing ("Not used").
- After the return from Mollie, `MollieRedirectProvider` sent the shopper to `checkout_process.php`
  for the current checkout order, where Gambio finishes the checkout.

## Gambio behaviour (GX 4.9.4.1, `system/classes/checkout/CheckoutProcessProcess.inc.php`)

- `proceed()` (`:138`) calls `_initOrderData()`, which calls `$payment->before_process()` (`:261`)
  **before** it checks `$_SESSION['tmp_oID']`.
- If `tmp_oID` is not set (`:147`), it saves the order, sets `tmp_oID` (`_setOrderId()`, `:929`) and
  calls `payment_action()`, which redirects to Mollie and exits.
- If `tmp_oID` **is** set, it skips order creation and, because `tmp_order` is false, sends the order
  mail, calls `after_process()`, `reset()` and redirects to `checkout_success.php` (`:161–175`).
  This is the path the return from Mollie relies on.
- A double click sends two POSTs to `checkout_process.php`. PHP file sessions serialize the two
  requests. The first creates the order and redirects to Mollie; the browser discards that response.
  The second sees `tmp_oID` and **finishes the checkout**: confirmation mail and success page with an
  unpaid order. The same happens when the shopper uses Back on the Mollie page (confirmation page
  restored from the browser cache, no server request) and submits again.
- `tmp_oID` is cleared by `checkout_payment.php` (`CheckoutPaymentContentControl::proceed()`),
  `checkout_confirmation.php` (`CheckoutConfirmationContentControl`) and the cart controller.

## Where the fix can go

| Option | Notes |
|---|---|
| Gambio core / theme (disable the button) | Not ours; JS-only protection does not cover Back + resubmit or scripted clients |
| `mollie::before_process()` | Called by Gambio on every submit before the `tmp_oID` check; module-local; `xtc_redirect` exits before Gambio finishes |
| Overload of `CheckoutProcessProcess` | Heavier, ties the module to Gambio internals across GX versions |

## What the change affects

`includes/modules/payment/mollie/mollie.php` (`before_process()`), `MollieRedirectProvider`
(returned-order mark, continuation URL).

## Risks found

- The legitimate return must still finish the checkout exactly once.
- If the order has no Mollie payment (creation failed), there is no checkout link to continue to.
- The fix relies on per-session request serialization (PHP file sessions lock).

## Questions for the spec

1. Where does a repeated submit send the shopper?
2. How is the legitimate return distinguished from a repeated submit?
3. Does the change go to `4.1-4.4`?

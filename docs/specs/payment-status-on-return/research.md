# Research: payment status check on customer return (PIGAM-229)

> **Retroactive.** Written on 2026-11-06 after the implementation on branch `feature/CS-8633_5`
> (commits `9f5180e`, `536a954`). Ticket question: *"Check if synchronous payment status changes make
> sense."* It records the state on `4.5-5.x` (release 3.1.5).

## What existed

- **Return endpoint.** Mollie sends the shopper to `shop.php?do=MollieCheckoutRedirect&order_id=N`
  (`MollieCheckoutRedirectController` → `MollieRedirectProvider::getRedirectUrl()`). The old provider:
  - read the order and compared its status with the mapped `mollie_canceled`;
  - if canceled: ran `OrderResetService::resetOrder()` and sent the shopper to
    `checkout_payment.php?payment_error=<module>` (cart not empty) or `account_history_info.php`;
  - otherwise: `checkout_process.php` when the session cart had contents, else `checkout_success.php`.
- **The return usually arrives before the webhook.** The order is still in "Created (Mollie)" when the
  shopper comes back, so a canceled, failed or expired payment was treated as successful:
  `checkout_process.php` finished the checkout (confirmation mail, success page). The order was
  canceled only later by the webhook.
- **The endpoint did not check ownership.** Any visitor could call it with any `order_id`; for a
  canceled order that ran `resetOrder()` again (restock and `DeleteHistory` report on every call).
- **Webhook** (`MollieWebhookController`) called `WebHookTransformer::handle()` without any
  serialization. Two webhooks for the same payment, or a webhook and another status change, could run
  concurrently.
- **Stock reset.** `OrderTransitionService::expireOrder()` reset the order; `cancelOrder()` and
  `failOrder()` only set the status. Reset on cancel happened only through
  the return endpoint, so a shopper who never returned left the stock reduced.
- **Comments** on status changes were translated with the session language of whoever triggered the
  change (`MollieTranslator` with `$_SESSION['languages_id']`), which is undefined in the webhook.

## Relevant Gambio behaviour (GX 4.9.4.1)

- `CheckoutProcessProcess::proceed()` (`system/classes/checkout/CheckoutProcessProcess.inc.php:138`)
  finishes the checkout (mail, `after_process()`, `reset()`, success page) whenever
  `$_SESSION['tmp_oID']` is an int. `payment_action()` runs only when the order is created in that request.
- `checkout_payment.php` (`CheckoutPaymentContentControl::proceed()`) and `checkout_confirmation.php`
  unset `tmp_oID`, so a new submit after going back to payment selection creates a new order.
- The merchant's cancel status is `gm_configuration/GM_ORDER_STATUS_CANCEL_ID` (default 99).

## Core behaviour

- `WebHookTransformer::handle($rawRequest)` fetches the payment from Mollie, fires
  `PaymentChangedWebHookEvent` and stores the new payload in `OrderReference`.
  `StatusWebHookHandler` calls a transition only when the status differs from the stored one, so
  running the same handler twice for one status change is a no-op the second time.
- `WebHookTransformer::handle()` starts `WebHookContext` itself, so status changes made from it do not
  echo back to Mollie as integration events.

## What the change affects

`MollieRedirectProvider`, `MollieWebhookController`, `OrderTransitionService`, `MollieTranslator`,
`GambioLanguageRepository`, new `DatabaseLock`, `Admin/TextPhrases/*` (new message
`mollie.payment.redirect.payment_not_completed.description`).

## Risks found

- Running the status update from the return and from the webhook at the same time can apply the same
  transition twice (double restock).
- An extra Mollie API call on every return (`PRINCIPLES.md` §5.12): bounded to orders that are still
  unconfirmed.
- If the Mollie call fails on return, the shopper still has to land somewhere sensible.

## Questions for the spec

1. Should the return synchronize the status from Mollie, or wait for the webhook?
2. Where does a shopper go for canceled/failed/expired, and with what message?
3. Should restock happen in the webhook transitions, and how is double restock prevented?
4. Should the return endpoint check order ownership?
5. Does the change go to `4.1-4.4`?

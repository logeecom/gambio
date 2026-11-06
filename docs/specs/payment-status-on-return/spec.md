# Spec: payment status check on customer return (PIGAM-229)

> **Retroactive.** Reconstructed on 2026-11-06 from the implementation (`9f5180e`, `536a954`,
> release `40724fe`). Decisions marked *unconfirmed* were read from the code, not from a recorded
> interview. **Status: awaiting developer confirmation.**

## Problem and goal

The shopper usually returns from Mollie before the webhook arrives. The module then decided by the
order status alone, so a canceled, failed or expired payment finished the checkout with a
confirmation mail. Goal: when the shopper returns, apply the current Mollie payment status first, and
send the shopper to the right page for it.

Answer to the ticket question: **yes, a synchronous status change on return makes sense**, provided it
uses the same code path as the webhook and is serialized with it.

## Scope

- Synchronize the payment status on return for orders that are still unconfirmed (created or pending).
- Canceled, failed and expired payments lead back to payment selection with a clear message.
- Restock exactly once on cancel, fail and expiry, no matter which path cancels first.
- Serialize the return and the webhook per order.
- Check that the returned order belongs to the session customer.
- Write status comments in the shop's default language.

## Out of scope

- Payment links (`MolliePaymentLinkRedirect`) and admin status changes.
- The product-stock defect in `RestockService` (pre-existing, found during testing; see § Known
  pre-existing defect, tracked in a separate ticket).

## Acceptance criteria

1. Final state `canceled`, `failed` or `expired`: the shopper lands on
   `checkout_payment.php?payment_error=<module>` with "The payment was not completed. Please choose a
   payment method and try again."; the order is in the canceled status right away; no confirmation mail.
2. Final state `paid` or `pending`: the status is already applied when the success page shows; the
   checkout finishes once with one confirmation mail.
3. A webhook that arrives after the return does not add a second status history entry and does not
   restock again.
4. A visitor without a session customer is sent to `account_history.php` (login); a customer who
   calls it for someone else's order is sent to `account_history.php`. Neither changes the order.
5. Webhook cancel and fail restock the order (combination stock observed restored once).

## Decisions

| Decision | Why |
|---|---|
| Synchronize by running `WebHookTransformer::handle(id=tr_…)` on return | Same code path as the webhook: same transitions, same payload update, and the core skips unchanged statuses, so the later webhook is a no-op |
| Only for orders in mapped `mollie_created` / `mollie_pending` | Avoids a Mollie API call for orders that are already final (`PRINCIPLES.md` §5.12) |
| Per-order MySQL named lock (`DatabaseLock::forOrder`) around both the return sync (5 s) and the webhook (10 s) | Serializes the two paths without schema changes; a webhook that can't get the lock answers 422 so Mollie retries |
| If the sync fails, log a warning and continue with the order status as it is | The shopper is not blocked by a Mollie outage; the webhook corrects the status later (fail-open, *unconfirmed*) |
| Restock moves into `OrderTransitionService::mapToCancelledStatus()` and is skipped when the order is already in the Mollie or Gambio cancel status | One place, idempotent; covers webhook cancel/fail (which did not restock before) and the merchant's own cancel |
| Ownership check (`$_SESSION['customer_id']` equals the order's customer) before anything else | ASVS 4.5/8.1; removes the unauthenticated reset |
| The checkout is finished only for the order of the current checkout (`$_SESSION['tmp_oID']`) | A return for an old order must not finish the current checkout |
| Comments in `DEFAULT_LANGUAGE` | Comments are written from the webhook too, where there is no session language |

## Port to `4.1-4.4`

Ported in release 2.1.5 on branch `feature/CS-8633` (`f89a9cc`, `8e03d83`, `52ea8b4`), with the
branch specifics from `CLAUDE.md` § Supported branches. Not yet verified on a GX 4.1–4.4 shop (`tasks.md` PSR-8).

## Open decisions

- Fail-open on a failed sync (decision above) needs explicit confirmation.

## Open risks

- A shopper who returns in another browser (bank app on mobile) has no session: they go to the login
  page, the checkout is not finished and no confirmation mail is sent; the webhook sets the status.
- `OrderResetService` reports the order to `DeleteHistoryService`; this now happens for every cancel
  and fail, not only for expiry.
- `MollieTranslator` falls back to `$_SESSION['languages_id']` when the default language is not
  found, which is undefined in the webhook (notice).

## Known pre-existing defect: product stock is not restored for products with combinations

Found while testing this change on 2026-11-06 (GX 4.9.4.1). **Not introduced by this change:**
`Components/OrderReset/RestockService.php` is unchanged since `2083f36` ("Reset order on expiry
webhook") and behaves the same on `4.5-5.x` and `4.1-4.4`. This change makes it visible more often,
because cancel and fail now restock too (before, only expiry and a canceled return did).
It is tracked in a separate ticket and is out of scope here.

- **Symptom:** after a canceled, failed or expired payment, the combination stock is restored
  correctly, but the product stock (`products.products_quantity`) stays reduced. Observed on product 5
  ("T-Shirt Gambio", size M, `use_properties_combis_quantity = 0`): after orders 400226–400228
  `combi_quantity` was back at 77 each time, while `products_quantity` stayed reduced by the ordered
  quantity (971 → 965 over the three orders).
- **Cause:** the restock condition does not mirror the condition Gambio uses when it reduces the stock.
  - Gambio, `CheckoutProcessProcess::update_stock()`: reduces product stock when
    `STOCK_LIMITED == 'true'` and (`$type === PropertiesCombisAdminControl::DEFAULT_GLOBAL` or
    `ProductStockService::isChangeProductStock($type, $combiId, $filename)`).
  - `RestockService::restock()`: increases product stock only when `isChangeProductStock(...)`.
    For a combination in `DEFAULT_GLOBAL` mode with `ATTRIBUTE_STOCK_CHECK = true`, that returns
    `false`, so the stock Gambio took is never given back.
  - From reading the code (not observed): `RestockService` does not check `STOCK_LIMITED`, so with
    `STOCK_LIMITED = false` and `STOCK_CHECK = true` it can increase stock that Gambio never reduced.
- **Likely fix:** use the same condition as Gambio (`STOCK_LIMITED` guard plus the `DEFAULT_GLOBAL`
  branch) in `RestockService::restock()`, after checking `update_stock()` on GX 4.5 and 5.0 (and 4.1–4.4
  for the other branch), and re-verify combination, attribute, specials and `products_ordered` restock.

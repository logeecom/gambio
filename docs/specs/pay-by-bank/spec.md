# Spec: Pay by Bank payment method

> **Retroactive.** Reconstructed on 2026-11-06 from the implementation (`41b2753`, `273f11c`,
> `536a954`, release `40724fe`). Decisions marked *unconfirmed* were read from the code, not from a
> recorded interview. **Status: awaiting developer confirmation.**

## Problem and goal

Merchants want to offer Mollie Pay by Bank. Its payments can be `pending` for some time after the
shopper approves them, and the merchant must not ship before the funds arrive. Goal: offer Pay by
Bank in checkout like any other Mollie method, and make a pending payment visible on the order.

## Scope

- New payment module `mollie_paybybank` (Payments API only), 6 languages, logo.
- New order status "Pending (Mollie)" with its own entry in the status mapping.
- New optional core transition for `pending` payments (core 1.3.12), implemented by the wrapper.
- Creation of the new status for shops that are already installed.

## Out of scope

- Pending handling for any method other than Pay by Bank.
- Changes to refund or capture (Pay by Bank is not capturable).
- Porting to `4.1-4.4` (*unconfirmed*, see open decisions).

## Acceptance criteria

1. Pay by Bank appears in checkout when it is enabled in the Mollie profile and its module is active.
2. Paying with final state `paid` leads to the success page, status "Paid (Mollie)", confirmation mail.
3. Final state `pending` leads to the success page, status "Pending (Mollie)" with a comment that
   the order is on hold, confirmation mail. A later `paid` moves it to "Paid (Mollie)".
4. Fresh install creates five statuses, including "Pending (Mollie)", with a name in every shop language.
5. An existing install gets "Pending (Mollie)" created once, without changing the merchant's saved
   mapping. Until the status exists, pending orders keep "Created (Mollie)".
6. Orders of other methods never move to "Pending (Mollie)".

## Decisions

| Decision | Why |
|---|---|
| Module follows the Wero pattern (hidden `API_METHOD`, `_getDefaultApi()` = Payments API) | Checkout is Payments API only since 3.1.0; same shape as the latest method |
| Pending is put on hold only for `mollie_paybybank` (`OrderTransitionService::$onHoldPaymentModules`) | Other methods are pending only briefly; moving their orders would add noise and status mail risk |
| Pending only replaces "Created (Mollie)" | A later transition (paid, canceled) is never overwritten by a late pending |
| New status "Pending (Mollie)" in `$defaultStatuses`, mappable in the admin | Same model as the other Mollie statuses; the merchant can map it to an existing status |
| Pending transition is an **optional** core interface (`PendingOrderTransitionService`), checked with `instanceof` in `StatusWebHookHandler` | The core is shared; integrations that don't implement it are unaffected |
| Core change is released as core 1.3.12 and vendored unchanged | `PRINCIPLES.md` §5.8: never edit `vendor/` in place. This also removes the earlier in-place vendor edits, which are all contained in 1.3.12 |
| Existing installs: `OrderStatusUpdate` creates missing statuses at runtime (payment module constructor and Module Center page), under a MySQL named lock | There is no migration framework; the same runtime-upsert pattern is used by `PaymentMethodUpdate` |
| Saved mappings without `mollie_pending` fall back to the default, and to `mollie_created` while the status does not exist | Backward compatibility for in-place upgrades (`PRINCIPLES.md` §5.10) |
| Status names are written for every shop language, English as fallback | Fixes install writing other languages to language ID 1 |

## Open decisions

- Port to `4.1-4.4`: not decided. The `4.1-4.4` vendored core is still labelled 1.3.10 and differs
  from both `4.5-5.x` and the core repo, so a port starts with a core update on that branch.

## Open risks

- Core 1.3.12 also makes Riverty always use manual capture (`b65e196` in the core). It ships with
  release 3.1.6 but is not in the README release notes.
- `OrderStatusUpdate` runs in the `mollie` constructor on storefront pages. When a status is missing
  and requests are concurrent, one request can wait up to 10 s for the lock (one-time).

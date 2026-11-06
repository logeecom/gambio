# Research: Pay by Bank payment method

> **Retroactive.** Written on 2026-11-06 after the implementation on branch `feature/CS-8633_5`
> (commits `41b2753`, `273f11c`, `536a954`). It records the state the change started from, as found
> on `4.5-5.x` (release 3.1.5).

## What existed

- **Payment methods** are one Gambio payment module per Mollie method:
  `includes/modules/payment/mollie_<id>.php` extending `mollie` (`includes/modules/payment/mollie/mollie.php`).
  A method also needs `lang/<language>/modules/payment/mollie_<id>.php` in 6 languages and a logo in
  `images/icons/payment/` (`CLAUDE.md` § Local rules). The latest precedent is Wero (`2931b5a`):
  module class, 6 language files, logo, and a constant in the core's `PaymentMethods`.
- **Methods that only work with the Payments API** hide the `API_METHOD` config key in `keys()` and
  return `PaymentMethodConfig::API_METHOD_PAYMENT` from `_getDefaultApi()` (`mollie_wero.php`,
  `mollie_twint.php`, `mollie_riverty.php`, …).
- **Order statuses.** `MollieModuleCenterModule::$defaultStatuses` created four statuses on install
  (Created/Paid/Authorized/Refunded (Mollie)) and stored their IDs in
  `gx_configurations` `configuration/MOLLIE_DEFAULT_ORDER_STATUSES_MAP`. Install wrote names only for
  the `en` and `de` language IDs; any other language fell back to language ID `1`
  (`_createOrderStatus()` / `_getLanguageId()`, removed in `41b2753`).
  There was no path that creates a status for a shop that is already installed.
- **Status mapping.** `OrderStatusMapper::getStatusMap()` merges the saved `StatusMapping`
  (`mollie_entity`) with the default map. The admin form `mollie_order_statuses.html` has one select
  per mapped key.
- **Webhook dispatch.** The core's `StatusWebHookHandler` mapped `paid`, `expired`, `canceled`,
  `authorized` and `failed` to `OrderTransitionService` methods. A payment that became `pending` was
  ignored, so the order stayed in "Created (Mollie)".
- **Vendored core.** `vendor/mollie/integration-core` was labelled 1.3.10 in `composer.lock` while
  `composer.json` required 1.3.11, and several files had been edited in place in the Gambio repo
  (`Http/DTO/Payment.php`, `Http/DTO/Payments/`, `Http/Proxy.php`, `Http/OrgToken/ProxyDataProvider.php`,
  `PaymentMethod/Model/PaymentMethodConfig.php`, `PaymentMethodService.php`, `PaymentMethods.php`).
  Those edits did not exist in the core repository (`integrations/mollie/core`).

## Mollie behaviour that matters

- Pay by Bank (`paybybank`) is a Payments API method with a hosted checkout.
- After the shopper approves the payment at the bank, the payment can stay `pending` until the
  funds arrive, and only then becomes `paid`. It can also end `failed`, `canceled` or `expired`.
- Mollie test mode offers all final states for Pay by Bank: open, pending, paid, failed, canceled,
  expired.

## What the change affects

| Area | File(s) |
|---|---|
| New payment module | `includes/modules/payment/mollie_paybybank.php` |
| Languages (6) | `lang/{dutch,english,french,german,italian,spanish}/modules/payment/mollie_paybybank.php` |
| Logo | `images/icons/payment/mollie_paybybank.png` |
| New order status | `MollieModuleCenterModule::$defaultStatuses`, `GambioStatusRepository::createStatus()` |
| Status for existing installs | new `Components/Update/v3_1_6/OrderStatusUpdate.php` |
| Status mapping and admin UI | `OrderStatusMapper`, `Admin/Html/ConfigSections/mollie_order_statuses.html`, `Admin/TextPhrases/*` |
| Pending transition | core `StatusWebHookHandler`, new core interface `PendingOrderTransitionService`, wrapper `OrderTransitionService::pendingOrder()` |
| Core | release 1.3.12 in the core repository, then a vendor update |

## Risks found

- A merchant who upgrades in place has a saved `StatusMapping` without `mollie_pending`, and a
  default map without it. Pending must not break or change their mapping.
- Creating a status at runtime can race between concurrent requests and create it twice.
- A `pending` transition for other methods (for example credit card, briefly pending) must not
  move those orders.
- The core is shared. A new transition must not affect other integrations that don't implement it.

## Questions for the spec

1. Which methods put the order on hold while the payment is pending? Only Pay by Bank, or all?
2. Is a new order status needed, or is "Created (Mollie)" enough?
3. How do existing installs get the new status (install only, or an update path)?
4. Does the change go to `4.1-4.4`?

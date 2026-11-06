# Tasks: Pay by Bank payment method

> **Retroactive.** The implementation tasks are done; their commits are listed. Open items are at the end.

| ID | Goal | Files | Verification | blockedBy | Wave | Status / commit |
|---|---|---|---|---|---|---|
| PBB-1 | Core 1.3.12: `PayByBank` constant, optional `PendingOrderTransitionService`, `pending` dispatch | core repo (`9500931`, `b65e196`, `295ea85`, merged `84a4bc9`) | Core release tagged; integrations without the interface unaffected | — | 1 | Done (core repo) |
| PBB-2 | Add the payment module, languages and logo; `pending` status with creation for existing installs and admin mapping | see `plan.md` §8 | `php -l`; checkout lists Pay by Bank; status created on an installed shop | — | 1 | Done, `41b2753` |
| PBB-3 | Vendor core 1.3.12 | `composer.json`, `composer.lock`, `vendor/` | Vendor equals core `84a4bc9` | PBB-1 | 2 | Done, `273f11c` |
| PBB-4 | Move the pending transition from the wrapper webhook handler to the core interface | `OrderTransitionService`, `BootstrapComponent` (registration removed), `WebHook/PendingPaymentWebHookHandler.php` (removed) | `pending` scenario sets "Pending (Mollie)" | PBB-3 | 3 | Done, `536a954` |
| PBB-5 | Release 3.1.6 | `composer.json`, `vendor/composer/installed.php`, `README.md` | Version check reads 3.1.6 | PBB-4 | 4 | Done, `40724fe` |
| PBB-6 | Manual verification on GX 4.9.4.1 | — | `plan.md` §10 | PBB-5 | 5 | Done 2026-11-06 |
| PBB-10 | Port to `4.1-4.4`: module, core 1.3.12, release 2.1.5 (branch `feature/CS-8633`) | same as PBB-2..5, with `Update/v2_1_5/OrderStatusUpdate.php` and `GambioConfigRepository::updateValue()` | `php -l`; vendor equals core `84a4bc9` | PBB-5 | 6 | Done, `4e5dbfa`, `1a126df`, `52ea8b4` |

## Open

| ID | Goal | Status |
|---|---|---|
| PBB-7 | Developer confirms `spec.md` (retroactive) | Open |
| PBB-8 | Run `/code-review` → `/simplify` → `/security-review` on the feature diff | Open |
| PBB-9 | Add "Riverty always uses manual capture" to the 3.1.6 and 2.1.5 README notes | Open (decision) |
| PBB-11 | Manual verification on GX 4.1–4.4 (`plan.md` §10) | Open |

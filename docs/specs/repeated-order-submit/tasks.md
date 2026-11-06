# Tasks: repeated order submit (double click) finishes the checkout (CS-8633)

> **Retroactive.** The implementation tasks are done; their commits are listed. Open items are at the end.

| ID | Goal | Files | Verification | blockedBy | Wave | Status / commit |
|---|---|---|---|---|---|---|
| ROS-1 | Guard `before_process()` with a single-use returned mark; continue the existing payment on any other submit | `mollie.php`, `MollieRedirectProvider` | `php -l`; double-submit and resubmit scenarios | PSR-1 (`docs/specs/payment-status-on-return/tasks.md`) | 1 | Done, `87c6fe3` |
| ROS-2 | README release note for 3.1.6 | `README.md` | — | ROS-1 | 2 | Done, `e7b740b` |
| ROS-3 | Manual verification on GX 4.9.4.1 | — | `plan.md` §10 | ROS-2 | 3 | Done 2026-11-06 |
| ROS-8 | Port to `4.1-4.4`, release 2.1.5 (branch `feature/CS-8633`) | `mollie.php`, `MollieRedirectProvider`, `README.md` | `php -l` | ROS-2 | 4 | Done, `39ad983` |

## Open

| ID | Goal | Status |
|---|---|---|
| ROS-4 | Developer confirms `spec.md` | Open |
| ROS-5 | Decide on the payment-creation-failure case (continue to `checkout_payment.php` with an error when there is no `OrderReference`) | Open (decision) |
| ROS-6 | Verify on GX 4.5 and 5.0 that `before_process()` runs before the `tmp_oID` check | Open |
| ROS-7 | Run `/code-review` → `/simplify` → `/security-review` on the feature diff | Open |
| ROS-9 | Manual verification on GX 4.1–4.4, including that `before_process()` runs before the `tmp_oID` check (`plan.md` §10) | Open |

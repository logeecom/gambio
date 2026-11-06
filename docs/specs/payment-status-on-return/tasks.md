# Tasks: payment status check on customer return (PIGAM-229)

> **Retroactive.** The implementation tasks are done; their commits are listed. Open items are at the end.

| ID | Goal | Files | Verification | blockedBy | Wave | Status / commit |
|---|---|---|---|---|---|---|
| PSR-1 | Sync the status on return through `WebHookTransformer` under a per-order lock; lock the webhook; owner check; idempotent cancel with restock; new "not completed" message | `MollieRedirectProvider`, `MollieWebhookController`, `OrderTransitionService`, `DatabaseLock`, `Admin/TextPhrases/*` | `php -l`; canceled/paid return scenarios | — | 1 | Done, `9f5180e` |
| PSR-2 | Review fixes: re-read the order only after an attempted sync, log a lock timeout, catch `Throwable`, also treat the Gambio cancel status as canceled, comments in the default language | `MollieRedirectProvider`, `OrderTransitionService`, `DatabaseLock`, `MollieWebhookController`, `MollieTranslator`, `GambioLanguageRepository` | `php -l`; all return scenarios | PSR-1 | 2 | Done, `536a954` |
| PSR-3 | Release 3.1.6 (shared with Pay by Bank) | `composer.json`, `installed.php`, `README.md` | — | PSR-2 | 3 | Done, `40724fe` |
| PSR-4 | Manual verification on GX 4.9.4.1 | — | `plan.md` §10 | PSR-3 | 4 | Done 2026-11-06 |

## Open

| ID | Goal | Status |
|---|---|---|
| PSR-5 | Developer confirms `spec.md`, including fail-open on a failed sync | Open |
| PSR-6 | Run `/code-review` → `/simplify` → `/security-review` on the feature diff | Open |
| PSR-7 | Decide and, if yes, plan the port to `4.1-4.4` | Open (decision) |
| — | Pre-existing `RestockService` product-stock defect (`spec.md` § Known pre-existing defect) | Out of scope; separate ticket |

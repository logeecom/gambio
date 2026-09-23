# CLAUDE.md

## What this repository is

The Mollie payment module for **Gambio**. Two branches are actively maintained: `4.5-5.x`
(GX 4.5.x–5.0.x, the primary branch) and `4.1-4.4` (GX 4.1.x–4.4.x). All other branches and GX
versions are out of scope; branch specifics are in § Supported branches. It exposes Mollie payment methods as Gambio payment modules, plus a
surcharge order-total module. The classic Gambio module structure (`includes/modules/` +
`GXModules/`) is used on purpose, so one codebase supports multiple Gambio versions. It also adds
a Module Center module for API-key connection and status mapping, and an admin order dashboard
with refund, capture and payment-link actions. Payment state comes back through a webhook and a checkout redirect controller. Business logic
lives in the vendored shared core `mollie/integration-core`. This repo is the Gambio **wrapper** that
implements the core's integration interfaces. See `DESIGN.md`.

## Supported branches

The same AI resources (`CLAUDE.md`, `PRINCIPLES.md`, `DESIGN.md`, `LEARNINGS.md`, `.claude/`) are kept
**identical on both branches**. Everything branch-specific is in this table. Architecture, layout,
commands, rules and the development flow are shared. Changes are made on `4.5-5.x` first and then
ported (`PRINCIPLES.md` §7).

| | `4.5-5.x` | `4.1-4.4` |
|---|---|---|
| Gambio versions | GX 4.5.x–5.0.x | GX 4.1.x–4.4.x |
| Module version line | `3.x.y` (`composer.json` `version`) | `2.x.y` |
| Local branch suffix (existing convention) | `_4X` / `_5X` (for example `bugfix/CS-8452_5X`) | `_44` (for example `feature/PIGAM-218_44`) |
| `ConfigurationService::VERSION_CHECK_URL` | `…/mollie/gambio/4.5-5.x/…/composer.json` | `…/mollie/gambio/4.1-4.4/…/composer.json` |
| `.github/workflows/codeql.yml` branches | `4.5-5.x` | `4.1-4.4` |
| Storefront/admin languages | de, en, fr, nl, it, es | de, en, fr, nl (no `lang/italian`, `lang/spanish`, `Admin/TextPhrases/{italian,spanish}`) |
| `gx_configurations` inserts | `key`, `value`, `type`, `sort_order` | additionally `legacy_group_id = 6` (`mollie::install`, `ot_mollie::install`, `PaymentMethodUpdate`, `mollie_issuer_providable`) |
| Constant lookups | `mollie::_getConstantValue()` (`defined()` guard) | direct `@constant(...)`; there is no `_getConstantValue()` |
| Config-field updater | `PaymentMethodUpdate::upsertConfigFields()`, which also updates the SORT_ORDER type; `GambioConfigRepository::update/select` | `PaymentMethodUpdate::addConfigFields()`, with no type update and no `update/select` |
| Overload signatures | `Mollie_AdminApplicationTopExtender::proceed()` | `proceed(): void` |
| Vendored core (`vendor/mollie/integration-core`) | lock `1.3.10` | lock `1.3.10`; the files differ from `4.5-5.x` (`ProxyDataProvider`, `PaymentMethodConfig`). **Never copy `vendor/` between branches.** |

## Architecture map

- `includes/modules/payment/mollie/mollie.php` — base Gambio payment module (`selection`, `payment_action`, config keys)
- `includes/modules/payment/mollie_*.php` — one class per Mollie method; `mollie_issuer_providable.php` for issuer methods
- `includes/modules/order_total/ot_mollie.php` — surcharge order total
- `lang/<lang>/modules/...` — language constants (6 languages)
- `GXModules/Mollie/Mollie/autoload.php` — vendor autoload + `BootstrapComponent::init()`; every entry point `require_once`s it
- `GXModules/Mollie/Mollie/Components/` — wrapper code, PSR-4 `Mollie\Gambio\` → `Components`
  - `BootstrapComponent.php` — composition root (service, repository and processor registration)
  - `APIProcessor/` — `ProcessorFactory` → `PaymentProcessor` (payment creation)
  - `Mappers/` — Gambio order → Mollie DTOs; status mapping
  - `Services/Business/` — core interface implementations (Configuration, PaymentMethod, OrderTransition, …)
  - `OrderReset/` — restock, reactivate and reship after a failed or expired payment
  - `Entity/Repository/` — `BaseRepository` (`mollie_entity` ORM), `Gambio*Repository` (raw Gambio tables)
  - `MollieRedirect/`, `PaymentLink/`, `CustomFields/`, `Authorization/`, `Utility/`, `Debug/`, `Update/`
- `GXModules/Mollie/Mollie/Shop/Classes/Controller/` — `shop.php?do=MollieWebhook|MollieCheckoutRedirect|MolliePaymentLinkRedirect`
- `GXModules/Mollie/Mollie/Admin/Classes/` — Module Center module (install/uninstall) and admin controllers (`admin.php?do=…`)
- `GXModules/Mollie/Mollie/Admin/Overloads/` — Gambio overload extenders (order dashboard, order write events, admin top, cancel, config box)
- `GXModules/Mollie/Mollie/vendor/` — committed Composer output incl. `mollie/integration-core`. **Never edit in place.**
- `.github/workflows/codeql.yml` — CodeQL (JavaScript only)

## Where the instructions live

| Scope | File | What it covers |
|---|---|---|
| **Working agreement + development flow** | `PRINCIPLES.md` | Plan first with explicit design options, the spec-driven flow (research → spec → plan → implement → verify → review → hand-off), git rules, documentation discipline, engineering principles. **Mandatory for every change; read it first.** |
| **Architecture** | `DESIGN.md` | The living architecture record: layers, components, domain model, key flows (with ownership labels), module map, patterns, boundaries, constraints. Updated in the same pass as any architectural change. |
| **Plan standard** | `.claude/plan-template.md` | The required sections of every `plan.md`, including Architecture impact and Risks & emphasis. |
| **Security** | `.claude/rules/` | See "MANDATORY sources of truth" below. |
| **Feature artifacts** | `docs/specs/<feature>/` | `research.md`, `spec.md`, `plan.md`, `tasks.md` for each feature. This is the source for resuming work. |
| **Corrections** | `LEARNINGS.md` | Append-only record of corrections and traps. Read it before planning. Format in `PRINCIPLES.md` §4.4. |

## Commands

There is no test suite, linter, static analysis or build step in this repo, and no composer scripts.

```bash
# dependencies (run in GXModules/Mollie/Mollie/; needs SSH access to git@github.com:mollie/orocore.git; vendor/ is committed)
composer install

# PHP syntax check (not configured in repo — ad hoc)
find includes GXModules/Mollie/Mollie/Components GXModules/Mollie/Mollie/Admin GXModules/Mollie/Mollie/Shop \
  -name '*.php' -print0 | xargs -0 -n1 php -l
```

Verification is manual on a Gambio install within the branch's GX range (4.5–5.0 or 4.1–4.4):
1. Copy the repo contents into the shop root.
2. Go to Toolbox » Cache and clear the module, output and text cache.
3. Go to Modules » Module Center » Mollie » Install.

## Local rules

- **Describe the Gambio module structure neutrally.** `includes/modules/*` + `GXModules/` is the classic
  Gambio structure, kept on purpose for multi-version support. Never call it "legacy" in docs, specs,
  plans or comments.
- **PHP syntax floor is 5.4** (`composer.json` `"php": ">=5.4"`), and the code must also run on PHP 8.x
  (8.2+). Do not use `??`, scalar or return type declarations, or other post-5.4 syntax unless the
  floor is deliberately raised. Guard every constant lookup with `defined()`.
- **The PSR-4 path must match the namespace** (`Mollie\Gambio\X\Y` → `Components/X/Y.php`). No classmap
  covers mismatches.
- **Overload classes** (`Admin/Overloads/<OverloadedClass>/Mollie_<X>`) extend `Mollie_<X>_parent` and
  must call `parent::…` to keep Gambio's `MainFactory` chain working.
- **Resolve dependencies through `BootstrapComponent::init()` + `ServiceRegister`.** Register services in
  `BootstrapComponent` and resolve them with `ServiceRegister::getService(X::CLASS_NAME)`. Avoid
  instantiating services directly with `new`.
- **Direct SQL uses the CodeIgniter query builder** (`StaticGXCoreLoader::getDatabaseQueryBuilder()`). Avoid
  `xtc_db_query` wherever possible, unless Gambio itself dictates it.
- **Payment codes** are `mollie_<mollieMethodId>`. A new method needs a payment module class, `lang/*` constants
  for all 6 languages, and a logo in `images/icons/payment/`.
- **Checkout is Payments API only** (since 3.1.0). Orders API paths exist only for older `OrderReference`
  records created with the Orders API.
- **Release: bump versions in lockstep.**
  - Update `GXModules/Mollie/Mollie/composer.json` `version` in the branch's own line (`3.x.y` / `2.x.y`). The
    in-shop version check reads this file from the branch's `VERSION_CHECK_URL`.
  - Add the README release notes.
- **Porting**: changes land on `4.5-5.x` first. The spec decides whether they also go to `4.1-4.4`, and
  if so they are ported per `PRINCIPLES.md` §7, keeping the branch specifics from § Supported branches.

## MANDATORY sources of truth

The security rules live in `.claude/rules/`. Two of them are copied from
[TikiTribe/claude-secure-coding-rules](https://github.com/TikiTribe/claude-secure-coding-rules):
`_core/owasp-2025.md` and `cicd/github-actions/CLAUDE.md`. They are MIT-licensed; which files, and
what was changed, is in `.claude/rules/THIRD-PARTY-NOTICES.md`. The other files (`_core/asvs-l2.md`,
`languages/php/CLAUDE.md`, `languages/javascript/CLAUDE.md`) were written for this repository and
cover the module's real surface: PHP in Gambio, and browser-only JavaScript.

### Always applied (every change, every file)

| Rule set | File | Why mandatory |
|---|---|---|
| **OWASP ASVS v5.0 — Level 2** | `.claude/rules/_core/asvs-l2.md` | The verification standard this module is held to (payments, merchant credentials, shopper PII). Walk the checklist at the bottom before declaring any work done. |
| **OWASP Top 10 (2025)** | `.claude/rules/_core/owasp-2025.md` | Cross-cutting controls for the most common web vulnerabilities. |

### Applied per stack (path-scoped, loaded automatically when a matching file is read)

| Stack | Rule file | Loads when reading |
|---|---|---|
| PHP (Gambio module) | `.claude/rules/languages/php/CLAUDE.md` | `includes/**/*.php`, `lang/**/*.php`, `GXModules/Mollie/Mollie/{*.php,Components,Admin,Shop}/**` (not `vendor/`) |
| JavaScript | `.claude/rules/languages/javascript/CLAUDE.md` | `GXModules/Mollie/Mollie/{Admin,Shop}/Javascripts/**/*.js` |
| CI/CD | `.claude/rules/cicd/github-actions/CLAUDE.md` | `.github/workflows/**` |

The per-stack files are required reading for a change in their scope, even before they load
automatically.

### Enforcement levels (used inside the rule files)

- `strict`: refuse to generate violating code. No "but it works" exceptions.
- `warning`: generate the code, but flag the issue and propose an alternative in the same response.
- `advisory`: mention it as a best practice.

### Conflicts

If a rule conflicts with an explicit developer instruction, follow the developer but **name the
rule being overridden**.

## Tooling

- **Built-in review skills**: `/code-review`, `/simplify` and `/security-review` are the mandatory
  review sequence (`PRINCIPLES.md` §2.6).
- **Plugin**: `.claude/settings.json` enables `security-guidance@claude-plugins-official` from the
  built-in official marketplace. It warns about risky patterns while editing. No other marketplace is
  required.
- **Task tools**: `.claude/settings.json` sets `CLAUDE_CODE_ENABLE_TODO_TOOLS=1`, so
  `TaskCreate`/`TaskUpdate`/`TaskList` are available for the task graph (`PRINCIPLES.md` §2.3). The
  tools bind at session start, so restart Claude Code after changing this setting.
- **Plan-section gate**: `.claude/hooks/plan-gate.sh`, registered in `.claude/settings.json`,
  denies `ExitPlanMode` and blocks a written `docs/specs/<feature>/plan.md` when the plan is missing
  one of the headings **Architecture impact**, **Risks & emphasis** or **Verification**. It reads the
  hook JSON with `python3`, or `php` as a fallback. If neither is installed, it lets the plan through
  and prints a warning.
- **Permissions**: `git push`, `git commit --amend`, `git rebase` and `git reset --hard` always ask
  first. Reading `.env*`, keys and `auth.json` is denied.

## Development flow (mandatory)

Every task goes through the spec-driven flow in `PRINCIPLES.md` §2, in every session and without being
asked. Skipping a phase gate is a defect, not a shortcut.

| Phase | What happens | Artifact / gate |
|---|---|---|
| Research | Read-only exploration of the affected code, `DESIGN.md`, `LEARNINGS.md` and the Mollie API reference; parallel read-only subagents for broad sweeps | `docs/specs/<feature>/research.md` |
| Spec | Interview the developer (AskUserQuestion) until no decision is open, including branch/GX scope, migration and delegation | `docs/specs/<feature>/spec.md`, confirmed by the developer |
| Plan | Per `.claude/plan-template.md`: architecture impact, Risks & emphasis, task graph | `plan.md` + `tasks.md`; **the developer approves before any code** |
| Implement | Local `feature/<feature>` branch; subagents in parallel waves where allowed; **one short commit per task**, author unchanged | Each task's verification green before its commit |
| Verify | `php -l` on changed files, PHP 5.4 floor, the plan's manual verification, the ASVS checklist, docs in sync | Real output reported |
| Review | `/code-review` → `/simplify` → `/security-review`; every finding verified against the code | All findings resolved or answered |
| Hand-off | Summary of commits, files and verification to the developer | **No push**: the developer decides when to push |
| Resume | Continue from `docs/specs/<feature>/` + `git log`, never from memory | `tasks.md` updated first |

### Git rules (summary of `PRINCIPLES.md` §3)

- Everything stays in the local repository. Never push, open PRs or touch remotes unless the developer
  asks for that specific action.
- Never change the commit author. Use the configured git user, with no `--author`.
- Commit messages are one short line in the existing style (`Add Wero payment method`,
  `Guard constant lookups with defined()`, `Release 3.1.5`), with no body, no `Co-Authored-By` and no
  other trailers.

## When you need to write code

1. Re-read `PRINCIPLES.md`, `LEARNINGS.md`, the relevant `DESIGN.md` sections and the applicable rule
   files (`asvs-l2.md` + the stack file). Don't work from memory.
2. Follow the development flow above. For a non-trivial change, research, spec and plan come
   before code.
3. Generate code that satisfies the `strict` rules unconditionally. If you cannot, stop and ask.
4. Verify before claiming done, and report the real results.
5. Run the review sequence and resolve the findings.
6. Update the docs and instruction files in the same pass (`PRINCIPLES.md` §4), and append any
   correction to `LEARNINGS.md`.

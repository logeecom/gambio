# Plan authoring standard (`docs/specs/<feature>/plan.md`)

Every task plan in this repo MUST contain the sections below, in this order. A plan must be detailed
enough to implement without re-deriving decisions, and reviewable on its own. Keep prose tight and
prefer tables, diagrams and signatures over paragraphs.

The plan and its implementation comply with `PRINCIPLES.md`, `CLAUDE.md` § Local rules, `DESIGN.md`
and the security rules in `.claude/rules/`. **Reference those documents; never restate them in the plan.**

## Scope tiers

- **Light plan**: for a small change (docs-only, config tweak, rename, single-file bugfix). Include §1,
  §2 (if any decisions), §3, a one-line §4 and §10–§11. Skip the rest.
- **Full plan**: everything else (new payment method, new flow, schema/config change, multi-file
  change). Include all sections, in order.

## Required sections

1. **Title + summary.** What the task delivers, the spec it realizes
   (`docs/specs/<feature>/spec.md`), its prerequisites, and explicitly what is **out of scope**. Name
   the target branch (`4.5-5.x`), and whether a port to `4.1-4.4` is required. If it is, §9 includes
   the port tasks, and §10 the verification on GX 4.1–4.4 (`PRINCIPLES.md` §7).

2. **Decisions.** The locked choices, each with a one-line *why* (plus the cost when it isn't obvious).
   Say where it reuses an existing mechanism (a core service, `BootstrapComponent` registration, an
   existing mapper or repository) instead of inventing one. Name any security rule it satisfies or
   overrides.

3. **Architecture impact.** Either **architectural** (new or changed component, flow, boundary,
   persistence, external call, or a change to the core) or **business-case-only**. If architectural,
   list the `DESIGN.md` sections and diagrams this plan updates in the same pass. Use the ownership
   labels `«gambio»` / `«mollie-gambio»` / `«mollie-core»` / `«mollie-api»`.

4. **Risks & emphasis** *(mandatory)*. State the risk and its mitigation for each of these:
   - **Security**: input validation, output escaping, SQL via the query builder, admin/shop
     authorization, CSRF, webhook authenticity and idempotency, secrets/PII in logs
     (`.claude/rules/_core/asvs-l2.md` checklist).
   - **Money and state correctness**: amounts, taxes, rounding, currency, and order-status transitions.
   - **Backward compatibility**: existing `gx_configurations` keys, `mollie_entity` data, order
     statuses and references for merchants upgrading in place.
   - **Compatibility**: the PHP 5.4 syntax floor and PHP 8.x runtime, and the GX 4.5–5.0 APIs used (and, when
     ported, the `4.1-4.4` specifics in `CLAUDE.md` § Supported branches).
   - **Performance**: DB queries per page load, Mollie API calls per request or webhook, and
     complexity over order lines.
   - **Alignment**: `DESIGN.md` layering (entry point → wrapper → core) and `ServiceRegister` DI.

5. **Model / flow diagrams** (full plan). The entities touched (with ownership labels) and the runtime
   flows added or changed, as Mermaid `classDiagram` / `sequenceDiagram` in the `DESIGN.md` style.

6. **Persistence changes** (when it touches data). New or changed `gx_configurations` keys,
   `mollie_entity` types and indexes, Gambio tables written, and how existing shops are migrated
   (install/update path).

7. **Class outline.** For each changed or new class: its path, namespace, responsibility, the
   signatures of its key members, and its `BootstrapComponent` registration if it has one.

8. **Files.** The exact files to create or edit. For repeated patterns (for example a new payment
   method: module class, `lang/*` in 6 languages, logo), describe the pattern once and list
   representative files.

9. **Tasks.** The task graph that goes into `tasks.md`: ID, goal, files, verification, `blockedBy`,
   and wave. Each task is one commit (`PRINCIPLES.md` §3).

10. **Verification.** The exact commands (`php -l` on the changed files) and the **manual verification
    script**: steps on a Gambio 4.5–5.0 install (and on 4.1–4.4 when ported) with the expected result for each step (checkout,
    webhook, admin action, as applicable), plus the review sequence `/code-review` → `/simplify` →
    `/security-review`. Name the instruction/doc files this task updates, or state "none".

11. **Done.** The definition of done: gates green, the observable outcome confirmed, and
    `DESIGN.md` / instruction files in sync.

## Clear-explanation rule

Every diagram and class outline comes with a short plain-language explanation of *why* it looks that
way and which requirement it serves. A reviewer should understand the intent, not just the shape.

# BitLicense — Agent Instructions

## Purpose and scope

BitLicense is a small Italian-language application for an MSP: select a company, see its computers and expiries, click a computer, edit and save. The operational domain is Company + Computer; each computer has an optional GravityZone reference and directly editable current commercial dates. GravityZone's company-level technical expiry and each computer's local commercial expiry are different facts and must never be conflated.

The approved specification and GitHub issues define the product. Issue #26 supersedes the former four-entity architecture and operational workflow. The customer has approved #17 only for development verification and UI/UX refinement of the current Licenze flow (Company + Computer). Issues #10–#15 remain suspended until the customer reviews and approves their scope against the new model. Issue #16 (hosting/deployment) remains closed and must not be reopened. Do not create release or deployment issues.

Implement only the approved GitHub issue. Do not add a CRM, contracts, reseller purchasing costs, accounting, invoicing, quote generation, bulk initial commercial-data imports, license inventory, automated endpoint removal, a general task manager, or unrequested features. Existing company contact fields do not justify a new CRM workflow. Renewal proposals and communications are suspended pending scope review.

## Required development approach

- Use Ponytail: read and activate the upstream skill from https://github.com/DietrichGebert/ponytail (prefer the full mode where supported) before implementing or reviewing changes. Verify that it is available to the current agent, including each sub-agent. Do not claim to have used it if it is unavailable; report the blocker.
- Read this entire file before application development. Read the complete relevant source files before modifying them. If output is truncated, inspect remaining portions in chunks; never infer that omitted text or ... is part of the file.
- Understand the actual data flow and existing implementation before proposing changes. Do not invent API capabilities, fields, behavior, configuration or test results. Verify the GravityZone data contract against available POC output and official documentation; reuse the user's POC when available and appropriate.
- Prefer existing project code, framework APIs and installed components. Follow YAGNI and DRY without introducing speculative abstraction. Write simple, clear, idiomatic, maintainable Laravel/PHP code. Avoid needless services, repositories, helpers, wrapper layers, new dependencies and premature generalization.
- Prioritize correctness and maintainability over code-golf. Do not sacrifice validation, authorization, security, error handling or accessibility to reduce lines of code. Do not deliberately introduce technical debt without a documented reason.
- Comment non-obvious code only, in English. Avoid explanatory noise and boilerplate comments. User-facing labels, messages and validation text are in Italian.
- Keep migrations and models aligned to Company + Computer. Remove obsolete resources, models, relations, services, migrations and tests; do not retain Endpoint + Workstation + Coverage + CoverageAssignment, adapters or parallel implementations for compatibility. Preserve minimal automatic commercial snapshots when a computer is changed, replaced or reconciled. External synchronization must never overwrite locally owned commercial data.

## Supervision and autonomous progress

- Act as the architecture, engineering and product supervisor, accountable for the final result. Make ordinary technical decisions independently. Ask the customer only for required credentials, unresolved functional decisions or mandatory approvals.
- Use real sub-agents when delegation provides a concrete benefit for a bounded implementation, independent analysis, review or testing. Handle small changes directly when that is simpler. If sub-agents are unavailable, work directly and do not simulate delegation.
- Before delegation, read the issue and acceptance criteria, verify dependencies and repository state, define the task and allowed files/branch, and provide project instructions and the Ponytail skill. Require each sub-agent to read both. Avoid concurrent edits to shared files or work based on dependencies not yet integrated.
- Personally inspect delegated work against the original requirement, security, simplicity, readability, integration and appropriate tests. Remove unnecessary complexity. Fix small problems directly or request targeted corrections. A sub-agent's completion report is not proof of completion.
- Sequence approved work by actual dependencies and reuse opportunities. Work on #17 only within its revised approved scope; do not resume #10–#15 without customer scope review. Do not create new issues for work that belongs in existing ones; report out-of-scope problems without implementing them.
- Keep a clear record of completed work, current branch/issue, checks, blockers, required approvals and the next step so work can resume reliably. Stop affected work at approval, credential or indispensable functional-decision gates; never bypass them.

## Technology and interface

- Laravel 13, Filament 5 (including its native auth/components), MySQL/MariaDB and Laravel Scheduler/Cron where needed. Any future approved communications must use Laravel Mail. Verify actual framework/runtime requirements rather than assuming compatibility.
- No frontend build pipeline, including during development: do not require Node.js, npm, Vite, React or npm run build. Use prebuilt Filament panel assets and simple static CSS only when strictly necessary; do not introduce a compiled Tailwind custom theme.
- The architectural target is ordinary PHP hosting (CloudPanel, cPanel or equivalent), but execution and verification in this phase are development-only. Do not require Docker, Redis, permanent queue workers, Supervisor or shell services beyond ordinary PHP CLI/Cron. If a requirement cannot be met on the target, identify it before implementation.
- Dark-first, carefully designed, responsive UI in Italian, with Europe/Rome timezone and Italian date/time formats. Do not ship a generic collection of autogenerated CRUD tables without reviewing the actual operational workflow. Reuse Filament components and create specific pages only when needed; show screenshots or a local preview for UI-related work before approval.
- Keep one operational **Licenze** page at `/admin`: searchable company selector, one computer table with company technical expiry in its header, and one edit modal per computer. Manual creation, explicit reconciliation and replacement belong to that same page. Do not recreate separate company, endpoint, workstation, coverage, expiry or dashboard modules. Use Italian dark-first desktop/mobile previews before approval. Bulk date editing was optional in #26 and is not implemented.
- Users are created and disabled manually by administrators. Use only administrator/operator roles with server-side authorization on sensitive pages and actions, including direct URLs. Reuse native authentication, session, password, CSRF and rate-limit protections. No self-registration, bespoke invitation subsystem or complex permissions framework.

## GravityZone: strictly read-only

- Use one GravityZone API key for the installation, configured only through a protected administrator page and encrypted at rest. Never expose saved credentials in logs, browser responses or commits.
- Do not implement the capability to create, modify, delete, uninstall, assign or otherwise mutate GravityZone data or devices, including unused methods, operational examples or administrative endpoints.
- Do not implement a generic JSON-RPC client that can invoke arbitrary methods. Implement only an explicit allowlist of necessary read methods whose semantics, parameters and payloads have been verified against official documentation and available POC output.
- JSON-RPC reads may use HTTP POST: determine read-only safety from the API method's semantics, not the HTTP verb.
- Use Laravel's HTTP facilities; handle authentication, pagination, timeouts, network errors and HTTP 429/Retry-After without leaking secrets or personal data. Do not invent unavailable fields or assume that each endpoint's commercial product is exposed by the API.
- Synchronization must be repeatable without duplicate companies or computers. Use stable GravityZone IDs, never hostname as persistent identity. Update remote fields only, preserving local company ownership, dates, product, price, references, notes and history. Track synchronization status/errors and preserve records when remote computers disappear or reappear. A remote company move updates the remote company reference and is shown for verification; it does not move local commercial data.
- New remote computers appear automatically in Licenze with missing commercial dates, shown as “Da completare”. Do not require creating or linking an additional workstation or coverage. A manual computer may be explicitly reconciled with a currently present import from the same company, only when the imported record has no local data or history to lose. Names alone must never cause an automatic merge. Reconciliation removes only the redundant empty local import, never a GravityZone device.

## Credentials and mandatory customer intervention

1. Implement the protected GravityZone configuration page first, then make the application accessible in development and explain where to sign in and enter the API key.
2. Stop before live API tests. Wait for the customer to enter the key through the panel and explicitly say to resume. Never request the key in chat or embed it in source code.
3. SMTP and communications are outside #26 and suspended. If subsequently approved, provide protected administrator settings and encrypt stored passwords without returning secrets to the browser or logs.
4. When that approved work needs real SMTP credentials, make the panel accessible, explain how to configure it, and stop until the customer explicitly says to resume.
5. Use verified, anonymized fixtures and test data for work that does not require real credentials. Distinguish simulated checks from live checks. Never send real emails to customers during testing without an explicit customer action authorizing that send; permission to resume is not permission to send customer emails.

## Current commercial dates and minimal history

- The customer has requested the company product from GravityZone: display documented assignedProductType/assignedProtectionModel in read-only form, using the detected company for imported computers and the local company for manual computers. Missing fields remain unknown; never infer a per-computer commercial edition. Preserve previously saved local product text in snapshots/transfers and display it read-only when present.
- Each Computer exposes optional activation date, commercial expiry, price, invoice/quote references and notes. The customer removed the commercial description field from create/edit forms; retain existing values and snapshots without exposing an editable field. A manual computer requires a name and company. Technical names remain read-only. Do not require optional fields or introduce a product/price catalog.
- Dates are current values edited directly in the computer modal, not separate periods created by the operator. The expiry is the last included day; when both dates exist, expiry cannot precede activation. The UI suggests one year from activation only when expiry is empty, using 28 February for 29 February in a non-leap year; the operator can change the result.
- Four computers activated on 20/04/2026 and a fifth on 01/05/2026 retain their own dates under one company technical expiry. Never infer dates or purchased products from API fields.
- Money is optional, stored in integer euro cents and validated without implicit rounding. No purchasing costs, margins, price catalogs or pro-rata calculations.
- Essential statuses and filters stay in Licenze: missing dates, expired, expiring within 30 days including today, active and future activation. Rendering and filtering use local data without sending emails or making API calls.
- Replacement transfers the complete purchased period, product, price, description and references to another computer of the same company with no commercial data to overwrite. Clear those fields on the source so the purchased period is not duplicated; preserve each computer's name and notes. Keep minimal automatic snapshots and related computer identity/name for history, without separate assignment or coverage entities.
- Commercial mutations use transactions and version checks; transfers/reconciliation lock participating rows in ID order. Reject stale forms and conflicting operations instead of overwriting newer edits. Synchronization does not change the commercial version or history.

## Suspended renewal and communication work

Issues #10–#15 remain suspended after #26. Their former assumptions about separate coverage periods, products, proposals and screens are not authorization to restore that architecture. The approved #17 covers only verification and UI/UX refinement of the current Licenze flow. Do not implement future renewal calculations, proposals, PDFs, SMTP, public responses or automatic notifications in #17.

If communications are approved later, preserve the existing safety constraints: Laravel Mail, explicit operator confirmation before any send, no automatic customer email, private externally produced quote PDFs, no secrets in browser responses or logs, and unpredictable expiring/revocable public tokens with authorization, validation and rate limiting. Customer responses must never automatically change GravityZone or confirm a local renewal. Detailed behavior requires the revised approved issue.

## Local development schema reset

Issue #26 explicitly authorizes discarding the local development database without backup. A clean replacement schema is allowed; do not add conservative data migrations solely to retain disposable development records. Before any destructive operation, verify the effective connection, database identity and host/socket resolve to the intended local development database. Stop and ask if the destination is remote, production or cannot be clearly identified. This permission never covers other databases, customer files or GravityZone data. A full database reset also removes local users and saved integration settings; recreate them through the normal protected setup flow.

## GitHub workflow — mandatory approval gates

1. One approved issue = one dedicated branch, named issue/<number>-<short-description>, branched from current main. Do not mix unrelated issues in one branch or modify main directly, except for the explicit bootstrap below.
2. Before coding, read the entire issue, identify dependencies, explain any blockers, and inspect the relevant project files.
3. Implement and run proportionate checks locally. For money, dates, state transitions, permissions, API synchronization and public renewal links, include focused automated tests and test relevant failure cases. Perform your own technical and functional review.
4. Before any push, show the customer the complete relevant diff, changed files, tested scenarios and outcomes, UI screenshots/preview when applicable, and known limitations. Request explicit approval and stop until it arrives. Feedback is implemented on the same branch and reviewed again.
5. Only after explicit authorization may the branch be pushed and a PR prepared. Do not merge without a separate, explicit approval after the customer has reviewed the final PR/diff. Never assume that approval of a specification authorizes a push or merge. Silence is not consent.
6. After the approved merge into main, verify integration, close the corresponding issue, and delete the merged issue branch both locally and remotely. Never delete a branch if it contains unmerged work.
7. Do not perform destructive operations outside the explicitly authorized, verified local development schema reset above. Never publish credentials or change production data. Production deployment is blocked as specified below.
8. Empty-repository bootstrap exception: if main has no initial commit, prepare and show the complete AGENTS.md text and request specific approval for a one-time initial commit and push on main containing only AGENTS.md. The specification/backlog approval does not authorize this commit. Do not commit or push before that approval, and do not begin any other issue until the approved file is present in the repository. This is the only exception to the issue-branch workflow.

## Quality and completion

- Tests must be proportional to risk; avoid duplicate, brittle or purely decorative tests. Use verified payload shapes in anonymized fixtures, identifying their source and any live-verification limits; no invented fields.
- Check relevant failures and edge cases: independent company/computer expiries, optional dates and money, invalid dates and leap years, inclusive expiry boundaries, direct edits, stale forms, replacement transfers, explicit reconciliation, rename/absence/reappearance/reinstallation, remote company moves, missing API fields, repeated synchronization, authorization and transaction rollback. Add tests for future approved behavior only when that work resumes.
- Avoid N+1 database access, unnecessary polling and unnecessary API calls. Sync jobs should be repeatable and safe to retry; report failures without corrupting commercial data. Do not commit private customer information, real credentials or .env files; document non-secret configuration keys.
- Personally verify the main screens and complete development flow with available browser tools, including desktop/mobile previews. If tools or credentials prevent a check, state exactly what remains unverified. Do not report fixture tests as successful live integrations.
- The #17 development check covers login, company selection, automatically listed imported computers, technical/commercial expiry separation, direct editing, optional fields, status filters, manual creation, contextual reconciliation and replacement, plus repeated synchronization without losing local data/history. Use fixtures until live-check prerequisites are satisfied. The former wider #17 acceptance scope is superseded by the customer-approved scope above.
- At handoff, report what changed, what was tested (including commands and results), what was not tested, limitations, and which approval is required next. Do not mark work complete if tests fail or unresolved functional questions remain.
- Issue #26 is complete only when its accepted scope is implemented and verified without known blocking errors, desktop/mobile previews and limitations have been reviewed by the customer, code is integrated through the separately approved push and merge, the issue is closed and the merged branch is removed locally/remotely. No production release may occur. Completion does not authorize resuming suspended development issues.

## Production release is blocked

Do not deploy to CloudPanel, cPanel or any production server. Do not configure production domains, run migrations on real customer databases, or publish the application on an external server. Do not prepare unnecessarily complex release procedures. Deliver a complete application verifiable in development; distribution is a separate decision after customer acceptance and requires new explicit authorization.

# BitLicense — Agent Instructions

## Purpose and scope

BitLicense is a small Italian-language application for an MSP to track Bitdefender GravityZone companies, endpoints, and independent commercial coverage periods per workstation. GravityZone's company-level technical license expiry and BitLicense's per-device commercial expiry are different facts and must never be conflated.

The approved specification defines the product; GitHub issues are the operational backlog. Coordinate and implement all active development issues until the application is working, tested and ready for the customer's final review in development. Issue #16 (hosting/deployment) is excluded and must not be reopened; issue #17 covers end-to-end acceptance in development only. Do not create release or deployment issues.

Implement only the approved GitHub issue. Do not add reseller purchasing costs, accounting, invoicing, quote generation, bulk initial commercial-data imports, license inventory, automated endpoint removal, a general task manager, or features not explicitly requested. A customer's quote is an externally produced PDF attached to a manually reviewed renewal email.

## Required development approach

- Use Ponytail: read and activate the upstream skill from https://github.com/DietrichGebert/ponytail (prefer the full mode where supported) before implementing or reviewing changes. Verify that it is available to the current agent, including each sub-agent. Do not claim to have used it if it is unavailable; report the blocker.
- Read this entire file before application development. Read the complete relevant source files before modifying them. If output is truncated, inspect remaining portions in chunks; never infer that omitted text or ... is part of the file.
- Understand the actual data flow and existing implementation before proposing changes. Do not invent API capabilities, fields, behavior, configuration or test results. Verify the GravityZone data contract against available POC output and official documentation; reuse the user's POC when available and appropriate.
- Prefer existing project code, framework APIs and installed components. Follow YAGNI and DRY without introducing speculative abstraction. Write simple, clear, idiomatic, maintainable Laravel/PHP code. Avoid needless services, repositories, helpers, wrapper layers, new dependencies and premature generalization.
- Prioritize correctness and maintainability over code-golf. Do not sacrifice validation, authorization, security, error handling or accessibility to reduce lines of code. Do not deliberately introduce technical debt without a documented reason.
- Comment non-obvious code only, in English. Avoid explanatory noise and boilerplate comments. User-facing labels, messages and validation text are in Italian.
- Keep migrations and models narrowly aligned to the real commercial domain. Preserve historical coverage and assignments when a workstation is replaced or disappears from GravityZone. External synchronization must never overwrite locally owned commercial data.

## Supervision and autonomous progress

- Act as the architecture, engineering and product supervisor, accountable for the final result. Make ordinary technical decisions independently. Ask the customer only for required credentials, unresolved functional decisions or mandatory approvals.
- Use real sub-agents when delegation provides a concrete benefit for a bounded implementation, independent analysis, review or testing. Handle small changes directly when that is simpler. If sub-agents are unavailable, work directly and do not simulate delegation.
- Before delegation, read the issue and acceptance criteria, verify dependencies and repository state, define the task and allowed files/branch, and provide project instructions and the Ponytail skill. Require each sub-agent to read both. Avoid concurrent edits to shared files or work based on dependencies not yet integrated.
- Personally inspect delegated work against the original requirement, security, simplicity, readability, integration and appropriate tests. Remove unnecessary complexity. Fix small problems directly or request targeted corrections. A sub-agent's completion report is not proof of completion.
- Sequence issues by actual dependencies and reuse opportunities. After an approved integration, continue with the next available issue. Do not create new issues for work that belongs in existing ones; report out-of-scope problems without implementing them.
- Keep a clear record of completed work, current branch/issue, checks, blockers, required approvals and the next step so work can resume reliably. Stop affected work at approval, credential or indispensable functional-decision gates; never bypass them.

## Technology and interface

- Laravel 13, Filament 5 (including its native auth/components), MySQL/MariaDB, Laravel Mail for communications and Laravel Scheduler/Cron where needed. Verify actual framework/runtime requirements rather than assuming compatibility.
- No frontend build pipeline, including during development: do not require Node.js, npm, Vite, React or npm run build. Use prebuilt Filament panel assets and simple static CSS only when strictly necessary; do not introduce a compiled Tailwind custom theme.
- The architectural target is ordinary PHP hosting (CloudPanel, cPanel or equivalent), but execution and verification in this phase are development-only. Do not require Docker, Redis, permanent queue workers, Supervisor or shell services beyond ordinary PHP CLI/Cron. If a requirement cannot be met on the target, identify it before implementation.
- Dark-first, carefully designed, responsive UI in Italian, with Europe/Rome timezone and Italian date/time formats. Do not ship a generic collection of autogenerated CRUD tables without reviewing the actual operational workflow. Reuse Filament components and create specific pages only when needed; show screenshots or a local preview for UI-related work before approval.
- Users are created and disabled manually by administrators. Use only administrator/operator roles with server-side authorization on sensitive pages and actions, including direct URLs. Reuse native authentication, session, password, CSRF and rate-limit protections. No self-registration, bespoke invitation subsystem or complex permissions framework.

## GravityZone: strictly read-only

- Use one GravityZone API key for the installation, configured only through a protected administrator page and encrypted at rest. Never expose saved credentials in logs, browser responses or commits.
- Do not implement the capability to create, modify, delete, uninstall, assign or otherwise mutate GravityZone data or devices, including unused methods, operational examples or administrative endpoints.
- Do not implement a generic JSON-RPC client that can invoke arbitrary methods. Implement only an explicit allowlist of necessary read methods whose semantics, parameters and payloads have been verified against official documentation and available POC output.
- JSON-RPC reads may use HTTP POST: determine read-only safety from the API method's semantics, not the HTTP verb.
- Use Laravel's HTTP facilities; handle authentication, pagination, timeouts, network errors and HTTP 429/Retry-After without leaking secrets or personal data. Do not invent unavailable fields or assume that each endpoint's commercial product is exposed by the API.
- Synchronization must be repeatable without duplicate companies or endpoints. Use stable external IDs, never hostname as persistent identity. Track synchronization status/errors and preserve local contacts, notes, commercial records and historical assignments when remote records disappear or reappear.
- New endpoints start as “Da verificare” without automatic commercial coverage. Manual workstations may be linked to imported endpoints; uncertain matches require an explicit operator choice, without automatic merging or deletion.

## Credentials and mandatory customer intervention

1. Implement the protected GravityZone configuration page first, then make the application accessible in development and explain where to sign in and enter the API key.
2. Stop before live API tests. Wait for the customer to enter the key through the panel and explicitly say to resume. Never request the key in chat or embed it in source code.
3. Provide administrator settings for SMTP server, port, encryption, credentials, sender and communication settings. Encrypt the SMTP password and never return stored secrets to the browser or logs.
4. When real SMTP credentials are needed, make the panel accessible, explain how to configure it, and stop until the customer explicitly says to resume.
5. Use verified, anonymized fixtures and test data for work that does not require real credentials. Distinguish simulated checks from live checks. Never send real emails to customers during testing without an explicit customer action authorizing that send; permission to resume is not permission to send customer emails.

## Commercial coverage and renewals

- Allow manual workstation creation, commercial descriptions, activation/installation dates, notes and invoice/quote references, separately from synchronized technical fields. Initial commercial data is entered manually; no bulk initial import.
- Track each coverage's company, start, end, price and commercial references independently of GravityZone's technical company expiry and firstSeen. Preserve period and assignment history. A coverage can belong to only one workstation at a time; transfer to a replacement preserves the purchased period, price and history.
- Support initial coverage for one or several workstations sharing dates without introducing complex batches or inventory. Four workstations covered from 20/04/2026 to 20/04/2027 and a fifth from 01/05/2026 to 01/05/2027 must remain distinct under one company technical expiry.
- Let operators describe products manually when API data is unavailable. Add-ons are included in the total price. Propose a customary annual price per company/product, overridable for an individual coverage or renewal; do not track purchasing costs or margins.
- Display technical company expiries and commercial workstation expiries separately, with useful filters and internal alerts for upcoming/expired coverage, items to verify and renewals to handle. Repeated synchronization/refresh must not duplicate notifications or send customer emails.
- Renewal targets may be 31 December of the same year, the following year or another operator-selected date. Suggest a proportional amount while allowing manual changes and preserving the final chosen amount. Document and verify date boundaries, rounding, leap-year and multi-year handling; avoid overlapping periods or implicitly charging already covered days. Do not change coverage dates before operational confirmation.
- A proposal may contain selected workstations from one company with different proposed periods and amounts. Keep coherent draft/sent/response/partial/pending-confirmation/completed/refused states. Store snapshots of sent proposal lines and attachments so later changes cannot rewrite history.

## Communications and customer responses

- Use one configurable visual identity, including logo, colors, sender, signature and business details. Configure recipients and CC per company without changing synchronized technical data. Do not add a drag-and-drop email builder.
- Use Laravel Mail. Prepare an email draft with editable subject, content, recipients and CC; show the final preview and attached external quote PDFs. Require explicit operator confirmation to send. Never send automatically on synchronization, login, page rendering, expiry changes or scheduling.
- Store quote PDFs privately with controlled access. Preserve sent content, recipients, attachment references and outcomes. Handle missing attachments, SMTP failures, timeouts, double clicks and retries without false success or unintended duplicate sends. Branding/contact changes affect future drafts, not sent history.
- Public renewal links must use unpredictable, expiring, revocable tokens and allow access only to their own proposal. Apply rate limiting and server-side validation. Let customers accept/refuse individual workstations and optionally leave a note; record decisions and time without duplicate responses or inadvertent changes to closed proposals.
- Customer responses generate internal operator notifications, without automatically renewing coverage or changing GravityZone. Operators may record responses received by phone, email or another channel and explicitly confirm a completed renewal or mark removal/verification as still to handle.
- Keep expired, refused and unanswered renewals visibly open until handled, preserving decision and status history. No automatic endpoint removal or general task-management subsystem.

## GitHub workflow — mandatory approval gates

1. One approved issue = one dedicated branch, named issue/<number>-<short-description>, branched from current main. Do not mix unrelated issues in one branch or modify main directly, except for the explicit bootstrap below.
2. Before coding, read the entire issue, identify dependencies, explain any blockers, and inspect the relevant project files.
3. Implement and run proportionate checks locally. For money, dates, state transitions, permissions, API synchronization and public renewal links, include focused automated tests and test relevant failure cases. Perform your own technical and functional review.
4. Before any push, show the customer the complete relevant diff, changed files, tested scenarios and outcomes, UI screenshots/preview when applicable, and known limitations. Request explicit approval and stop until it arrives. Feedback is implemented on the same branch and reviewed again.
5. Only after explicit authorization may the branch be pushed and a PR prepared. Do not merge without a separate, explicit approval after the customer has reviewed the final PR/diff. Never assume that approval of a specification authorizes a push or merge. Silence is not consent.
6. After the approved merge into main, verify integration, close the corresponding issue, and delete the merged issue branch both locally and remotely. Never delete a branch if it contains unmerged work.
7. Do not perform destructive operations, publish credentials, or change production data. Production deployment is blocked as specified below.
8. Empty-repository bootstrap exception: if main has no initial commit, prepare and show the complete AGENTS.md text and request specific approval for a one-time initial commit and push on main containing only AGENTS.md. The specification/backlog approval does not authorize this commit. Do not commit or push before that approval, and do not begin any other issue until the approved file is present in the repository. This is the only exception to the issue-branch workflow.

## Quality and completion

- Tests must be proportional to risk; avoid duplicate, brittle or purely decorative tests. Use verified payload shapes in anonymized fixtures, identifying their source and any live-verification limits; no invented fields.
- Check relevant failures and edge cases: independent company/device expiries, dates and rounding, leap years, changed renewal dates, replacement coverage transfers, partially accepted renewals, endpoint renames/reinstallation, manual-to-synced linkage, missing API fields, repeated synchronization, authorization, invalid/expired/revoked public links and controlled email sending.
- Avoid N+1 database access, unnecessary polling and unnecessary API calls. Sync jobs should be repeatable and safe to retry; report failures without corrupting commercial data. Do not commit private customer information, real credentials or .env files; document non-secret configuration keys.
- Personally verify the main screens and complete development flow with available browser tools, including desktop/mobile previews. If tools or credentials prevent a check, state exactly what remains unverified. Do not report fixture tests as successful live integrations.
- The end-to-end development check covers company/endpoint imports, commercial edits, expiry alerts, proposal/PDF preparation, explicitly authorized manual email, customer response, internal notification and operator confirmation, plus replacement transfers and later synchronization without historical data loss.
- At handoff, report what changed, what was tested (including commands and results), what was not tested, limitations, and which approval is required next. Do not mark work complete if tests fail or unresolved functional questions remain.
- The assignment is complete only when all active development issues are implemented, verified, approved and closed; fundamental flows have no known blocking errors; the main UI and end-to-end development flow have been checked with required customer interventions; the final version has been reviewed by the customer; code is integrated through approved merges (apart from the approved bootstrap); integrated branches are removed; and no production release occurred.

## Production release is blocked

Do not deploy to CloudPanel, cPanel or any production server. Do not configure production domains, run migrations on real customer databases, or publish the application on an external server. Do not prepare unnecessarily complex release procedures. Deliver a complete application verifiable in development; distribution is a separate decision after customer acceptance and requires new explicit authorization.

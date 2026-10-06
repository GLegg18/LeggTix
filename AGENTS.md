# LeggTix project guidance

## Project and stack

- LeggTix is an API-first event reservation and waitlist service, built as a Laravel modular monolith.
- Use PHP 8.4, Laravel 13, MySQL 8, Redis, Docker Compose, and PHPUnit, following the versions and tools already configured in the repository.
- The default development target is a fully local Docker Desktop environment on Windows. Keep setup reproducible and document host requirements.
- Laravel owns business rules and is the source of truth. Nuxt is the lightweight customer-facing UI; Filament is optional internal administration only.
- Cloud Run is a possible later deployment target, not a reason to make local development GCP-specific.

## Design principles

- Prefer conventional Laravel code and a small, understandable modular monolith. Keep controllers focused; use an action/service when a real business workflow benefits from one.
- Avoid repository interfaces, extra layers, packages, or abstractions for hypothetical future needs.
- Treat MySQL as authoritative for reservation capacity. Protect capacity changes with transactions and appropriate locking or constraints; a count-then-insert check is not safe under concurrency.
- Preserve the core rules: only published, future events accept reservations; a user has at most one active reservation per event; capacity cannot be exceeded; users may change only their own reservations; event owners control their events.
- Make waitlist order deterministic and promotion safe under retries or concurrent cancellation. Keep external calls out of database transactions and dispatch side effects after commit when needed.
- Redis may support queues, caching, and rate limits. Do not use cached availability as the source of truth.

## Security and scope

- Enforce authentication and authorization on the Laravel side, including ownership checks for resource IDs. Validate input, explicitly control writable and returned fields, and use secure defaults for secrets and CORS.
- Treat the task prompt as the work request. Use a linked GitHub issue and its acceptance criteria when available, but do not require a ticket if the prompt already gives enough context.
- Read only the surrounding files and documentation relevant to the task. Ask when an unresolved choice could materially change a business rule, schema, permission boundary, or public API contract; otherwise use the smallest reasonable assumption.
- Implement optional Nuxt, Filament, caching, or cloud work only when requested or when the assigned task calls for it. Keep the core local reservation flow first.

## Completion reports and agent handoffs

- The agent coordinating a task is responsible for one end-to-end final report, even when developer, tester, or security-reviewer roles were used separately.
- Summarise what changed and why, important files, checks actually run and their results, assumptions, and remaining gaps or recommended next steps.
- Record meaningful bugs found and fixed with their symptom or risk, cause, fix, and verification. Summarise tester and security-reviewer findings individually as fixed, deferred (with a reason and next step), or not reproduced (with evidence).
- Include the tester's relevant manual test plan and expected outcomes when one was produced. Clearly identify checks, reviews, or agent roles that were not run; never imply unperformed verification.
- Preserve unresolved security, authorisation, concurrency, or data-integrity findings prominently so they are not lost in a generic follow-up list.

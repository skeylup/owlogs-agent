# Changelog

All notable changes to `skeylup/owlogs-agent` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Laravel **8.65 → 13** support, PHP **8.1+**. Monolog 2/3 compatibility via two `RemoteHandler` variants picked at boot by `RemoteLogChannel`. Cross-version `Context` API wrapped in `Compat\ContextShim` (delegates to Laravel 11+ Context, falls back to a per-process polyfill on L8/L9/L10).
- `ContextShim` polyfill for older Laravel — same `addHidden / pushHidden / getHidden / allHidden / hydrated` surface, in-process store when Laravel's native facade is missing.
- `IdShim::ulid()` — `Str::ulid()` on L9+, falls back to `Str::random(26)` on L8.
- `owlogs:emit-test-logs` artisan command — emits one log of every captured kind, tagged with a shared `test_run_id`, for sandbox / smoke-test scripts.
- Three new auto-log sources: `OWLOGS_AUTO_ROUTE_MATCHED` (on by default), `OWLOGS_AUTO_DB_TRANSACTION` (off), `OWLOGS_AUTO_LIVEWIRE_CALL` (off).

### Changed
- **Breadcrumb feature retired.** `Skeylup\OwlogsAgent\Breadcrumb` keeps its public API (`add / all / clear`) but every method is now a **no-op** — existing call sites compile without throwing. Auto-log lines tagged with the shared `trace_id` produce the same chronological narrative without the per-record payload duplication, and they cost less quota (one row per event vs. N decorations attached to every downstream row in the trace). Migration is best done call-site by call-site; until then existing `Breadcrumb::add()` lines silently no-op.

### Removed
- `breadcrumbs` is no longer populated on outgoing log rows (DB column is preserved for backwards-compat with the server schema). Server-side AI markdown export, MCP tool descriptions, embedding builders, and the workspace trace-detail UI no longer reference breadcrumbs.

## [1.0.9] - 2026-04-21

### Changed
- `RemoteHandler::buildRow()` no longer falls back to `auth()->id()` per log record. Resolving the guard + user provider on every record accumulated to dozens of redundant resolutions on busy requests. `user_id` is already populated once per request/job by `AddLogContext`, the `CommandStarting` listener, or the queue `Context::hydrated` listener — `buildRow` now reads it from context or emits `null`.

## [1.0.3] - 2026-04-19

### Fixed
- CLI (`CommandStarting`) context now populates `app_name`, `app_env`, `app_url`, `git_sha`, and (when authenticated) `user_id`, mirroring the HTTP middleware. Log rows emitted from artisan commands — and queue jobs chained from them — were previously missing the app identity, which broke per-environment filtering in the viewer.
- Queue `Context::hydrated` listener now defensively fills `app_name`, `app_env`, `app_url`, `git_sha` when absent from the dispatcher-serialized context (covers jobs dispatched from tinker, bare CLI, or external processes).

### Added
- `CommandFinished` listener that records `duration_ms` and a `command` measure, matching the per-request timing captured by `AddLogContext`.
- `AddLogContext::resolveGitSha()` is now a public static helper so the CLI + queue listeners can reuse the cached SHA resolution.

## [1.0.2] - 2026-04-19

### Fixed
- `RemoteHandler` now normalises `logged_at` to UTC before shipping. Apps running in a non-UTC timezone were sending local timestamps, which the server stored verbatim as `timestamp WITHOUT time zone` — the logs ended up offset by the TZ delta and fell outside the UI's default time window.

## [1.0.0] - 2026-04-19

### Added
- Initial public release.
- Automatic log context enrichment (`trace_id`, `span_id`, `origin`, user / tenant / route / timing).
- HTTP middleware, queue `Queue::before/after`, and `CommandStarting` listeners to propagate context across runtimes.
- `RemoteHandler` + `SendLogsJob` for async, buffered shipping to the Owlogs ingest endpoint.
- `Measure` helper and opt-in DB query tracking with N+1 detection.
- `Breadcrumb` helper and `HasLogContext` contract for model enrichment.
- Opt-in auto-logging for auth, job lifecycle, mail, cache, slow queries, migrations, scheduled tasks and model changes.
- Octane-safe flushing via a 1s tick plus shutdown / `Queue::after` fallbacks.

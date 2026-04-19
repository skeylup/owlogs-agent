# Changelog

All notable changes to `skeylup/owlogs-agent` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

---
name: owlogs-mcp
description: 'ACTIVATE when the user has configured an Owlogs MCP server in their IDE (Claude Code / Cursor / Windsurf / Cline / Codex CLI) and is asking the agent to investigate logs, traces, errors, slow routes, slow queries, N+1, or jobs from their workspace. Triggers on mentions of "owlogs", "the workspace logs", "trace_id", "what broke", "errors today", "slow queries", "n+1", "n plus one", "missing eager load", and on any tool call to `whoami`, `traffic_overview`, `list_recent_errors`, `list_slow_queries`, `list_n_plus_one`, `search_logs_*`, `get_trace*`, `analyze_route_performance`, `summarize_*`, `extract_root_cause`, `build_trace_url`, or any `github_*` MCP tool. Guides the agent to (a) start every session with `whoami`, (b) cascade from cheap aggregations to expensive deep-dives, (c) prefer local Read/Grep over `github_*` code tools, and (d) use the `summarize_*` tools to keep input tokens low. Do NOT activate for in-app chat questions, generic Laravel logging help, or non-Owlogs log shippers.'
license: MIT
metadata:
  author: skeylup
  homepage: https://www.owlogs.com
---

# Owlogs MCP — investigation playbook for IDE agents

You are connected to an **Owlogs workspace** through the MCP HTTP transport
exposed by `skeylup/owlogs-agent`'s host application. You are running in an
IDE (Claude Code / Cursor / Windsurf / Cline / Codex CLI) on the user's
machine, with **direct access to the local source tree** — leverage that.

The MCP server is workspace-scoped: every tool you see operates on ONE
workspace's database. The user pinned that workspace by configuring the URL
`/mcp/workspaces/{slug}` in their IDE.

## Open every session with `whoami`

The very first MCP call should be `whoami` (no arguments). It returns:

- The active workspace name + slug.
- Your authenticated user's email + role.
- Remaining `owlogs` credits + low-balance threshold.

Announce these to the human in one short line. If `credits.exhausted` is
true, **stop** and tell the user — every subsequent tool call will 402.

## Investigation cascade — cheap → expensive

Pick the cheapest tool that can answer the question. Escalate only when the
cheaper one came back empty or ambiguous.

1. **Bird's-eye / "is everything ok?"** → `traffic_overview` (single call:
   totals, errors, top routes/jobs, deploys, open issues).
2. **"Top errors / what's broken right now?"** → `list_issues` (pre-deduped
   exceptions / N+1s / slow queries) or `list_recent_errors`.
2bis. **"What slow queries should I fix?"** → `list_slow_queries` (NOT
   `list_issues` — the dedicated tool surfaces SQL, latest duration,
   caller, connection at the top level + sort by `duration` to rank by
   slowest known instance). Default sort = `occurrences` to find the
   most-fired offender; switch to `sort: "duration"` for the slowest
   single instance, or pass `min_duration_ms: 500` to focus on the
   really nasty ones.
2ter. **"Where are my N+1 queries?"** → `list_n_plus_one` (same idea —
   surfaces SQL pattern, caller, and the `latest_duplicate_count`
   = how many copies of the same query fired in ONE request). Sort by
   `duplicate_count` to rank by per-request severity, or by
   `occurrences` to find the most-fired N+1 across the workspace.
2quater. **"Which files / methods break the most?"** → `top_errored_symbols`
   ranks `caller_file` / `caller_method` / `caller_line` by error
   occurrence count over the window, with affected user / distinct trace
   counts and a sample trace_id. Pass `group_by: "file"` for a coarse
   leaderboard, `"method"` (default) for the sweet spot, `"line"` to
   pinpoint specific call sites in big files. Filter to a directory with
   `path_contains: "app/Http/Controllers"`. The natural follow-up: open
   the symbol locally and `get_trace_markdown(sample_trace_id)` to fix.
3. **Targeted exact match** (a route, a user_id, a job class, a `trace_id`)
   → `search_logs_by_field`.
4. **Composable filters across columns** (level + route + time + free-text
   substring inside `caller_file/caller_method/stacktrace/message`)
   → `search_logs_advanced` with `mentions: '<basename>'`.
5. **Value lives in JSON** (Stripe `request_input.data.object.id`,
   `context.order_id`, `extra.user.email`) → `search_logs_by_json`.
6. **Open-ended question / fuzzy / NL** ("checkout felt slow",
   "intermittent SSL error", "anything weird about this queue lately?")
   → **`search_logs_hybrid`** is the right default. It runs lexical +
   semantic in parallel, fuses both rankings via Reciprocal Rank Fusion,
   and reranks with a cross-encoder (Cohere). Catches BOTH exact
   identifiers (a stripe id buried in the message) AND meaning. Returns
   `score` + `source` (lexical / semantic / both / reranked) for each hit.

### When NOT to use hybrid

- **Single-token / phrase** that you KNOW is in the message ("X-Owlogs-Spent",
  a class name) → `search_logs_text` is enough and cheaper.
- **Pure semantic** with no keyword overlap ("conceptually similar errors")
  → `search_logs_semantic` skips the rerank cost and works fine.
- **Cost-conscious follow-up** (you already have 5 candidate trace_ids and
  just want to filter them by level / route) → `search_logs_advanced` with
  the structured filters, no embedding call needed.

Hybrid is more precise but pays for an embedding call AND a reranker call —
~20 owlogs per call vs ~15 for pure semantic. Worth it when you'd otherwise
be playing whack-a-mole with semantic false positives.

For perf:

- `analyze_route_performance` returns p50/p95/max + top errors + slowest
  traces in **one** call.
- `analyze_measures` aggregates `measures[].label/duration_ms` instrumentation.
- `list_slow_traces` ranks individual traces by `duration` (default,
  MAX(duration_ms) per trace), `memory` (MAX(memory_peak_mb)), or
  `entries` (COUNT(*) of log lines per trace — good proxy for
  query-heavy / chatty traces). Combine with `min_duration_ms`,
  `min_memory_mb`, or `min_entries` thresholds to filter noise.

For correlation / impact:

- `who_was_affected(trace_id|issue_id)` → distinct users, emails, routes.
- `get_user_recent_errors(user_id)` → last N error traces for ONE
  upstream user (use when a real user complained — pass the opaque
  `user_id` exactly as the upstream app emitted it, bigint / UUID /
  ULID / email / slug). Cheaper and tighter than `search_logs_by_field`
  as it pre-dedupes by trace_id and filters to error severities.
- `compare_deployments(base_sha, head_sha)` → NEW vs disappeared error
  messages between two deploys.

## Trace deep-dive — pick the right tool for the user's intent

Two flavours of "I want to understand this trace":

### A. The user wants you to **FIX the code** that broke

Use `get_trace_markdown(trace_id)`. Returns the FULL trace as markdown —
metadata, request input, error message, every entry's stacktrace,
breadcrumbs, performance measures, all secrets redacted. **Pure DB read,
zero owlogs spent on the Owlogs side.** Your local LLM (the IDE) reads
it natively and can walk the stacktrace into the local source tree via
Read / Grep.

This is the right call for: "fix this trace", "why is this failing",
"reproduce locally", "what's wrong with the checkout flow", anything
where the agent's next step is to read source code.

### B. The user wants an **explanation in prose**

Use `summarize_trace_narrative(trace_id)`. Returns a 2-paragraph
narrative, ~10× cheaper input than a raw markdown dump. Costs owlogs
(it runs a cheap sub-agent server-side) but the answer is short and
self-contained — the user reads, they don't ask you to fix.

For a **stacktrace explanation only** (one specific exception, no
fix-the-code intent) → `summarize_stacktrace(trace_id|entry_id)`.

For a **one-liner cause** in a list (annotating each of the top N
issues) → `extract_root_cause(trace_id)` per item.

### C. Cheap stats first

Always call `get_trace_summary(trace_id)` BEFORE either A or B if you're
not sure which trace is the right one. It returns entry count, peak
memory, first error, time bounds in a tiny payload — useful to confirm
you've identified the right incident.

### Avoid

- `get_trace(trace_id)` is the chat-only legacy that returns raw JSON
  payloads — DO NOT call it from the IDE; `get_trace_markdown` produces
  a more readable, smaller, secret-redacted output for the same data.

## Always include a deeplink to the trace

When you mention a specific trace in your reply, **always** call
`build_trace_url(trace_id)` and surface the URL as a clickable link. Do
NOT hand-craft the URL; do not paraphrase a stacktrace without giving the
user a way to open the full payload.

## GitHub tools — use the IDE's local repo, not github.com

The 6 GitHub **code-inspection** tools are intentionally soft-disabled
when called from an IDE — they would round-trip to github.com unnecessarily:

- ❌ `github_search_code` — use IDE's `Grep` / `rg` on the local clone.
- ❌ `github_get_file` — use IDE's `Read` on the local file.
- ❌ `github_list_files` — use IDE's `Glob` / `find` / file picker.
- ❌ `github_list_commits`, `github_get_commit`, `github_compare_refs` —
  use `git log`, `git show`, `git diff` locally.

If you call any of them you'll get an error message reminding you to use
the local tools. ONLY exception: when the human asks about a remote ref
the local clone can't reach (a PR diff from a fork, a commit on another
branch the user hasn't fetched, etc.) — then it's fine to call them.

The GitHub **issue / PR** tools remain useful and are NOT disabled:

- ✅ `github_list_issues`, `github_get_issue`, `github_search_issues` —
  remote ticket queue.
- ✅ `github_list_pull_requests`, `github_get_pull_request` — review
  context.
- ✅ `github_create_issue`, `github_comment_issue` — open / annotate
  tickets.

### Recommended recipe — file an issue from a real error

When the user says "open a ticket for this" after looking at a trace:

1. `get_trace_summary(trace_id)` — confirm it's the right trace.
2. `github_create_issue(repo: "owner/repo", title, body, trace_id, labels)`
   with a body using the markdown structure
   `**Symptom**` / `**Suspected cause**` / `**Steps to reproduce / context**`
   / `**Proposed fix**`.

When `trace_id` is passed, the **server-side** appends the full trace
export (metadata + error summary + breadcrumbs + perf measures, all with
secrets redacted) to the issue body inside a `<details>` block. **You do
not need to paste log content yourself** — saves a lot of input tokens
and gets you a richer issue for free.

## Working with the local code

### "Find errors related to this file" / "explain how this endpoint works"

1. Use the IDE's `Read` to load the local file the user is editing.
2. Use `search_logs_advanced(mentions: '<class basename>')` to find logs
   that mention it (the `mentions` parameter scans
   `caller_file + caller_method + stacktrace + message`).
3. Cross-reference: the `trace_id`s you find can be expanded with
   `get_trace_summary` → `summarize_trace_narrative`.

### "Fix this error" / "reproduce this trace locally" — the killer recipe

1. `get_trace_summary(trace_id)` — confirm it's the right incident.
2. `get_trace_markdown(trace_id)` — pull the full export (ZERO owlogs
   spent). This gives you the exception class + message, the failing
   request input (URL, method, payload, user_id), the full stacktrace
   with file paths, the breadcrumbs leading to the failure, and any
   inline performance measures.
3. Use the IDE's `Read` on the top user-code frames from the stacktrace
   (skip vendor frames). The file paths in the export match the user's
   local clone unless they're on a different branch.
4. `Grep` for related code paths if the error message mentions a
   specific value or constant.
5. Propose the fix as a diff in the editor. Mention the deeplink from
   `build_trace_url(trace_id)` so the user can verify against the live
   trace.

This is faster AND cheaper than the chat-style flow:
- Cheaper: zero owlogs (no LLM on the Owlogs side).
- Faster: one tool call gives you everything; no need to chain
  summarize_* calls and then ask the user "ok now show me the file".

## Discovering other workspaces

The current MCP session is **pinned** to the workspace in the URL. To
investigate a sibling workspace (e.g. comparing prod to staging):

1. Call `list_workspaces` — returns slugs + ready-to-paste `mcp_url`s for
   every workspace the user has access to.
2. **Tell the user** to add another MCP server entry to their IDE config
   using the new `mcp_url` and the SAME `Authorization: Bearer` token.
3. Restart the IDE. The new server will appear alongside the current one.

You **cannot** switch workspace within a session — don't try.

## Things to avoid

- Calling `get_trace` (legacy JSON dump). Use `get_trace_markdown` —
  same data, smaller, secret-redacted, parsable.
- Calling `summarize_trace_narrative` when the user wants you to FIX
  the code. The narrative costs owlogs AND drops the precise file/line
  info you need — `get_trace_markdown` is free AND gives you more.
- Calling any tool before `get_trace_summary` when you're not sure
  which trace is the right one (~200-byte response, nearly free).
- Hand-writing the trace URL. Always use `build_trace_url`.
- Calling `github_search_code` / `github_get_file` when the project is
  open in the IDE.
- Forgetting to forward `trace_id` to `github_create_issue` — without it
  you'd have to hand-write the trace context and burn tokens.

## Token cost transparency

Every MCP HTTP call debits a flat fee from the workspace's `ai_tokens`
entitlement (the IDE transport runs a billing middleware on each
`tools/call`). Five tiers, cheapest first:

| Tier | Owlogs per call | Tools |
|---|---|---|
| `light` | 1 | `whoami`, `list_workspaces`, `build_trace_url` |
| `medium` (default) | 5 | `traffic_overview`, `count_logs`, `list_*`, `search_logs_by_field / _advanced / _by_json / _text`, `get_trace_summary`, `get_log_entry`, `analyze_route_performance`, `analyze_measures`, `top_errored_symbols`, `who_was_affected`, `get_user_recent_errors`, `compare_deployments`, every `github_*` |
| `heavy` | 15 | `search_logs_semantic`, `get_trace`, `get_trace_markdown` |
| `hybrid` | 20 | `search_logs_hybrid` (embedding + Cohere rerank) |
| `llm` | 5 flat + actual LLM tokens | `summarize_stacktrace`, `summarize_trace_narrative`, `extract_root_cause` |

The flat fee covers the DB / HTTP / transport infra cost — even
pure-DB tools spend a non-zero number of owlogs per call. Add the
ACTUAL LLM token cost on top for `summarize_*` and `extract_root_cause`.

Every response carries `X-Owlogs-Spent` (this call) and
`X-Owlogs-Remaining` (workspace balance after debit) headers. The user
is billed in **owlogs** (≈ \$0.70 per million), debited from the
workspace's `ai_tokens` entitlement. If `whoami` reports
`status=exhausted` STOP — every subsequent call returns HTTP 402.

Cost-minimising rules of thumb:
- For raw data, use `get_trace_markdown` (heavy, but a single call gives
  you the full picture — no LLM cost on top).
- For prose, use `summarize_trace_narrative` (llm tier: short output,
  cheap LLM call).
- Cascade cheap → expensive: `traffic_overview` (medium) → `list_issues`
  (medium) → `search_logs_advanced` (medium) → `search_logs_hybrid`
  (hybrid) only if the cheaper lexical/structured filters came up empty.

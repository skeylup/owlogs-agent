---
name: owlogs-mcp
description: 'ACTIVATE when the user has configured an Owlogs MCP server in their IDE (Claude Code / Cursor / Windsurf / Cline / Codex CLI) and is asking the agent to investigate logs, traces, errors, slow routes, or jobs from their workspace. Triggers on mentions of "owlogs", "the workspace logs", "trace_id", "what broke", "errors today", and on any tool call to `whoami`, `traffic_overview`, `list_recent_errors`, `search_logs_*`, `get_trace*`, `analyze_route_performance`, `summarize_*`, `extract_root_cause`, `build_trace_url`, or any `github_*` MCP tool. Guides the agent to (a) start every session with `whoami`, (b) cascade from cheap aggregations to expensive deep-dives, (c) prefer local Read/Grep over `github_*` code tools, and (d) use the `summarize_*` tools to keep input tokens low. Do NOT activate for in-app chat questions, generic Laravel logging help, or non-Owlogs log shippers.'
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
3. **Targeted exact match** (a route, a user_id, a job class, a `trace_id`)
   → `search_logs_by_field`.
4. **Composable filters across columns** (level + route + time + free-text
   substring inside `caller_file/caller_method/stacktrace/message`)
   → `search_logs_advanced` with `mentions: '<basename>'`.
5. **Value lives in JSON** (Stripe `request_input.data.object.id`,
   `context.order_id`, `extra.user.email`) → `search_logs_by_json`.
6. **Phrasal text** → `search_logs_text` (Postgres tsvector, message body
   only).
7. **Fuzzy / NL** ("checkout felt slow") → `search_logs_semantic` — last
   resort, most expensive.

For perf:

- `analyze_route_performance` returns p50/p95/max + top errors + slowest
  traces in **one** call.
- `analyze_measures` aggregates `measures[].label/duration_ms` instrumentation.

For correlation / impact:

- `who_was_affected(trace_id|issue_id)` → distinct users, emails, routes.
- `compare_deployments(base_sha, head_sha)` → NEW vs disappeared error
  messages between two deploys.

## Trace deep-dive — always summarize, never paste raw

Once you have a candidate `trace_id`:

1. Start with `get_trace_summary(trace_id)` — entry count, peak memory,
   first error, time bounds. Cheap.
2. For a **narrative** ("tell me what happened in this trace") → call
   `summarize_trace_narrative(trace_id)`. Returns 2 paragraphs prose, safe
   to relay almost verbatim. ~10x cheaper than ingesting `get_trace`.
3. For a **stacktrace explanation** (one specific exception) → call
   `summarize_stacktrace(trace_id)` or `(entry_id)`. Returns structured
   exception + root cause + top 3 frames.
4. For a **one-liner cause** in a list (e.g. annotating each of the top 5
   issues) → call `extract_root_cause(trace_id)` per item.
5. ONLY fall back to `get_trace(trace_id)` when the human asks for the raw
   payloads or you can't reason without them. It returns up to 200 entries
   uncompressed and burns input tokens.

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

For "find errors related to this file" / "explain how this endpoint
works":

1. Use the IDE's `Read` to load the local file the user is editing.
2. Use `search_logs_advanced(mentions: '<class basename>')` to find logs
   that mention it (the `mentions` parameter scans
   `caller_file + caller_method + stacktrace + message`).
3. Cross-reference: the `trace_id`s you find can be expanded with
   `get_trace_summary` → `summarize_trace_narrative`.

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

- Calling `get_trace` before `get_trace_summary` (wastes 5-30 KB of input
  tokens for a stat you could have read in a 200-byte response).
- Pasting a raw stacktrace into your reply. Always go through
  `summarize_stacktrace` and reference the deeplink.
- Hand-writing the trace URL. Always use `build_trace_url`.
- Calling `github_search_code` / `github_get_file` when the project is
  open in the IDE.
- Forgetting to forward `trace_id` to `github_create_issue` — without it
  you'd have to hand-write the trace context and burn tokens.

## Token cost transparency

Every response carries a `X-Owlogs-Spent` and `X-Owlogs-Remaining` header.
The user is billed in **owlogs** (≈ \$0.70 per million owlogs), debited
from their workspace's `ai_tokens` entitlement. Tools that **don't** call
an LLM (search, get, count, list) cost zero owlogs — only the
`summarize_*` and `extract_root_cause` tools spend the entitlement.

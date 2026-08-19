# Admin Diagnostics

Lives in `easy-mcp-ai/includes/diagnostics/`. Answers "why can't my AI client connect?" from **inside** WordPress, where an external probe is blind: a missing table, a token whose filter chain yields zero tools, an `Authorization` header the web server drops before PHP runs. Spec and full history: `docs/plans/2026-08-04-admin-diagnostics.md`.

## Check IDs

The letter is the group; the number is the check within it. IDs are lowercase strings passed as the first argument to `Diagnostic_Result::pass()`/`fail()`/`warn()`/`unknown()`, and are referenced throughout this file as shorthand. **49 IDs, one per shipped check.**

| Group | Covers | Class | IDs |
|---|---|---|---|
| **A** | Auth & transport — the faults between the web server and PHP, where an external probe sees only a status code | `class-check-transport.php`, plus `-header-probe` (A1), `-notices` (A7/A8), `-edge-block` (A9) | A1–A9 |
| **B** | Session storage and object-cache health | `class-check-session.php` (B1/B2), `class-check-transport.php` (B3) | B1–B3 |
| **C** | Database schema integrity — the group an external probe is completely blind to | `class-check-schema.php` | C1–C5 |
| **D** | Tool visibility — the largest support-forum cluster; without a token an external probe cannot list tools at all | `class-check-tool-visibility.php` | D1–D7 |
| **E** | Plugin and host conflicts — these **detect**, they do not accuse | `class-check-conflicts.php` | E1–E5 |
| **F** | Configuration foot-guns — working exactly as configured, but reaching the owner as "the plugin is broken" | `class-check-config.php` | F1–F4, F6–F10 (no F5) |
| **G** | Environment limits — PHP/OpenSSL settings that break MCP calls as if they were plugin faults | `class-check-environment.php` | G1–G5 |
| **H** | Activity — the only checks answering "why is it intermittent?"; every other group inspects a snapshot | `class-check-observability.php` | H1–H4 *(deep-only)* |
| **I** | Multisite — faults that exist only on a network and are invisible from any single site's admin | `class-check-multisite.php` | I1–I2 *(deep-only)* |

<details>
<summary>All 49, by label</summary>

`A1` Authorization header actually reaches PHP (loopback probe) · `A2` PHP server interface · `A3` Authorization header rewrite present · `A4` PHP errors hidden from responses · `A5` No stray output before headers · `A6` Client IP resolves per visitor · `A7` Permalinks allow API discovery · `A8` OAuth clients can reach this site securely · `A9` AI assistant can reach this site (edge-block probe)

`B1` AI sessions can be stored · `B2` Object cache is healthy · `B3` Scheduled cleanup is registered

`C1` Plugin database tables present · `C2` Required database indexes present · `C3` Change history lookup index · `C4` Database migrations complete · `C5` Database user can change the schema

`D1` Every API token can see tools · `D2` Token users can reach the tools they need · `D3` Tool filter patterns match something · `D4` All tool categories are available · `D5` Connected clients have usable permissions · `D6` WordPress Abilities available · `D7` Plugin ability schemas are client-compatible

`E1` No competing MCP plugin · `E2` Firewall and security plugins · `E3` REST API authentication unmodified · `E4` Change tracking hooks stay registered · `E5` Caching excludes API endpoints

`F1` Dynamic Client Registration · `F2` OAuth client capacity · `F3` Minimum capability to authorize an AI client · `F4` Per-token rate limit · `F6` Force draft on create · `F7` API token expiry · `F8` Working authentication present · `F9` OAuth HTTPS requirement · `F10` OAuth layer active

`G1` PHP max execution time · `G2` PHP memory limit · `G3` PHP input limits · `G4` AES-256-GCM encryption available · `G5` WordPress security keys usable for encryption

`H1` Recent sign-in failures · `H2` When each API token last worked · `H3` Activity logging · `H4` Stored history size

`I1` Every site on the network is set up · `I2` AI clients can find this site

</details>

## Registering a check

`Diagnostics::register( callable $cb, string $id = '', bool $deep_only = false )` — `$cb` returns `Diagnostic_Result[]` and anything else is ignored; `$id` is a **fallback** id used only if `$cb` throws, so the report can still name which check could not run; `$deep_only` confines the check to an explicit Re-run, for costs that are unbounded on a page load (whole-table counts, per-site loops on a large network).

`Diagnostics::register_core_checks( $tool_registry = null )` wires every shipped check, one `register()` call per group. Two things there are load-bearing:

- **Group D receives the LIVE registry by handle**, not a fresh one. `Dynamic_Tool_Registrar` populates the plugin's own instance with third-party abilities; a registry built inside the check via `auto_discover()` holds static tools only, so D6 and D7 would report on a list that never contained a single ability. Passing the handle also means abilities registered later on `wp_loaded` are present by the time checks run on `admin_init`.
- **The four deep-only registrations are A1, A9, Group H and Group I.** On a shallow run they do not vanish — they collapse to one "not run" placeholder each, which is why the suite yields **49 results on a deep run and 45 on a page load**.

**Result model.** Every check returns a `Diagnostic_Result` with one of 4 statuses (PASS/WARN/FAIL/UNKNOWN) and one of 3 tiers (blocker/warning/info). The value object is the single authority on routing: only **blocker + FAIL** raises the admin banner, blocker|warning reach Site Health, everything reaches the dashboard card. UNKNOWN never renders as a problem.

**Execution.** No cron, ever — B3 exists because cron may itself be broken. The suite runs only when an admin is looking: a plugin-admin page load with a report older than 24h, or a nonce-checked Re-run (which is also the only path that runs the 8 deep-only checks — A1's loopback probe, A9's edge-block probe, Group H's table counts, Group I's per-site loop). `Diagnostics::maybe_run()` requires `manage_options`: `wp-admin/admin-post.php` fires `admin_init` **before** its logged-in check, so a screen gate alone let anonymous requests drive the suite. The report caches in a **non-autoloaded option, never a transient** — B1 exists precisely because transients cannot be trusted on the sites this diagnoses.

**Writing or changing a check — the rules that matter** (§3.1; each was learned from a shipped defect):

| Rule | The trap |
|---|---|
| 1. Detection ≠ causation | A deliberate admin choice is never a warning |
| 2. UNKNOWN over guessing | "I could not look" is not "it is fine" |
| 3. Blockers must be deterministic | Banners only for directly observed hard failures |
| 4. Web-server awareness | Skip, don't UNKNOWN, what does not apply |
| 5. **PASS means measured** | Applies to the DATA SOURCE, not just the wiring. A guard that makes a reader *safe* does not make it *honest*. Violated 15 times |
| 6. Labels name the healthy property | "Tool categories fully disabled — Pass" contradicts itself. **Corollary:** any surface that lists a problem without a Pass/Warn/Fail column beside it must prefix `Diagnostic_Result::problem_badge_html()`, or a list of problems reads as a list of reassurances |
| 7. **Measure what a real request sees** | Not the value this request already repaired, inflated or defaulted. Found 7 separate instances |

Rule 7 is the one that keeps recurring. B1 measured a per-request cache array its own write had just filled; G2 read `memory_limit` after wp-admin raised it to 256M; D1 defaulted a corrupt allowlist to `['*']` where production uses `[]`. Each had passing unit tests, because the tests supplied inputs the production reader never fetched. **Review the reader and the evaluator separately**, and for any check whose evaluator takes collected data, write the "collection returned nothing" test explicitly.

**Verifying a change.** Unit tests alone are not sufficient for this subsystem — see `tests/docker/object-cache/`, which runs the real Redis Object Cache drop-in and stops the container, because the earlier harness used a stub with no runtime cache and therefore certified a check that did not work. `./tests/docker/run-all.sh` runs every harness.

**A5 is a heuristic with stated limits, and it lexes rather than samples.** The stray-output check reads the whole of `wp-config.php` and the active theme's `functions.php` through `token_get_all()` and counts inline HTML at brace depth ZERO. It used to read the last 64 bytes and scan back for `?>`; round 3 widened that to 64 KB so a large amount of trailing junk could still be seen, and in doing so made the check WARN on healthy files — a `?>` inside a hook callback, a string literal or a comment now fell inside the window and read as the end of PHP (round-4 R4DG001, a regression from R3DG001's own fix). Whether a `?>` ends PHP mode is not decidable from the end of a file. Two details are load-bearing: inline HTML inside a function or conditional body is emitted only when that body RUNS, and nothing runs at include time; and `T_CURLY_OPEN`/`T_DOLLAR_OPEN_CURLY_BRACES` are the `{` of a `"{$x}"` interpolation, so a depth counter that skips them reads the inline HTML that follows as top-level output — measured, 29 bytes reported where PHP emits 0. `token_get_all()` is called without `TOKEN_PARSE` so newer syntax lexes instead of throwing, and lexing is skipped entirely when the source contains no `?>` — airtight, because inline HTML after index 0 requires one, and it keeps whole-file lexing (~28× the file size in peak memory, an UNCATCHABLE fatal if it blows) off 17 of 34 real themes. **Two limits are deliberate and recorded** (round-5 R5DG001/R5DG002): a top-level braced body that DOES run emits its HTML and is reported clean, and top-level alternative syntax (`if: … endif;`) and `goto` are counted as emitted when they may not be. The obvious depth tracker for the second was implemented and measured — an `if: elseif: else: endif;` chain opens three times and closes once, so on a fixture emitting 28 bytes it reported 0 and PASSED, turning a false WARN into a false PASS. A 65-theme scan found zero real false positives, because the idiom is always inside a function body. **Do not attempt a fifth rewrite of this check without measuring against real emission first.**

**Ask what would fail if the guard were deleted.** Two adversarial audits (spec §13, §14) found the same pattern twice, and neither time was it in the check logic: §13's only HIGH existed because a stub drop-in could not exhibit the fault it was built to prove, and §14's largest finding was a security gate — `Check_Multisite::may_read_network()` — that could be deleted outright with all 2413 tests staying green, because `is_multisite()` was hardcoded `false` in the stubs and the gate was never reached. When you add a guard here, mutate it and run the suite; if nothing fails, the guard is decoration. `is_multisite()`, `is_super_admin()` and `wp_cache_supports()` are now stub-controllable for exactly this reason.

**Two audits, two near-misses.** In both rounds the single most promising finding would have caused a regression if acted on directly — most recently a proposal to gate B1's `wp_cache_flush_runtime()` on `wp_using_ext_object_cache()`, which would have reintroduced the C1 false PASS on every site without a drop-in, because `get_transient()` reads `wp_cache_get()` before touching MySQL. Verify findings against real core source before changing anything.

**A9 is the only check whose subject is outside PHP, and it works by differential.** A CDN or WAF rule that blocks the AI client's user agent refuses the request *before* WordPress runs, so the audit log, the change log and any request logging inside the site all show the same thing — a flawless OAuth handshake followed by silence — while the client reports only "Authorization with the MCP server failed". Measured 2026-08-11: the claude.ai connector performs discovery, DCR and the token exchange with a generic HTTP client, then sends **every** MCP call (`initialize`, `tools/list`, `tools/call`) as `User-Agent: Claude-User`; a Cloudflare AI-bot rule answered that agent `403 Your request was blocked.` while answering the plugin's own agent with the ordinary 401 challenge. A9 therefore sends the same unauthenticated GET to its own MCP URL twice, differing only in `User-Agent`, and compares. **The differential is the measurement** — a 403 alone could be a firewalled loopback or a captive portal, so the control must come back as OUR 401 carrying the `resource_metadata` challenge built from this site's `home_url` (A1's constraint 2, restated) or the verdict is UNKNOWN. Three stated limits, deliberately not engineered away: PASS means "no user-agent block detected", never "Claude can reach this site" (an IP/ASN block still lets the probe through, since it leaves from the origin's own address); a host that resolves its own domain locally never crosses the edge and will PASS while a real client is blocked; and the agent strings are a maintained heuristic, not a specification. Each guard was mutated and the suite fails — see `DiagnosticsEdgeBlockTest`.

**Surfaces:** `Diagnostics_Notices` (one aggregated banner, plugin screens only; A7/A8 are re-evaluated live on every render, not read from the cache), `Diagnostics_Site_Health` (Status tests + the copyable Info panel — fields default to `private`, see `COPY_SAFE_IDS`, because that panel is pasted into public forums and carried usernames and token names), and the dashboard card. Three of these list problems as `label — detail` with no status column, so all three prefix the severity badge from `Diagnostic_Result::problem_badge_html()` — the card's issue list, the banner and the Site Health group body. The word, the colour and the escaping live in the value object precisely so a fourth surface cannot ship a fourth spelling; the card's full results table and the copyable block already carry their own status column and do not use it.

**The live A7/A8 path needs its own isolation boundary.** `Diagnostics_Notices::current_blockers()` re-evaluates A7/A8 on every render (§7.1), and it called `Check_Notices::run()` with no `try`/`catch` while `Diagnostics::run()` wrapped every check in exactly that boundary a hundred lines away — so a throw took the plugin's admin screens down instead of degrading one banner. The throw surface is ordinary: `permalinks_are_plain()` reads `option_permalink_structure`, `oauth_transport_problem()` reads `home_url()`/`is_ssl()`, and `home_url` is filtered as a matter of course by multilingual, CDN and staging plugins. `live_results()` now catches `Throwable` **and** rejects a non-array return (a filter can break this by returning the wrong type without throwing, and `foreach` over a non-iterable is itself a fatal on PHP 8). On failure it falls through to the **cached** a7/a8 verdict, not to "no blockers" — that verdict was really measured, only possibly stale, and it still warns an admin whose permalinks are broken. Its `$runner` parameter exists so the catch is reachable from a test; anything unreachable is decoration.

**Gate reading, not just collecting.** `Check_Multisite::may_read_network()` stops a sub-site admin *running* I1, but the report is cached in `wp_N_options` (`get_option()` is per-site), so a super admin who views a plugin page **while on a sub-site** leaves sibling domains in that site's own options — where its administrator renders them. Reproduced on a 32-site network: the dashboard card emitted them to a non-super-admin. `Diagnostics::cached()` is the single choke point every surface reads through, so the redaction lives there and swaps I1 for the same "not permitted" UNKNOWN the collection gate returns; `class-check-multisite.php` loads only under `is_multisite()`, so single sites pay nothing. **It is applied on the way out and never written back into `self::$results`** — poisoning that memo would blank the report for a super admin whose own `run()` filled it in the same request, which is why a test reads twice and then switches user. Same lesson as the §13/§14 audits: when adding a guard here, mutate it and run the suite.

**Options:** `easy_mcp_ai_diagnostics_last_run` (cached report, non-autoloaded) and `easy_mcp_ai_header_probe_secret` (one-shot, arms A1's public probe route for one request). Both are purged on uninstall.

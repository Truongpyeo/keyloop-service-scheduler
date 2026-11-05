# AI collaboration log
Tool: Claude Code + Laravel Boost (MCP). For each step: what I asked, what the AI produced, what I changed and why.
## Setup
- Installed Laravel Boost: it added CLAUDE.md, AGENTS.md, .mcp.json and skills (laravel-best-practices, testing-best-practices) so Claude Code had the correct Laravel 13 documentation before writing any code.

## Step 1 – System design
- Prompt: gave all the architecture decisions (tables, API, overlap rule, FOR UPDATE locking, idempotency) and asked the AI to write DESIGN.md, no code yet.
- The AI filled in 6 details I had not decided. I kept 4 (lock ordering by id, idempotent cancel, 503 health check, out of scope).
- I corrected 3 points:
  - The AI wrote PHPUnit while the project uses Pest → standardised on Pest.
  - Idempotency missed the case of the same key with a different payload → added a body hash, returning 422.
  - The opening-hours check only looked at the start time and did not mention the timezone → check ends_at too, converted to the dealership's timezone.
- How I checked: read each section myself, used a second AI assistant (Claude chat) to cross-review the design, and checked that the Mermaid diagrams render on GitHub.
## Step 2 – Design review
- Prompt: asked the AI to act as a senior engineer and list 8 weaknesses of DESIGN.md, without editing the file.
- The AI gave 8 points. The most important: under MySQL REPEATABLE READ, the re-check SELECT can read an old snapshot → double bookings are still possible. I checked the MySQL documentation, confirmed it → switched to a locking read.
- Accepted: snapshot read (1), vehicle double-booking (3), normalising times before hashing for idempotency, retry on deadlock + return 503 (2), mandatory timezone offset on times (4), availability computed in memory (6), closing-time boundary tests (7).
- Dropped (recorded in Out of scope): is_active, leave/maintenance schedules, seed validation, DST transition tests, tuning innodb_lock_wait_timeout. Reason: short deadline, priority on guaranteeing no double bookings.
- Note: the AI suggested "a test with two interleaved transactions", but Pest tests on SQLite cannot reproduce MySQL locking → I chose to verify with a script sending parallel requests to the real MySQL.
- Additional decision: own Docker setup (no Sail) so reviewers need only Docker; a Blade frontend last, if time allowed (later dropped, see Step 7).

## Step 3 – Docker environment
- **Mistake the AI missed:** the AI reported 6/6 tests passing, but only ran them on Herd. When I ran them in Docker, the health test failed (200 instead of 503). I found the cause: docker-compose's DB_CONNECTION=mysql environment variable beat the <env> in phpunit.xml (missing force="true"), so tests in the container ran against the real MySQL and RefreshDatabase could wipe the app's data. Fixed with force="true" and a guard test asserting that tests always run on SQLite in-memory.
- Lesson: always run tests in the exact environment the reviewer will use; do not trust a "pass" from a different environment.

- Results in Docker: health 200 with the DB running, 503 with MySQL stopped; 7/7 tests pass in the container (after the force="true" fix).
- Note for the README: reviewers on Windows PowerShell 5.1 must type curl.exe (curl is an alias of Invoke-WebRequest).

## Step 4 – Data model
- Prompt: 8 tables following DESIGN.md, restrict foreign keys, 4 indexes, fixed demo seed data.
- The AI spotted on its own: the immutable_datetime cast does not convert to UTC by itself → noted for step 5 that ->utc() must be called before saving.
- I checked on the real MySQL (SHOW CREATE TABLE): starts_at/ends_at are datetime, 4 composite indexes plus a unique idempotency_key, 6 foreign keys ON DELETE RESTRICT.
- Tests in Docker: 26 passed. The seed does not run again on restart.

## Step 5 – Booking service and API
- Docker check results (the AI ran them, I read the output): 66/66 tests pass; 11 API scenarios pass (201, idempotent 200, 409 no_technician_available, 409 vehicle_already_booked, 422 missing offset, 422 same key with a different body, idempotent cancel, slot free again after cancelling). MySQL stores 09:00+07:00 as 02:00 UTC.
- Logs: every application log line has a request_id; the access lines printed by php artisan serve do not (recorded as a known limitation).

## Step 6 – Concurrency verification
- Prompt: an app:concurrency-test command sending truly parallel requests (Http::pool) to MySQL in Docker, 3 scenarios, overlap check with a SQL self-join independent of the booking code.
- Results of 2 runs (the AI ran them, I read the output and queried MySQL myself): A 1/10 succeeded (1 EV technician), B 3/5 (3 bays), C 1 appointment for 10 requests with the same key; overlapping_pairs = 0; no 503/deadlock.
- Further confirmation on real data: times stored in UTC; the rule of picking the technician with the fewest appointments, ties to the lowest id.

## Step 7 – Docs & demo
- Prompt: an app:demo command, an OpenAPI 3.1 spec, a sample request body and a full English README for reviewers on Windows, macOS or Linux, running on any date after today.
- app:demo works out tomorrow in the dealership's timezone, picks the first free EV slot from the availability response, and runs availability → 201 → idempotent 200 → 409 → 422 → cancel 200 → slot free again over the REST API. It uses its own demo customer and vehicles and a fresh Idempotency-Key per run, so it can be repeated and never touches the seeded or concurrency-test data. Two consecutive runs in Docker: all 7 steps as expected, exit code 0.
- docs/openapi.yaml covers the 5 endpoints, request/response schemas, 200/201/404/409/422/503, the Idempotency-Key, X-Request-Id and Retry-After headers, with examples; all $ref entries resolve.
- README curl examples use a date **two days ahead** (a reviewer behind Vietnam's timezone could otherwise send a time that is already past in Asia/Ho_Chi_Minh) and send the JSON body **from a file** (docs/requests/book-appointment.json with a <FUTURE-DATE> placeholder: `sed … | curl --data @-` in bash, `Set-Content -Encoding ascii` + `curl.exe --data "@file"` in PowerShell), so no shell-specific quote escaping is needed. Both variants were run against Docker: 201, 200 replay, 409 without the key, X-Request-Id echoed, cancel 200.
- Dropped the Blade demo page; DESIGN.md now lists any Web UI as out of scope and describes app:demo as the CLI client.

## Lock-removal experiment
- Goal: prove the concurrency check can fail. I had the AI temporarily remove lockForUpdate() from the technician, bay and vehicle locks and from the overlap re-check in BookingService (the Idempotency-Key lookup kept its lock), rebuild, and run app:concurrency-test three times.
- Without the locks, every run found real double bookings (expected: A 1/0/9, B 3/0/2):

  | Run | A: 201 / 409, rows | B: 201 / 409, rows | C: rows for the key | Double booking |
  |---|---|---|---|---|
  | 1 | 4 / 6, 4 rows | 5 / 0, 5 rows | 1 | YES |
  | 2 | 3 / 7, 3 rows | 5 / 0, 5 rows | 1 | YES |
  | 3 | 5 / 5, 5 rows | 4 / 1, 4 rows | 1 | YES |

- C itself stayed correct (one appointment, every response pointing to it) thanks to the unique index and the locked key lookup; it was marked FAIL only because the overlap check scans the whole table and found A's and B's double bookings. No 503 or deadlock appeared: without locks the requests simply all saw the slot as free and all inserted.
- An earlier identical run gave A 3, 5 and 4 successes, so the failure is reproducible, with varying counts as expected from a race.
- After `git checkout` of BookingService.php (git diff empty, 5 lockForUpdate() calls back in the running image) and a rebuild, all three scenarios passed again (A 1/0/9, B 3/0/2, C 1/9/0, no double booking, exit code 0).

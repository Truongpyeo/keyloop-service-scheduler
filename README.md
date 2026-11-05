# Unified Service Scheduler

Keyloop Technical Assessment — **Scenario A: Unified Service Scheduler**, backend only.

A REST API that books a service appointment for a specific vehicle, service type and dealership at a desired time. Before confirming, it checks that a **service bay** and a **technician with the required skill** are both free for the **entire duration** of the service, then persists an appointment linked to the customer, vehicle, technician and bay. The frontend is replaced by an [OpenAPI spec](docs/openapi.yaml), sample requests and an end-to-end demo command.

The full design (architecture, data model, booking flow, concurrency, observability, trade-offs) is in **[docs/DESIGN.md](docs/DESIGN.md)**.

## Tech stack

| Component | Version |
|---|---|
| PHP | 8.4 (`php:8.4-cli` image) |
| Laravel | 13 (13.39) |
| MySQL | 8.4 (InnoDB) |
| Tests | Pest 5 (on PHPUnit 13), SQLite in-memory |
| Runtime | Docker Compose: own Dockerfile, no Laravel Sail |

## Quick start

The only requirement is **Docker** (Docker Desktop on Windows/macOS, Docker Engine + Compose plugin on Linux). No PHP, Composer or MySQL on the host.

```bash
docker compose up --build
```

The first build takes a few minutes. On start the app container creates its `.env`, runs the migrations and seeds a demo dealership (only when the database is empty), then serves the API on **http://localhost:8000**.

In another terminal, check it is ready:

```bash
curl -i http://localhost:8000/api/health
```

Expected: `HTTP/1.1 200 OK` and `{"status":"ok","database":"up"}`. On Windows PowerShell 5.1 use `curl.exe` instead of `curl` (see [Troubleshooting](#troubleshooting)).

To run in the background instead, use `docker compose up --build -d`. To reset everything, including the database volume: `docker compose down -v`.

### Seeded demo data

| Entity | Data |
|---|---|
| Dealership `1` | Keyloop Da Nang, `Asia/Ho_Chi_Minh` (UTC+7), open 08:00–18:00 |
| Service types | `1` Oil change (60 min, skill `general`), `2` Brake inspection (90 min, `brakes`), `3` EV battery check (120 min, `ev`) |
| Technicians | An (`general`), Binh and Chi (`general` + `brakes`), Dung (`general` + `ev`, the only EV technician) |
| Bays | Bay 1, Bay 2, Bay 3 |
| Customers / vehicles | Customer `1` owns vehicles `1` and `2`; customer `2` owns vehicle `3`; customer `3` owns vehicles `4` and `5` |

## Try it

### End-to-end demo

```bash
docker compose exec app php artisan app:demo
```

It works out **tomorrow in the dealership's timezone** by itself (any day you run it), then goes through the REST API, printing each request, HTTP status and a shortened body:

1. `GET` availability for the EV battery check → 200, takes the first free slot
2. `POST` a booking with an `Idempotency-Key` → **201**
3. the same request with the same key → **200**, same appointment id
4. the same slot for another vehicle → **409** `no_technician_available` (only one EV technician)
5. `starts_at` without a UTC offset → **422**
6. `PATCH` cancel → **200**, status `cancelled`
7. `GET` availability again → the slot is free again

It uses its own demo customer and vehicles, so it never touches the seeded vehicles or the concurrency-test data, and it can be run any number of times. Exit code 0 means every step matched.

### Manual requests

[docs/requests/book-appointment.json](docs/requests/book-appointment.json) is a sample body with a `<FUTURE-DATE>` placeholder; `starts_at` must be in the future and keep its offset. The commands below fill in the date two days ahead (safe whatever your own timezone) and send the file, so no JSON quoting is needed on the command line.

**bash / macOS / Linux / Git Bash**

```bash
DATE=$(date -d '+2 days' +%F 2>/dev/null || date -v+2d +%F)
```

```bash
curl -s "http://localhost:8000/api/dealerships/1/availability?service_type_id=3&date=$DATE"
```

```bash
sed "s/<FUTURE-DATE>/$DATE/" docs/requests/book-appointment.json | curl -i -X POST http://localhost:8000/api/appointments -H "Content-Type: application/json" -H "Accept: application/json" -H "Idempotency-Key: readme-$DATE" --data @-
```

```bash
curl -i -H "X-Request-Id: my-trace-1" http://localhost:8000/api/appointments/1
```

```bash
curl -i -X PATCH -H "Accept: application/json" http://localhost:8000/api/appointments/1/cancel
```

**Windows PowerShell** (5.1 or 7)

```powershell
$date = (Get-Date).AddDays(2).ToString('yyyy-MM-dd')
```

```powershell
curl.exe -s "http://localhost:8000/api/dealerships/1/availability?service_type_id=3&date=$date"
```

```powershell
(Get-Content docs/requests/book-appointment.json -Raw) -replace '<FUTURE-DATE>', $date | Set-Content -Encoding ascii "$env:TEMP\booking.json"
```

```powershell
curl.exe -i -X POST http://localhost:8000/api/appointments -H "Content-Type: application/json" -H "Accept: application/json" -H "Idempotency-Key: readme-$date" --data "@$env:TEMP\booking.json"
```

```powershell
curl.exe -i -H "X-Request-Id: my-trace-1" http://localhost:8000/api/appointments/1
```

```powershell
curl.exe -i -X PATCH -H "Accept: application/json" http://localhost:8000/api/appointments/1/cancel
```

Replace `1` in the last two commands with the `data.id` returned by the booking. Sending the booking again with the same `Idempotency-Key` returns 200 with the same appointment; sending it again without the header returns 409.

## Running tests

```bash
docker compose exec app php artisan test --compact
```

The suite (Pest feature tests: booking rules, every error code, idempotency, availability, cancellation, models, seeder, error rendering) runs on **SQLite in-memory**, not on the app's MySQL:

- **Why:** fast, isolated runs that can never wipe the demo database (tests refresh the schema).
- **How:** `phpunit.xml` forces `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:` with `force="true"` *and* matching `<server>` entries. Docker Compose sets real `DB_*` environment variables, `<env>` alone does not override real variables, and Laravel reads `$_SERVER` before `getenv()`, so both are needed.
- **Guard:** `tests/TestCase.php` refuses to boot the test application unless the default connection is in-memory SQLite, so a misconfiguration fails immediately instead of touching MySQL.

## Concurrency verification

SQLite ignores `SELECT ... FOR UPDATE` and locks the whole database file, so no unit test can prove that two simultaneous requests cannot double-book. That is verified against **real MySQL** with real parallel HTTP requests:

```bash
docker compose exec app php artisan app:concurrency-test --force
```

`--force` is required because the command first deletes dealership 1's appointments on the test date (default: 7 days ahead, override with `--date=YYYY-MM-DD`); it only runs in the local environment. It sends each scenario's requests at once with `Http::pool` to the server, which runs 4 PHP workers:

| Scenario | Requests | Expected |
|---|---|---|
| A. One EV technician | 10 different vehicles book the EV battery check at the same time | exactly 1 × 201, 9 × 409 `no_technician_available` |
| B. Three bays | the 5 seeded vehicles book an oil change at the same time (4 technicians, 3 bays) | exactly 3 × 201, 2 × 409 `no_bay_available` |
| C. Same Idempotency-Key | 10 identical requests with one key | exactly 1 appointment; every response is 201 or 200 with the same id |

After each scenario, a SQL self-join over `appointments` checks that no two confirmed appointments overlap on the same technician, bay or vehicle. The command prints a summary table and exits 0 only if all three pass. As a sanity check, temporarily removing the `lockForUpdate()` calls in `BookingService` makes scenarios A and B fail with real double bookings.

## API summary

| Method | Path | Purpose | Success | Errors |
|---|---|---|---|---|
| GET | `/api/health` | Liveness + database connectivity | 200 | 503 |
| GET | `/api/dealerships/{id}/availability?service_type_id=&date=YYYY-MM-DD` | Free 30-minute start times on a local date, in local time | 200 | 404, 422, 503 |
| POST | `/api/appointments` | Book; optional `Idempotency-Key` header | 201 (200 on replay) | 409, 422, 503 |
| GET | `/api/appointments/{id}` | Appointment with technician, bay, service type, vehicle; times in UTC and local | 200 | 404 |
| PATCH | `/api/appointments/{id}/cancel` | Cancel (idempotent) | 200 | 404 |

Every response carries `X-Request-Id`. Full schemas, examples and error bodies: **[docs/openapi.yaml](docs/openapi.yaml)** (OpenAPI 3.1; paste it into [editor.swagger.io](https://editor.swagger.io) to browse).

## Key design decisions

- **Pessimistic row locks, fixed order.** A booking locks the dealership's skilled technicians, then its bays, then the vehicle (`SELECT ... FOR UPDATE`, ascending id), so concurrent bookings cannot deadlock each other.
- **Locking re-check.** The overlap check runs after acquiring the locks and is itself a locking read: under InnoDB REPEATABLE READ a plain `SELECT` could read a stale snapshot and miss an appointment committed while waiting. The read is bounded to `starts_at > start − 1 day` so it never scans or locks a resource's whole history.
- **Idempotency after the locks.** The `Idempotency-Key` lookup happens inside the transaction, after the locks, so a retry sent while the first request is still in flight waits for it and returns its appointment (200) instead of a false 409. The key is stored with a SHA-256 of the normalized body; reusing a key for a different body is a 422.
- **UTC everywhere.** Stored in UTC; opening hours, the 30-minute grid and "a day" are evaluated in the dealership's timezone; requests must carry an offset.
- **Failures are explicit.** Business conflicts are 409 with a machine-readable `reason`; deadlocks and lock timeouts are retried (3 attempts) and then return 503 with `Retry-After`, as do lost database connections.

## Assumptions and out of scope

Assumptions: 30-minute slots; the same opening hours every day with no overnight shifts; technicians work the dealership's opening hours; every bay can do every service; a vehicle cannot have two overlapping appointments.

Out of scope: authentication and authorization, a production frontend, technician leave and bay maintenance windows, deactivating technicians or bays, rescheduling, payments and notifications. See [DESIGN.md §1 and §11](docs/DESIGN.md) for the full lists.

## Troubleshooting

- **Port 8000 is already in use.** Change the host side of the mapping in `docker-compose.yml` (for example `"8080:8000"`) and use that port in the URLs. MySQL's port 3306 is **not** published to the host, so a local MySQL server does not conflict; to inspect the database use `docker compose exec mysql mysql -uscheduler -psecret scheduler`.
- **`curl` fails in Windows PowerShell 5.1** (it asks for `Uri:` or reports "Cannot find drive"). There `curl` is an alias for `Invoke-WebRequest`; call `curl.exe`. Send JSON bodies from a file with `--data "@file.json"` (quoted, because `@` is special in PowerShell) rather than inline, which avoids quote-escaping problems.
- **Logs.** `docker compose logs app` shows one JSON line per application event (`booking.succeeded`, `booking.failed` with a `reason`, `request.completed`, ...), each with a `request_id`. The plain-text lines such as `2026-11-05 10:00:00 /api/appointments ... ~ 0.08ms` come from PHP's built-in server (`php artisan serve`); they carry no `request_id` and their timing is not the request duration (use `duration_ms` in `request.completed`). The built-in server is for the demo only; production would run nginx + php-fpm or Laravel Octane.
- **App container exits on start.** Check `docker compose logs app`. A stale database from an older version can be reset with `docker compose down -v`.

## AI Collaboration Narrative

**Tools.** Claude Code as the main coding agent, with Laravel Boost (MCP) so it worked from Laravel 13 documentation and project conventions. I also used a second AI assistant to review the design independently. The step-by-step record is in [docs/ai-log.md](docs/ai-log.md).

### Strategy: decide first, then delegate
- I made the architecture decisions myself (data model, overlap rule, row locking with a re-check inside the transaction, idempotency, UTC storage) and gave them to the agent as explicit requirements. The agent turned them into the design document and code.
- I worked in small steps (design → Docker → data model → booking service → concurrency check → docs). Each step ended with commands I could run to verify it, and I committed only after it passed.
- Before writing code, I asked the agent to act as a senior reviewer and attack its own design. I accepted the findings that protect correctness and moved the rest to Out of scope.

### How I verified the AI's output
1. **Reading the diff for specific invariants**, not just "does it run": locking read on the overlap query, locks taken in id order, `->utc()` before saving, transaction retries for deadlocks.
2. **Running everything in the reviewer's environment.** The agent reported all tests green on my local PHP. In Docker, one test failed. I traced it: Docker's `DB_CONNECTION=mysql` overrode `phpunit.xml` (missing `force="true"`), so tests were running against the real MySQL and `RefreshDatabase` could wipe the app's data. Fixed with `force="true"` and a guard test that fails if tests are not on SQLite in-memory.
3. **Checking claims against primary sources.** The agent warned that under MySQL REPEATABLE READ a plain SELECT can read an old snapshot and miss another request's booking. I confirmed this in the MySQL documentation before changing the re-check to a locking read.
4. **Checking at the database level.** `SHOW CREATE TABLE` for column types, indexes and foreign keys; confirmed 09:00+07:00 is stored as 02:00 UTC; a self-join query, independent of the booking code, finds any overlapping appointments.
5. **Not trusting unit tests for concurrency.** SQLite ignores `FOR UPDATE`, so I had the agent build `app:concurrency-test`, which sends parallel HTTP requests to MySQL. Two runs gave identical results: one EV booking out of 10, three out of five for three bays, one appointment for ten requests with the same Idempotency-Key, zero overlaps.
6. **Proving the check can fail.** With the locks temporarily removed, the concurrency test detected double bookings; with the locks restored, it passed again.

### Where I corrected or narrowed the AI
- It documented PHPUnit while the project uses Pest.
- Idempotency did not handle the same key with a different payload (now 422), and the payload hash did not normalise times to UTC.
- The opening-hours check ignored the end time and the dealership timezone.
- I cut scope it proposed (maintenance schedules, DST tests, seed validation) to keep the solution focused.
- I made the docs match the code (lock order) and made README examples work on any date and any shell.

### Where the AI improved my plan
- Checking the Idempotency-Key *after* taking the locks, so a concurrent duplicate returns 200 instead of a false 409.
- Running the dev server with 4 workers; with one worker, parallel requests would be serialised and the concurrency test would prove nothing.
- A TCP health check for MySQL, and a warning that Laravel's datetime cast does not convert to UTC by itself.

### Final quality
75 Pest tests for the business rules, the concurrency command on real MySQL, a clean-clone run with Docker only, and a final review of every file by me. I can explain every line in this repository.

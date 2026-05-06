# Full Application Report: Piler Archive Extractor

## 1. Executive Summary

This application is a PHP MVP named **Piler Archive Extractor API**. Its purpose is to act as a bridge between archived email data from Piler and a future Listmonk contact/campaign workflow.

The app currently provides:

- A REST API for reading archived email metadata.
- Attachment metadata lookup by email.
- SQL dump parsing for local Piler archive testing.
- Contact extraction from email sender, recipient, and CC fields.
- Contact cleaning, validation, system-address filtering, and deduplication.
- Placeholder contact classification.
- Placeholder Listmonk synchronization.
- A browser dashboard for exploring archived emails and contact candidates.
- A CLI command for running the contact extraction pipeline.

The current implementation is best described as an **MVP for ingestion, exploration, and contact-preparation**, not a production-ready Listmonk synchronization service yet.

## 2. Product Purpose

Archived email systems contain useful contact data, but the data is difficult to reuse directly because it is buried in message metadata and often includes duplicates, system addresses, invalid addresses, and operational noise.

This app solves the first phase of that problem:

1. Read archived email metadata.
2. Expose email records through simple API endpoints.
3. Extract candidate email addresses.
4. Clean and deduplicate those addresses.
5. Classify contacts with simple placeholder rules.
6. Prepare the structure needed for a future Listmonk sync.

In one sentence:

> The app extracts useful contact data from a Piler email archive and prepares it for future campaign/contact management in Listmonk.

## 3. Current Status

The app is functional as a local MVP.

Working today:

- Local PHP server bootstraps successfully.
- Health endpoint responds.
- Email listing works against the cached SQL dump.
- Email search works against the cached SQL dump.
- Attachment metadata endpoint works.
- Contact pipeline works in mock mode.
- Full-dump pipeline has previously completed and logged results.
- Dashboard loads and calls the API.

Not complete yet:

- Real Piler database queries are still TODO placeholders.
- Listmonk API sync is still TODO.
- Contact classification is rule-based placeholder logic.
- No automated test suite exists yet.
- No authentication or authorization is implemented.
- The app is not production-hardened.

## 4. Technology Stack

Backend:

- PHP 8.1+ required by `composer.json`.
- Fat-Free Framework via `bcosca/fatfree`.
- Installed direct dependency observed: `bcosca/fatfree 3.9.2`.
- Composer PSR-4 autoloading with namespace `App\\`.

Frontend:

- Static HTML, CSS, and vanilla JavaScript.
- Served from `public/gui`.
- Uses browser `fetch()` for API calls.
- Uses Google Fonts and Material Symbols from external CDN URLs.

Data and storage:

- Mock in-memory data for MVP fallback.
- Optional SQL dump source: `piler_backup.sql`.
- JSON cache: `storage/cache/piler_dump_cache.json`.
- Sync history log: `storage/logs/sync_history.log`.
- Future MySQL/PDO configuration exists but repository queries are not implemented.

Tooling:

- Composer script: `composer serve`.
- No configured PHPUnit, Pest, static analysis, formatter, or CI workflow is present.

## 5. Repository Structure

Important directories and files:

```text
app/
  Controllers/
    AttachmentController.php
    EmailController.php
    HealthController.php
  Middlewares/
    ErrorHandlerMiddleware.php
  Models/
    AppException.php
    Attachment.php
    Email.php
  Repositories/
    AttachmentRepository.php
    EmailRepository.php
  Services/
    ClassificationService.php
    ContactCleaningService.php
    ContactExtractionService.php
    EmailService.php
    ListmonkSyncService.php
    PilerDumpDataSource.php
    ResponseHelper.php
    SyncHistoryService.php
cli/
  sync_contacts.php
config/
  app.php
  db.php
  routes.php
public/
  index.php
  gui/
    index.html
    assets/css/dashboard.css
    assets/js/dashboard.js
storage/
  cache/
  logs/
tests/
```

## 6. Runtime Entry Points

### Web Entry Point

`public/index.php` is the HTTP entry point. It:

1. Loads Composer autoload.
2. Creates the Fat-Free Framework instance.
3. Loads app configuration.
4. Loads database configuration.
5. Registers routes and dependencies.
6. Runs the app.

### CLI Entry Point

`cli/sync_contacts.php` runs the contact pipeline from the command line. It:

1. Loads app and database configuration.
2. Chooses the active data source.
3. Builds the repository and service graph.
4. Runs the contact pipeline.
5. Prints a summary.
6. Appends a JSON line to `storage/logs/sync_history.log`.

## 7. Configuration

The app loads configuration from `.env` through `config/app.php`.

Main app settings:

- `APP_NAME`
- `APP_ENV`
- `APP_DEBUG`
- `APP_TIMEZONE`
- `APP_URL`

Database settings:

- `USE_REAL_DB`
- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`

SQL dump settings:

- `USE_SQL_DUMP`
- `SQL_DUMP_PATH`
- `SQL_DUMP_MAX_EMAILS`
- `SQL_DUMP_MEMORY_LIMIT`

Important configuration behavior:

- The local `.env` file is gitignored.
- `piler_backup.sql` is gitignored.
- SQL dump cache JSON files are gitignored.
- The code reads configuration from `$_ENV`.
- In this PHP CLI environment, shell-provided environment variables were not enough to override the local `.env` during the CLI run, because the app explicitly reloads `.env` and uses `$_ENV`.

## 8. Data Source Modes

The repository layer supports three effective modes.

### 8.1 Mock Mode

If there is no PDO connection and no SQL dump data source, `EmailRepository` and `AttachmentRepository` return hardcoded MVP records.

This mode is useful for:

- Demos.
- Fast local checks.
- Testing the contact pipeline without a large archive.

### 8.2 SQL Dump Mode

If SQL dump mode is enabled and `piler_backup.sql` exists, the app uses `PilerDumpDataSource`.

The data source:

- Parses `metadata` inserts for email metadata.
- Parses `rcpt` inserts for recipients.
- Parses `attachment` inserts for attachment metadata.
- Builds an in-memory normalized structure.
- Caches the result in `storage/cache/piler_dump_cache.json`.
- Invalidates the cache when the dump modification time or max-email setting changes.

Current local artifacts:

- `piler_backup.sql`: about 136 MB.
- `storage/cache/piler_dump_cache.json`: about 55 MB.
- Cached emails: 226,260.
- Email IDs with attachments: 36,390.
- Attachment metadata rows: 105,599.

### 8.3 Real Database Mode

`config/db.php` can initialize a PDO connection when `USE_REAL_DB=true`.

However, the real Piler query methods are placeholders:

- `EmailRepository::findAllFromPiler()` returns an empty array.
- `EmailRepository::findByIdFromPiler()` returns null.
- `EmailRepository::searchFromPiler()` returns an empty array.
- `AttachmentRepository::findByEmailIdFromPiler()` returns an empty array.

This means enabling `USE_REAL_DB=true` today would not produce useful email results until the real Piler schema is mapped.

## 9. Backend Architecture

The architecture is layered.

### API Layer

Controllers accept Fat-Free requests and return JSON through `ResponseHelper`.

Controllers:

- `HealthController`
- `EmailController`
- `AttachmentController`

### Service Layer

Services contain the application behavior:

- `EmailService`: orchestrates repositories and the contact pipeline.
- `ContactExtractionService`: extracts email addresses with regex.
- `ContactCleaningService`: normalizes, validates, filters, and deduplicates contacts.
- `ClassificationService`: assigns placeholder contact categories.
- `ListmonkSyncService`: placeholder for future sync.
- `PilerDumpDataSource`: parses and caches SQL dump content.
- `SyncHistoryService`: appends CLI run summaries.
- `ResponseHelper`: formats success and error payloads.

### Repository Layer

Repositories abstract the data source:

- `EmailRepository`
- `AttachmentRepository`

Each repository chooses between:

1. PDO mode.
2. SQL dump mode.
3. Mock mode.

### Model Layer

Models normalize arrays into consistent response shapes:

- `Email`
- `Attachment`
- `AppException`

## 10. API Endpoint Inventory

### `GET /`

Purpose:

- Welcome endpoint.

Response shape:

```json
{
  "success": true,
  "message": "Welcome to Piler Archive Extractor API",
  "version": "mvp"
}
```

### `GET /gui`

Purpose:

- Serves the static dashboard HTML.

Behavior:

- Reads `public/gui/index.html`.
- Returns a 404 JSON error if the file is missing.

### `GET /health`

Purpose:

- Health check.

Response shape:

```json
{
  "success": true,
  "status": "ok"
}
```

### `GET /emails?limit=10&offset=0`

Purpose:

- Returns a paginated list of email metadata.

Parameters:

- `limit`: default 10, minimum 1, maximum 100.
- `offset`: default 0, minimum 0, maximum 5,000,000.

Response includes:

- `data`: email records.
- `pagination.limit`
- `pagination.offset`
- `pagination.count`

### `GET /emails/@id`

Purpose:

- Returns one email by ID.

Validation:

- IDs less than or equal to zero return 400.
- Missing email returns 404.

### `GET /emails/search?q=keyword`

Purpose:

- Searches email metadata.

Validation:

- Empty `q` returns 422.

Current search fields:

- `subject`
- `from`
- `to`

Not currently searched:

- Email body.
- Attachment filenames.
- CC.

### `GET /emails/@id/attachments`

Purpose:

- Returns attachment metadata for one email.

Validation:

- Invalid ID returns 400.
- Unknown email returns 404.

Response includes:

- `email_id`
- `count`
- `data`

## 11. Data Models

### Email

Normalized email shape:

```json
{
  "id": 1,
  "subject": "Message subject",
  "from": "sender@example.com",
  "to": ["recipient@example.com"],
  "cc": [],
  "date": "2026-02-10 09:30:00",
  "body_preview": "Short preview"
}
```

SQL dump mode currently leaves `body_preview` empty.

### Attachment

Normalized attachment shape:

```json
{
  "id": 101,
  "email_id": 1,
  "filename": "document.pdf",
  "size": 203948,
  "type": "application/pdf"
}
```

The app currently exposes attachment metadata only. It does not download or stream attachment files.

## 12. Contact Pipeline

The contact pipeline runs in `EmailService::runContactPipeline()`.

Pipeline steps:

1. Load all emails for sync.
2. Extract addresses from `from`, `to`, and `cc`.
3. Lowercase and trim every address.
4. Validate addresses with PHP `FILTER_VALIDATE_EMAIL`.
5. Ignore system addresses.
6. Deduplicate contacts.
7. Classify contacts.
8. Prepare a placeholder Listmonk payload.
9. Return summary stats and result arrays.

System addresses filtered:

- `noreply`
- `no-reply`
- `do-not-reply`
- `mailer-daemon`
- `postmaster`

Mock-mode pipeline verification result:

```json
{
  "emails_processed": 6,
  "stats": {
    "total_extracted_addresses": 24,
    "valid_contacts": 12,
    "duplicates_removed": 8,
    "ignored_invalid_or_system_addresses": 4
  },
  "preview_count": 12
}
```

Latest full-dump sync history entry:

```json
{
  "emails_processed": 226260,
  "total_extracted_addresses": 489379,
  "valid_contacts": 30882,
  "duplicates_removed": 427884,
  "ignored_invalid_or_system_addresses": 30613
}
```

Interpretation:

- The archive contains many repeated operational recipients.
- Deduplication removes a very large portion of extracted addresses.
- The final cleaned contact set is much smaller than the raw extracted-address count.

## 13. Classification

`ClassificationService` is intentionally simple.

Rules:

- Domains ending in `.edu` or `.tn` -> `education-or-local-domain`.
- Domains containing `gov` -> `public-sector`.
- Everything else -> `business-or-general`.

The result includes:

```json
{
  "email": "contact@example.com",
  "category": "business-or-general",
  "model": "placeholder"
}
```

This is useful for proving the pipeline shape, but it is not a real NLP, ML, or business-grade classification model.

## 14. Listmonk Integration

`ListmonkSyncService` exists but is not implemented.

Current behavior:

- `preparePayload()` returns contacts unchanged.
- `sync()` returns:

```json
{
  "status": "not_implemented",
  "synced": 0,
  "failed": 0
}
```

Needed for production:

- Listmonk base URL configuration.
- API credentials.
- Subscriber payload mapping.
- List assignment.
- Batch sync.
- Retry policy.
- Duplicate/idempotency handling.
- Failure logging.
- Opt-out and consent rules.

## 15. Dashboard Report

The dashboard is served at `/gui`.

Main capabilities:

- Health check button.
- Root endpoint ping.
- Paginated email loading.
- Load all rows workflow.
- Server-side search through `/emails/search`.
- Local filtering over loaded rows.
- Sortable email table.
- Email inspector.
- JSON detail tab.
- Attachment metadata tab.
- Contacts tab with client-side extraction and deduplication.
- Copy contacts button.
- Request trace panel.
- Toast notifications.

Dashboard state is held in a global JavaScript `state` object.

The frontend repeats some backend contact logic:

- Email extraction regex.
- System-address filtering.
- Contact deduplication.

This makes the dashboard responsive, but it also creates a risk that frontend and backend contact rules drift over time.

## 16. Verification Performed

### PHP Syntax Check

Command:

```bash
find app config public cli -name '*.php' -print0 | xargs -0 -n1 php -l
```

Result:

- Passed.
- No syntax errors detected in app, config, public entrypoint, or CLI files.

### Composer Validation

Command:

```bash
composer validate --no-check-publish --strict
```

Result:

- Failed with exit code 2.
- `composer.json` is valid, but Composer reports:
  - Lock file is not up to date with `composer.json`.
  - No license is specified.

### API Smoke Test

Temporary server:

```bash
php -S 127.0.0.1:8087 -t public
```

Smoke-tested endpoints:

- `GET /health`: 200 OK.
- `GET /emails?limit=3&offset=0`: 200 OK, returned 3 cached SQL dump emails.
- `GET /emails/search?q=campaign`: 200 OK, returned count 62.
- `GET /emails/1/attachments`: 200 OK, returned count 0.

Observation:

- The response bodies are JSON.
- The observed response header was `Content-type: text/html; charset=UTF-8`, not `application/json`, even though `ResponseHelper` attempts to set JSON content type through Fat-Free headers.

### Cache Inspection

Default PHP memory limit was not enough to decode the 55 MB cache file.

This failed under 128 MB:

```text
Allowed memory size of 134217728 bytes exhausted
```

This succeeded with a larger memory limit:

```bash
php -d memory_limit=1024M ...
```

Result:

```json
{
  "emails": 226260,
  "email_ids_with_attachments": 36390,
  "attachments": 105599,
  "max_emails": 0
}
```

## 17. Strengths

- Clear layered architecture.
- Small, understandable codebase.
- Good separation between controllers, services, repositories, and models.
- SQL dump mode makes demo/testing possible without a live Piler database.
- Cache invalidation uses source modification time and max-email setting.
- Contact extraction and cleaning are simple and explainable.
- CLI pipeline gives useful operational summaries.
- Dashboard provides a practical way to inspect data and demonstrate the app.
- Existing README and meeting tutorial are helpful for presentation.

## 18. Risks and Gaps

### 18.1 Real Database Mode Is Not Functional Yet

The PDO connection setup exists, but the repository methods for real Piler data return empty results. This is the biggest backend implementation gap.

### 18.2 Listmonk Sync Is Not Implemented

The integration service is a placeholder. The app prepares contacts but does not send them to Listmonk.

### 18.3 No Authentication

Archived emails and contact data are sensitive. The current API and dashboard have no login, API key, authorization layer, or role model.

### 18.4 JSON Content-Type Header Issue

Smoke testing showed JSON bodies returned with a `text/html` content type. API clients may still parse the body, but the header should be fixed before integration work.

### 18.5 Heavy Cache Loading

The SQL dump cache is loaded as one large JSON document. This is acceptable for MVP demos, but it is memory-heavy and not scalable for production.

### 18.6 Search Is In-Memory

Search over SQL dump data scans loaded arrays. It has no index and only searches subject, sender, and recipients.

### 18.7 Environment Precedence Is Awkward

The app reads `$_ENV` after loading `.env`. In this setup, shell overrides did not easily override `.env`. This makes testing alternate modes less ergonomic.

### 18.8 No Automated Tests

The `tests` directory only contains `.gitkeep`. Core services are small and testable, but no tests exist yet.

### 18.9 Frontend and Backend Contact Logic Can Drift

The dashboard duplicates backend extraction/cleaning behavior in JavaScript. If backend rules change, the dashboard may show different counts unless both sides are updated.

### 18.10 Piler Dump Parsing Depends on Table Order

`PilerDumpDataSource` maps fields by position in `metadata`, `rcpt`, and `attachment` inserts. It will need adjustment if the dump schema/order differs.

## 19. Recommended Next Steps

### Priority 1: Stabilize the MVP

1. Fix JSON response headers by using direct PHP `header('Content-Type: application/json; charset=utf-8')` or the correct Fat-Free response API.
2. Update `composer.lock` or align `composer.json` with the lock file.
3. Add a license field to `composer.json`.
4. Add unit tests for:
   - `ContactExtractionService`
   - `ContactCleaningService`
   - `ClassificationService`
   - `EmailRepository` mock mode
5. Add API integration tests for:
   - `/health`
   - `/emails`
   - `/emails/@id`
   - `/emails/search`
   - `/emails/@id/attachments`

### Priority 2: Make Data Access Production-Ready

1. Inspect the real Piler schema.
2. Implement PDO queries in `EmailRepository`.
3. Implement PDO queries in `AttachmentRepository`.
4. Add indexes or search strategy for subject, sender, recipients, and optionally body content.
5. Replace large JSON cache with a more queryable local store if SQL dump mode remains important, such as SQLite or indexed NDJSON.

### Priority 3: Build the Listmonk Integration

1. Add Listmonk configuration values.
2. Implement authenticated HTTP client calls.
3. Map cleaned contacts into Listmonk subscriber payloads.
4. Add batch processing.
5. Add retry and failure logging.
6. Add dry-run mode.
7. Add idempotency to avoid duplicate subscribers.

### Priority 4: Add Security and Operations

1. Add authentication for the dashboard/API.
2. Add authorization for sensitive routes.
3. Add CORS policy only if needed.
4. Add structured logging.
5. Add rate limiting for search and load-all style actions.
6. Add operational health checks for data source availability.

### Priority 5: Improve the Product Workflow

1. Add export endpoint for cleaned contacts.
2. Add contact review/approval state before Listmonk sync.
3. Add exclusion rules for internal domains, system domains, and blocked lists.
4. Add contact provenance so each contact can be traced back to source emails.
5. Add dashboard controls for pipeline preview and sync history.

## 20. Overall Assessment

The app is a solid PFE/MVP foundation. It demonstrates the full intended workflow shape: read Piler archive data, expose it through an API, extract contacts, clean them, classify them, and prepare for Listmonk.

The strongest parts are the simple layered architecture, the working SQL dump cache mode, and the clear contact-processing pipeline.

The main missing pieces are production data access, real Listmonk synchronization, security, automated tests, and performance hardening around the large cached archive.

Recommended project label:

> MVP complete for archive exploration and contact preparation; integration and production hardening still pending.

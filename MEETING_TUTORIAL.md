# Piler Archive Extractor Meeting Tutorial

## 1. Executive Summary

This application is a PHP 8 MVP that exposes archived emails through a REST API, extracts contact addresses from those emails, cleans and deduplicates them, and prepares the dataset for a future synchronization into Listmonk.

In one sentence:

> It is a bridge layer between archived email data in Piler and marketing/contact management workflows in Listmonk.

What it does today:
- serves archived email metadata through API endpoints,
- reads either mock data, a real SQL dump, or eventually a real Piler database,
- extracts email addresses from `from`, `to`, and `cc`,
- removes duplicates, invalid addresses, and system senders like `noreply`,
- classifies contacts with a simple placeholder rule set,
- provides a small dashboard for exploring the archive.

What it does not do yet:
- no real Listmonk API push,
- no real PDO mapping to a live Piler schema,
- no NLP or AI classification,
- no authentication or production hardening.

## 2. What Problem It Solves

The business problem is that archived emails contain a lot of useful contact information, but that information is buried in message metadata and is not ready to be used directly in a campaign platform.

This MVP solves the first half of the problem:
1. access archived email records,
2. extract candidate contacts,
3. clean the contact list,
4. structure the data for future synchronization.

So the app is best described as an **email archive enrichment and preparation layer**.

## 3. The 30-Second Pitch

If someone asks you to explain it quickly, say this:

> The app reads archived emails from Piler or from a Piler SQL dump, exposes them through a lightweight REST API, extracts and deduplicates contact addresses, and prepares those contacts for future synchronization into Listmonk. Right now it is an MVP focused on ingestion, cleaning, and exploration, not on final production sync.

## 4. Main Architecture

The architecture is layered:

- **API layer**: receives HTTP requests and returns JSON.
- **Service layer**: contains the enrichment logic.
- **Repository layer**: chooses the data source and fetches emails/attachments.
- **Integration layer**: placeholder for future Listmonk sync.
- **Presentation layer**: a mini dashboard in `public/gui`.

Important files:
- `public/index.php`: app bootstrap.
- `config/routes.php`: route registration and dependency wiring.
- `app/Controllers/*`: HTTP controllers.
- `app/Services/*`: extraction, cleaning, classification, sync helpers.
- `app/Repositories/*`: data access.
- `cli/sync_contacts.php`: CLI pipeline runner.

## 5. How the App Actually Works

### Step 1: Bootstrap

The request enters through `public/index.php`, which:
- loads Composer autoload,
- loads environment config,
- initializes database config,
- registers routes,
- runs the Fat-Free Framework app.

### Step 2: Choose the Data Source

The app has 3 effective data modes:

1. **Mock data mode**
   - used if there is no DB connection and no SQL dump datasource.
   - returns a small hardcoded email set from `EmailRepository`.

2. **SQL dump mode**
   - used when `piler_backup.sql` exists and SQL-dump loading is enabled.
   - this is the main working mode in the current repo.
   - the dump is parsed and cached into `storage/cache/piler_dump_cache.json`.

3. **Real database mode**
   - enabled when `USE_REAL_DB=true`.
   - configuration exists, but the actual repository queries are still TODO.
   - this means the live Piler DB integration is not finished yet.

### Step 3: Expose Email Data

The API exposes:
- email list,
- email by ID,
- search,
- attachments,
- health check.

### Step 4: Run Enrichment Logic

The contact pipeline does this:

1. load emails,
2. extract addresses from `from`, `to`, and `cc`,
3. normalize addresses to lowercase,
4. reject invalid addresses,
5. reject system addresses like `noreply`, `postmaster`, `mailer-daemon`,
6. deduplicate the remaining contacts,
7. classify them with a simple rule,
8. prepare a Listmonk payload preview.

## 6. The Core Logic, Plainly Explained

### Contact extraction

The extraction logic uses a regex to detect email addresses inside:
- single sender strings,
- arrays of recipients,
- arrays of CC values.

This means it can handle values like:
- `Alice Manager <alice@company.tn>`
- `["team@company.tn", "sales@company.tn"]`

### Contact cleaning

After extraction, each address is:
- trimmed,
- lowercased,
- validated with `FILTER_VALIDATE_EMAIL`,
- filtered against system-account patterns.

System addresses rejected by rule include:
- `noreply`
- `no-reply`
- `do-not-reply`
- `mailer-daemon`
- `postmaster`

### Classification

Classification is currently a placeholder:
- domains ending with `.edu` or `.tn` become `education-or-local-domain`,
- domains containing `gov` become `public-sector`,
- everything else becomes `business-or-general`.

This is useful as a demo of the pipeline shape, but it is not a smart model yet.

### Listmonk sync

The Listmonk integration is not implemented.

Today it only:
- prepares a placeholder payload,
- returns `not_implemented` from the `sync()` method.

## 7. Current Data Facts From This Workspace

These are the real archive stats found in the current cached SQL-dump dataset during inspection:

- emails in cache: **226,260**
- attachments linked to emails: **105,599**
- email IDs with at least one attachment: **36,390**
- distinct sender domains observed: **4,306**
- earliest cached email date: **2013-08-28 16:31:43**
- latest cached email date: **2026-02-06 07:11:54**

Representative sample emails from the cache:

1. `Re: Undelivered Mail Returned to Sender`
2. `PHP: An error occurred on server canli.uzem.iienstitu.com ERROR ID 'e8381047da'`
3. `[Activity #857][RIADVICE - Management HQ] Activité #857 moved to status 'assigned'`

Important nuance:
- in SQL-dump mode, the app currently extracts metadata, recipients, and attachment metadata,
- `body_preview` is empty in cached dump rows,
- this MVP is mostly metadata-driven, not full email-body analysis.

## 8. Pipeline Results You Can Quote

The sync history log contains successful sample runs over a 1,000-email subset.

One recorded run produced:
- emails processed: **1,000**
- extracted addresses: **2,072**
- valid contacts: **29**
- duplicates removed: **1,590**
- ignored invalid or system addresses: **453**

How to explain that result:

> The archive contains many repeated operational recipients and system notifications. The pipeline is intentionally strict, so the number of final unique contacts is much smaller than the raw extracted addresses.

## 9. API Endpoints

### `GET /`
- welcome endpoint,
- returns a simple success response and version.

### `GET /health`
- health-check endpoint,
- returns `{ "success": true, "status": "ok" }`.

### `GET /emails?limit=10&offset=0`
- paginated list of emails,
- max limit is clamped to 100 by the controller,
- returns email data plus pagination info.

### `GET /emails/{id}`
- returns one email by ID,
- returns `404` if not found.

### `GET /emails/search?q=keyword`
- searches on:
  - subject,
  - sender,
  - `to` recipients.
- current search does not use body content.

### `GET /emails/{id}/attachments`
- returns attachment metadata for one email,
- returns `404` if the email does not exist.

## 10. Dashboard / GUI Walkthrough

The GUI is a lightweight local dashboard, not a separate frontend framework.

What it gives you:
- health check buttons,
- paginated email loading,
- server-side search,
- local filter on the currently loaded rows,
- sortable table,
- inspector for selected email,
- tabs for summary, JSON, attachments, contacts, and request trace,
- computed contact stats on the loaded dataset.

Important nuance for the meeting:

The dashboard computes the contact list on the client side from the loaded rows using logic that mirrors the backend pipeline. So:
- the GUI is excellent for demonstration,
- but it is not the authoritative sync engine,
- the authoritative pipeline is the PHP CLI/service flow.

Also note:
- `Load All Rows` can be expensive because it repeatedly paginates through the API until everything is loaded.

## 11. CLI Pipeline

The CLI command is:

```bash
php cli/sync_contacts.php
```

What it does:
- boots the same app config,
- chooses the same data source,
- runs the contact pipeline,
- prints a summary,
- records the run in `storage/logs/sync_history.log`.

This is the closest thing in the current MVP to a real batch job.

## 12. What Is Implemented vs. Placeholder

### Implemented
- app bootstrap,
- REST API routes,
- mock dataset,
- SQL-dump parser,
- cache generation,
- attachment metadata loading,
- regex-based contact extraction,
- contact cleaning and deduplication,
- placeholder contact classification,
- dashboard,
- sync-history logging.

### Placeholder or incomplete
- real Piler PDO queries,
- real Listmonk synchronization,
- advanced NLP or AI enrichment,
- test suite,
- authentication,
- production security controls,
- queueing/scheduling,
- body-content enrichment.

## 13. Honest Technical Limitations

These are good to say directly in a meeting.

1. This is an MVP, not a production-ready sync service.
2. Real DB integration is scaffolded but not mapped to the actual Piler schema yet.
3. Listmonk integration is stubbed, not live.
4. Contact intelligence is currently rule-based, not ML-based.
5. The search feature is metadata-based only.
6. The repo currently has no real automated tests.
7. There is no auth layer on the API.

If someone asks whether this is “finished,” the honest answer is:

> The ingestion and enrichment skeleton is working, but the final production integrations are still the next phase.

## 14. Best Way to Demo It Live

### Demo script

1. Start the app:

```bash
php -S localhost:8000 -t public
```

2. Open:

```text
http://localhost:8000/gui
```

3. Show the dashboard:
- click `Check Health`,
- click `Refresh Emails`,
- point out the metrics cards,
- select one email,
- open `JSON`,
- open `Attachments`,
- open `Contacts`,
- run a search like `devops`, `alert`, or `riadvice`,
- explain deduplication and filtering.

4. Then show the batch side:

```bash
php cli/sync_contacts.php
```

5. Explain the output:
- how many raw addresses were found,
- how many valid contacts remained,
- how duplicates and system addresses were removed.

## 15. Questions You Are Likely To Get

### “What exactly is intelligent about this app?”

Strong answer:

> In the current MVP, the intelligence is rule-based enrichment: extracting emails from archived metadata, normalizing them, filtering invalid/system addresses, deduplicating them, and classifying them into broad contact groups. It prepares the structure for more advanced NLP or smarter classification later.

### “Does it already sync to Listmonk?”

Strong answer:

> Not yet. The app is already shaped for that integration and can prepare the contact payload, but the actual API sync step is still stubbed.

### “Can it connect directly to Piler?”

Strong answer:

> The configuration layer for a real Piler database is present, but the actual PDO queries are still TODO because the final mapping depends on the real schema.

### “Why use a SQL dump?”

Strong answer:

> The SQL dump allows us to validate parsing, extraction, and enrichment on realistic archive data without waiting for a full live-database integration.

### “Is the GUI the product?”

Strong answer:

> The GUI is a lightweight inspection and demo console. The real core of the project is the backend API and the contact enrichment pipeline.

### “Why are the final contacts much fewer than the raw extracted addresses?”

Strong answer:

> Because archived mailboxes contain repeated recipients, group aliases, operational mailboxes, and system senders. The pipeline intentionally removes duplicates and rejects invalid or system addresses.

### “Is there AI in it already?”

Strong answer:

> Not in the ML sense yet. Right now the enrichment is deterministic and rule-based. The architecture leaves room for a later NLP or AI classification module.

## 16. A Very Short Closing Summary

If you need to end the explanation cleanly, say:

> This project is a backend MVP that transforms archived email data into a cleaner, structured contact dataset. It already proves the archive ingestion, extraction, deduplication, and exploration workflow. The next milestones are live Piler schema mapping, real Listmonk synchronization, and stronger classification logic.


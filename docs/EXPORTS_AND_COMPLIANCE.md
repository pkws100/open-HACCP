# Exports, compliance evidence and privacy boundary

## Purpose and limitation

Open HACCP produces operational evidence for a business HACCP system. It does not decide which control points, limits, actions or retention periods are correct for a particular business, and it does not certify legal or EN conformity. The responsible food business and, where appropriate, the competent authority determine the required scope.

The prototype terminology follows this distinction: **Behörden-Nachweis (Kernumfang)** means a deliberately reduced evidence set, not “legally complete”. Incomplete business, responsibility, point or instrument data causes a visible **Entwurf / Nachweis unvollständig** marker.

## Referenced legal framework

- [Regulation (EC) No 852/2004, Article 5](https://eur-lex.europa.eu/eli/reg/2004/852/oj/) requires HACCP-based procedures and documents/records commensurate with the nature and size of the business.
- [Commission Notice 2022/C 355/01](https://eur-lex.europa.eu/legal-content/DE/TXT/?uri=CELEX%3A52022XC0916%2801%29) describes food-safety management systems, monitoring, corrective action and proportionate documentation.
- [Regulation (EC) No 37/2005](https://eur-lex.europa.eu/eli/reg/2005/37/oj/deu) contains specific temperature-monitoring and record-retention rules for quick-frozen food in transport, warehousing and storage.
- [German § 2a TLMV](https://www.gesetze-im-internet.de/tlmv/__2a.html) addresses temperature recording equipment for quick-frozen food.

These links are design references, not an automated legal opinion. Before production use, a competent specialist must confirm applicability, national requirements, calibration evidence and retention.

## Versioned evidence

The following records are append-only or versioned:

- device alarm limits, battery thresholds and reporting cadence;
- point legal profile, classification, monitoring purpose, responsibility, retention and instrument evidence;
- measurements and transmission attempts;
- state/discrete compliance events;
- corrective-action revisions and verifications;
- security and domain audit entries.

For measurements, export queries resolve the configuration version whose effective timestamp is at or before `measured_at`. Later edits do not reinterpret old values. Corrective-action changes append a revision; verification makes an action immutable.

## Preflight

The preflight checks:

- legal business name and address;
- timezone and HACCP responsible user;
- each selected active point has responsibility and a versioned profile;
- quick-frozen points retain at least 12 months;
- quick-frozen points have documented instrument/conformity evidence and a reference.

Failing preflight does not block export because a draft can help correct data. It does block any claim that the generated evidence is complete.

## Core authority profile

Included:

- report ID, creator, generation time, selected period and SHA-256;
- business identity and timezone;
- selected devices/points, purpose and classification;
- effective temperature limits and measurement/upload intervals;
- relevant temperature values and data-quality status;
- deviations, acknowledgements, corrective actions, affected-product disposition and verification.

Excluded by default:

- humidity unless relevant to an extended operational analysis;
- battery, battery forecast, RSSI and Wi-Fi connection time;
- firmware/hardware, sequences, batch IDs and transmission diagnostics;
- IP addresses, user email and internal audit payloads/hashes;
- passwords, session/device tokens, device keys, peppers, audit keys and Wi-Fi credentials.

The PDF deliberately summarizes by day and point. XLSX/CSV are the formats for a complete selected measurement series.

## Extended profile

Administrators and operators can select additive fields: humidity, battery, forecast, RSSI, Wi-Fi timing, firmware/hardware, sequence, receive time, transmissions and configuration history. Auditors cannot create extended exports.

The selection and all filters are stored in immutable job parameters. An extended XLSX contains the normal sheets plus `Diagnose`, `Übertragungen` and `Konfiguration`. Technical content remains subject to the same secret exclusion.

## File and job lifecycle

Exports are asynchronous and processed by an internal worker:

1. API validates role, filters, mode, format and fields, then splits a range longer than 366 days into consecutive jobs.
2. It stores a `queued` job and an audit entry.
3. Worker atomically claims it as `running` and retries one transient generation failure once.
4. On success it stores filename, MIME type, size, SHA-256, dataset fingerprint, audit reference and 24-hour expiry.
5. Cleanup removes the file and marks the job `expired`; job/audit metadata remains.

Files are never served by a public static path. An authenticated, authorized download endpoint streams them with `Cache-Control: no-store, private`.

## Format safeguards

- PDF uses an embedded DejaVu font and no remote resources.
- XLSX writes typed numbers and UTC date values using a streaming writer.
- CSV uses UTF-8 BOM, semicolon delimiter, explicit manifest and ZIP packaging.
- Any text beginning with `=`, `+`, `-` or `@` is prefixed to prevent formula execution.
- Export data is capped at 500,000 measurement rows per job; requested periods are automatically split at 366-day boundaries.

## Audit chain

Audit entries contain the previous hash and an HMAC-SHA-256 of canonical metadata using `AUDIT_LOG_KEY`. The chain head is serialized under a database row lock. `php bin/audit-verify` recomputes the chain and reports the first invalid entry. The key is deliberately separate from `DEVICE_API_KEY_PEPPER`.

The HMAC chain makes changes detectable; it is not a substitute for database access control, encrypted backups, off-site/WORM anchoring or qualified electronic signatures. Production governance should periodically record the chain head outside the database.

Stable API error codes include `AUTHENTICATION_REQUIRED`, `PASSWORD_CHANGE_REQUIRED`, `CSRF_FAILED`, `FORBIDDEN`, `LEGAL_PROFILE_INCOMPLETE`, `INVALID_EXPORT_REQUEST`, `EXPORT_NOT_READY`, `EXPORT_EXPIRED` and `ACTION_VALIDATION_FAILED`. An incomplete but permitted draft is normally represented by `draft: true`; `LEGAL_PROFILE_INCOMPLETE` is reserved for workflows that explicitly require a complete legal profile.

## Data protection and operations

- Only a username/display name is needed; email is optional and excluded from core exports.
- Users are deactivated, not deleted, because their actions remain referenced.
- Application logs never receive full request bodies or credentials.
- Export downloads are audited without logging file contents.
- Existing measurement retention is not automatically enforced in this version. Business retention is configuration/evidence, and deletion requires a future reviewed retention job.

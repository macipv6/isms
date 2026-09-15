# ISMS Builder

Evidence-based ISMS and BCM builder for SME consulting workflows. The Foundation slice provides a Laravel/Vue application, PostgreSQL runtime, Microsoft Entra single-tenant login, a local consultant allow-list, a protected dashboard, security hardening and append-only authentication auditing.

## Requirements

- Docker Engine with Docker Compose
- A Microsoft Entra tenant for the real login smoke test

The application container uses PHP 8.4; the frontend container uses Node 24; PostgreSQL 18 is the database baseline.

Evidence uploads additionally require PHP's ZIP extension. Rebuild the application image after pulling changes to the PHP runtime or Dockerfile:

```bash
docker compose build app
```

## Quick start

```bash
cp .env.example .env
docker compose build app
docker compose run --rm app composer install
docker compose run --rm node npm install
docker compose up -d db app web node
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
```

Configure the Microsoft values in `.env` as described in [`docs/setup/entra-id.md`](docs/setup/entra-id.md), then create the initial local allow-list user:

```bash
docker compose exec app php artisan isms:bootstrap-user \
  11111111-1111-4111-8111-111111111111 \
  22222222-2222-4222-8222-222222222222 \
  admin@example.test \
  "ISMS Admin" \
  --organization="ISMS Consulting" \
  --role=admin
```

The UUIDs and email above are examples. Replace them with the administrator's actual Entra Tenant ID, Object ID and email before attempting a real sign-in.

Open `http://localhost:8080/login`.

## Verification

Inside the application container:

```bash
composer test
```

Frontend:

```bash
npm run lint:check
npm run format:check
npm run types:check
npm run build
```

The CI workflow runs the same backend and frontend gates, verifies reversible migrations and the starter catalog, and explicitly installs PHP's ZIP extension.

## Register CSV imports

Business processes, assets, and dependencies can be imported through separate CSV files with these exact headers. Header matching is case-insensitive, column order is arbitrary, and unknown or duplicate headers are rejected.

```text
key,name,description,owner_name,owner_email,active
key,name,type,description,owner_name,owner_email,active
source_type,source_key,target_type,target_key,importance,reason,active
```

Files must be UTF-8 and may include a UTF-8 BOM. Comma and semicolon delimiters are accepted when the header identifies exactly one format. Each upload is limited to 5 MiB and 10,000 data rows. NUL bytes, malformed quoting, and cells whose trimmed value begins with `=`, `+`, `-`, or `@` are rejected to prevent spreadsheet-formula injection.

An import first validates the complete file and presents a preview; it does not change a register. A valid preview can then be confirmed once within 30 minutes. Confirmation revalidates the reviewed data and applies the whole batch atomically, so a conflict or invalid row leaves the register unchanged. Existing records omitted from a CSV remain unchanged and are never implicitly deactivated or deleted.

The application retains only the normalized preview payload needed for confirmation, never the original CSV bytes. Expired and completed batches are removed by the scheduled daily `register-imports:purge` command. Ensure the Laravel scheduler runs in production so this cleanup continues automatically.

CSV parsing, validation, and register updates are implemented behind transport-independent services. These services can support a future authenticated API, but this version does not expose an external register API.

## Evidence storage and upload security

Uploaded evidence is stored on Laravel's private `evidence` disk below `storage/app/private/evidence`. It has no public URL and is only delivered through an authorized, integrity-checked download endpoint. Original filenames are never used as storage paths. The domain services use Laravel's filesystem abstraction, so the disk can later be switched to S3 or Azure-compatible object storage without changing the evidence workflow.

The effective upload limit is exactly 50 MiB per file. PHP uses `upload_max_filesize=50M` and `post_max_size=52M`; Nginx uses `client_max_body_size 52m` so request overhead does not lower the application limit. Approved formats are PDF, PNG, JPEG, TXT, CSV, DOCX, XLSX, and ZIP. The application checks the extension against the detected content type.

User-supplied ZIP files are inspected without extraction. Encrypted or nested archives, path traversal, symbolic links, executable content, scripts, macro-enabled Office files, installers, and disk images are rejected. A ZIP may contain at most 200 regular files and at most 250 MiB of uncompressed data. These checks reduce archive abuse but are not malware scanning: this version has no antivirus, sandbox, OCR, or automatic content analysis. Deployments with stronger threat-model requirements must add a quarantine and malware-scanning stage before evidence is made available.

The private evidence directory is application data, not disposable container state. Back up `storage/app/private/evidence` together with the PostgreSQL database and keep both backups on the same retention schedule. Restore them as one consistent set because database metadata contains the immutable size and SHA-256 values used for download integrity checks. Test restores regularly and protect backup copies with access controls and encryption appropriate for customer evidence. When using S3 or Azure-compatible storage, enable corresponding object-store durability, versioning, retention, and backup controls instead of relying on the local directory backup.

No local password authentication, registration or password-reset flow is part of this application.

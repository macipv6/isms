# ISMS Builder

Evidence-based ISMS and BCM builder for SME consulting workflows. The Foundation slice provides a Laravel/Vue application, PostgreSQL runtime, Microsoft Entra single-tenant login, a local consultant allow-list, a protected dashboard, security hardening and append-only authentication auditing.

## Requirements

- Docker Engine with Docker Compose
- A Microsoft Entra tenant for the real login smoke test

The application container uses PHP 8.4; the frontend container uses Node 24; PostgreSQL 18 is the database baseline.

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

No local password authentication, registration or password-reset flow is part of this application.

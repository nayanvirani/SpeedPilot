# SpeedPilot

Shopify speed-optimization app. See `SpeedPilot — Shopify Speed Optimization App MVP Spec.docx`
for the full product spec. This repo implements all four roadmap phases (audit, safe
auto-fixes, monitoring, advanced/AI) as a monorepo of three independently runnable services.

```
backend/    Laravel 11 API - OAuth, billing, webhooks, scan orchestration, fix engine
frontend/   Remix + Polaris + App Bridge - embedded admin UI
scanner/    Node/Express - Playwright + Lighthouse runner, DOM/resource analyzer
```

## What's still needed before this runs end-to-end

1. **Shopify Partner app credentials** - `SHOPIFY_API_KEY` / `SHOPIFY_API_SECRET` in
   `backend/.env` and `SHOPIFY_API_KEY` in `frontend/.env`, plus `client_id` in
   `frontend/shopify.app.toml`.
2. **A Postgres database** (Railway or otherwise) - set `DATABASE_URL` (or the discrete
   `DB_*` vars) in `backend/.env`. This machine's PHP only has the `pdo_mysql` driver
   installed, so migrations can't run here locally yet; they will run unmodified once
   pointed at a real Postgres instance (the migrations avoid MySQL-only syntax).
3. `sudo apt install php8.3-pgsql` locally if you ever want to run `artisan migrate`
   against Postgres from this machine directly, rather than only from wherever it's
   deployed (Railway ships the driver by default).

## Running each service

**Scanner** (no dependencies on the other two - fully runnable now):
```
cd scanner
npm install && npx playwright install chromium
npm start                 # listens on :4000
curl -X POST localhost:4000/scan -d '{"url":"https://example.com"}' -H "Content-Type: application/json"
```

**Backend**:
```
cd backend
composer install
cp .env.example .env      # fill in DATABASE_URL + SHOPIFY_* once available
php artisan migrate --seed
php artisan serve         # :8000
php artisan queue:work    # separate terminal - runs RunAuditJob/ApplySafeFixesJob
```

**Frontend**:
```
cd frontend
npm install
cp .env.example .env
npm run dev                # :3000
```

## Pricing (billed via Shopify Billing, config/speedpilot.php)

| Plan | Price | Included |
|---|---|---|
| Free Scan | $0 | One-time audit, app/script impact table (read-only) |
| Starter | $19/mo | 5 safe auto-fixes, 1 script rule, weekly scan, 7-day history |
| Growth | $39/mo | Unlimited safe fixes + rules, medium-risk preview fixes, daily monitoring, 30-day history |
| Pro | $79/mo | + high-risk recommendations, AI recommendations, 90-day trends, priority support |

## Known caveats

- `@shopify/polaris` (React) is deprecated upstream in favor of Polaris web components -
  it still works and is what the spec calls for, but plan to migrate eventually.
- The Theme App Extension's `shopify.extension.toml` schema was hand-written (no Shopify
  CLI available in this dev environment) - re-verify against current docs before first
  `shopify app deploy`.

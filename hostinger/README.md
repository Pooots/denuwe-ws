# Hostinger: SPA in public_html + Laravel in backend

Your live site currently serves only the frontend from `public_html`.
Laravel lives in `backend`, which is **not** the document root, so
`/api` never runs PHP — Hostinger returns its HTML 404.

## Required layout

```text
domains/your-denuwe-site.hostingersite.com/
  public_html/                 ← document root (SPA from denuwe-core/dist)
    index.html
    assets/
    .htaccess                  ← from hostinger/public_html/.htaccess
    api/
      index.php                ← from hostinger/public_html/api/index.php
      .htaccess                ← from hostinger/public_html/api/.htaccess
  backend/                     ← full denuwe-ws project
    app/
    bootstrap/
    config/
    public/
    routes/
    vendor/
    .env
    ...
```

## Upload these 3 files now

From this repo, copy into Hostinger:

| Local file | Upload to |
|---|---|
| `denuwe-ws/hostinger/public_html/.htaccess` | `public_html/.htaccess` |
| `denuwe-ws/hostinger/public_html/api/index.php` | `public_html/api/index.php` |
| `denuwe-ws/hostinger/public_html/api/.htaccess` | `public_html/api/.htaccess` |

Create the `public_html/api` folder if it does not exist.

Also upload the SPA build from `denuwe-core/dist/` into `public_html/`
(`index.html`, `assets/`, logos, favicon, etc.).

## Storage (logos / uploads)

Uploads and other files are served from Laravel `storage/app/public`.
After deploy, on the server:

```bash
cd backend
php artisan storage:link
```

Then either:

- Symlink / copy `backend/public/storage` → `public_html/storage`, **or**
- Keep document root as `backend/public` (see alternative below).

## backend/.env checks

### Same-origin (SPA + API on Hostinger)

```env
APP_URL=https://your-denuwe-site.hostingersite.com
FRONTEND_URL=https://your-denuwe-site.hostingersite.com
CORS_ALLOWED_ORIGINS=
JWT_SECRET=...must not be empty
```

### Split deploy (SPA on Vercel → API on Hostinger)

```env
APP_URL=https://your-denuwe-site.hostingersite.com
FRONTEND_URL=https://your-denuwe-frontend.vercel.app
CORS_ALLOWED_ORIGINS=https://your-denuwe-frontend.vercel.app
JWT_SECRET=...must not be empty
```

On Vercel (denuwe-core), set a **build** env var to the Hostinger API:

```env
VITE_API_BASE_URL=https://your-denuwe-site.hostingersite.com/api/v1
VITE_FRONTEND_URL=https://your-denuwe-frontend.vercel.app
```

Then redeploy Vercel. Relative `/api/v1` will not work on Vercel (no Laravel there).

Generate secret on the server (SSH or temporary artisan run):

```bash
cd backend
php artisan jwt:secret
php artisan config:clear
php artisan migrate --force
php artisan storage:link
```

## Verify

These must return **JSON**:

1. https://your-denuwe-site.hostingersite.com/api
2. https://your-denuwe-site.hostingersite.com/api/health
3. https://your-denuwe-site.hostingersite.com/api/v1

If you still get Hostinger’s HTML 404, the `api` folder was not created under `public_html`, or the bridge path to `backend` is wrong.

If you get a JSON error `"Laravel backend folder not found"`, rename/move the Laravel project so it sits next to `public_html` and is named exactly `backend`.

## Alternative (cleaner long-term)

In hPanel → Websites → your site → **Document root** → set to `backend/public`, then copy the SPA build into `backend/public/`. Then you can remove the `public_html/api` bridge.

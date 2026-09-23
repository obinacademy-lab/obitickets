# obitickets — deploy repo

This is the **flat-structure deploy mirror** of the main development repo
(`obitickets.com`, nested under `public/`, `includes/`, `config/`, etc.). This
repo's root corresponds to the dev repo's `public/` folder — `public/index.php`
becomes `index.php` here, `public/api/` becomes `api/`, while `includes/`,
`config/` and `migration/` sit at the repo root as siblings, not nested under
a `public/` folder.

Hosted on Hostinger via hPanel's Git integration with auto-deployment
enabled — pushing to `main` deploys automatically, no manual step needed.

## Porting a change from the dev repo

1. Edit and test the change in the main dev repo first.
2. Copy the changed file(s) here at their flattened path.
3. Fix `__DIR__`-relative paths that reference `includes/`/`config/`:
   - A file that was directly under `public/` (e.g. `index.php`): strip one
     `../` (`__DIR__.'/../includes/x'` becomes `__DIR__.'/includes/x'`).
   - A file one level deeper (e.g. `public/api/*.php`, now `api/*.php`):
     strip one `../` the same way (`../../includes/x` becomes `../includes/x`).
   - Files inside `includes/`/`config/`/`migration/` themselves don't change —
     their relative relationship to each other is identical in both layouts.
   - Watch for any path that mentions `public/` literally (e.g.
     `__DIR__.'/../public/uploads/...'` in `includes/uploads.php`) — that
     needs `public/` dropped entirely here, since `uploads/` is a top-level
     sibling of `includes/` in this repo, not nested under a `public/` folder.
4. `php -l` the ported file.
5. `git add` the specific file(s) (never `-A`), commit, `git push origin main`.

## Production setup (one-time)

1. Create the MySQL database in hPanel and import `schema.sql` via phpMyAdmin.
2. Create `config/config.php` directly on the server (via hPanel File Manager
   or SSH) from `config/config.example.php` — it's gitignored and never
   deployed via git. Set real `DB_*`, a fresh `APP_SECRET`
   (`php -r "echo bin2hex(random_bytes(32));"`), `APP_ENV` to `production`,
   `APP_URL` to the live domain, and the real `IOTEC_*` values.
3. `uploads/events/*.jpg` (the curated seed photos) are committed and deploy
   automatically; anything an organizer uploads later lands in `uploads/`
   too but is never tracked by git (see `.gitignore`).

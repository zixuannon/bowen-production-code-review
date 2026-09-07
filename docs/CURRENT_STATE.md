# Current State

Updated: 2026-08-25

## Active delivery

- A standalone static rebuild of the public Bowen School website now lives in `mmbowen-site/`.
- The page HTML, CSS, responsive layout, public imagery, metadata, telephone links, and anchor navigation are included locally.
- The static delivery does not depend on the original Vinext/React runtime, a database, or the eSchool application.
- The verified page is also integrated into Laravel as `resources/views/bowen-school/home.blade.php`, with assets isolated under `public/assets/bowen-school/`.
- Only the configured `BOWEN_PUBLIC_SITE_HOST` (default: `school.mmbowen.com`) receives the new public homepage. Authenticated users still redirect to `/dashboard`, and all other hosts retain the existing root behavior.
- Desktop and mobile navigation each include a “登录” link to the existing named `/login` route.
- Existing eSchool and staff-leave worktree changes were left untouched.

## Verification evidence

- `node mmbowen-site/verify.mjs` passes 14 structural checks and validates 13 unique local resource references.
- Local browser acceptance returned HTTP 200 for the page, stylesheet, and every image, with no console warnings or errors.
- At 1280 × 720, the local page matches the live page's document height, section bounds, principal heading typography, colors, and layout measurements.
- At 390 × 844, the responsive navigation displays, opens correctly, and the contact anchor scrolls to the expected section.
- `git diff --check -- mmbowen-site` passes.
- HTML Tidy reports only intentional empty decorative `span`/`i` elements; no structural HTML error was reported.
- `tests/Feature/BowenPublicHomepageTest.php` passes 3 tests covering host routing, route preservation, both Login links, and published asset existence.
- A focused regression set passes 11 existing tests plus the 3 new homepage tests (37 assertions in the focused run).
- Local Laravel browser acceptance confirms a 1280 px homepage with no overflow, every image loaded, no console warnings/errors, and successful desktop navigation to the existing login form.
- At 390 × 844, the mobile menu opens and its Login link navigates to `/login` without console warnings/errors.
- The broader unit suite has 49 passing tests and 15 pre-existing `TwoStageLeaveServiceIsEnabledTest` failures caused by that pure PHPUnit test class not bootstrapping Laravel's `config` binding. This homepage change does not touch that service or its tests.

## Production deployment

- The user approved the targeted production deployment to `eschool-prod` on 2026-08-25.
- The active vhost root `/www/wwwroot/43.160.241.126` remained linked to `/www/wwwroot/releases/eschool-rc-3be9f22` throughout deployment.
- Artifact `/root/bowen-homepage-20260825.tar.gz` was verified locally and remotely with SHA-256 `a69058cba05b3b118650fad1da27374687b83e21ae85d0f600f784fda7ab2fd5`.
- Production backup: `/root/backups/bowen-homepage-20260825_1835`.
- Retained staging extraction: `/root/bowen-homepage-stage-20260825_1835`.
- Deployed only the controller, application host configuration, Bowen Blade view, and 14 isolated public assets. No database, migration, `.env`, Nginx, DNS, TLS, financial data, user role, or service restart was involved.
- Production and local hashes match for the controller, config, Blade view, stylesheet, and social preview image. Ownership and modes are `root:root`, `664` for files, and `775` for the asset directory.
- Laravel configuration and compiled-view caches were cleared successfully; neither configuration nor routes were cached before deployment.
- Production desktop and 390 × 844 mobile browser smoke checks passed. The homepage, all images, CSS, both Login links, and the existing `/login` form loaded without browser console warnings/errors.
- The newest `production.ERROR` entry in the last 100 Laravel log lines predates deployment; no post-deployment Laravel error was observed.
- Deployment scope and rollback remain documented in `docs/BOWEN_PUBLIC_HOMEPAGE_DEPLOYMENT.md`.

## Deployment baseline guardrail

- Machine-readable contract: `config/production-baseline.json`.
- Release manifests are generated as `.release-manifest.json` and checked by `scripts/production/verify_release_guard.sh` before any release action.
- The guard verifies the Production host/runtime/socket/release-root contract, manifest identity, GitHub ref availability, accepted-baseline ancestry, shared `.env`/storage links, and writable bootstrap cache; failures are fail-closed.
- Current accepted Production baseline is `79f56c8337785a5672b54800615e02b4a58098b7` (Phase 5.5A); the next phase must descend from it.

# Frontend Standards — KAM Growth Partnership Program

How the *Frontend Development Standard Principles SOP* is applied in this
codebase. Read this before changing anything under `api/` or `assets/`.

The app is server-rendered PHP (no build step) deployed on Vercel
(`vercel-php`). "Component" here means a PHP function that returns markup —
the same idea as a UI component, minus the framework.

---

## 1. Project structure (SOP §3 Step 3)

| SOP layer          | Where it lives                      | Rule |
|--------------------|-------------------------------------|------|
| Pages              | `api/*.php`                         | One file per route. Data in, markup out. |
| Components         | `api/lib/ui.php`                    | Presentation only. No DB, no permissions, no money maths. |
| Domain components  | `api/lib/supabase.php`, `work.php`, `terms.php`, `docs.php` | Markup helpers that need domain data (`notif_bell`, `app_sidebar`, `business_card`, `terms_checklist`). |
| Services / API     | `api/lib/supabase.php`              | The only place that talks to Supabase. |
| State / session    | `api/lib/supabase.php` (cookie session, guards, idle timeout) | |
| Styles / tokens    | `assets/styles.css`                 | Tokens in `:root`, then components. |
| Behaviour          | `assets/js/app.js`                  | One module per behaviour. No inline `<script>` in pages. |
| Assets             | `assets/img/`                       | |

Routing is declared in `vercel.json`: every public `/x.php` rewrites to
`/api/x.php`. A new page needs a rewrite entry or it 404s in production.

> **Known duplication:** the PHP files at the repo root (`index.php`,
> `dashboard-*.php`, `lib/`, `public/`) are a superseded copy from before the
> `api/` split and are not deployed. SOP §2.3 ("remove unused code") says they
> should go; they were left in place because deleting them is a call for the
> repo owner, not a frontend change.

## 2. Components (SOP §2.2)

Every component in `api/lib/ui.php` **returns** a string and never echoes, so
callers control output order. Current set:

| Function | Renders |
|---|---|
| `ui_head($opts)` | `<head>`, stylesheet + `app.js`, skip link, optional idle watchdog |
| `ui_foot($extra)` | Closing markup |
| `ui_topbar($opts)` | Lockup, notification bell, role badge, extra links, logout |
| `ui_alerts($notice, $errors)` | Flash + validation errors in one live region |
| `ui_page_title($kicker, $title, $accent)` | Kicker line + H1 |
| `ui_stat_grid($stats, $id)` | The `01 // …` KPI tiles (auto-numbered) |
| `ui_terminal_head($caption)` | Terminal-style section header |
| `ui_table($inner, $label)` | Scrollable, focusable, labelled table region |
| `ui_empty($message)` | Empty state |
| `ui_idle_watchdog($csrf)` | Markup for the inactivity warning |
| `asset($path)` | Cache-busted asset URL (`?v=` from file mtime) |

**Adding a page** — the shell is always:

```php
<?= ui_head(['title' => '…', 'description' => '…', 'csrf' => $csrf, 'idle' => true]) ?>
<?= ui_topbar(['csrf' => $csrf, 'bell' => notif_bell($token, $profile, $csrf),
               'role' => 'Client', 'role_class' => 'client']) ?>
<main class="wrap" id="main" tabindex="-1">
  <?= ui_alerts($notice, $errors) ?>
  …
</main>
<?= ui_foot() ?>
```

`id="main"` is required — it is the skip link's target.

## 3. Design tokens (SOP §3 Step 2)

Declared once in `:root` in `assets/styles.css`: colour, a 4px spacing scale
(`--space-1…8`), a type scale (`--text-2xs…xl`), radii, shadows, motion
(`--ease`, `--fast`, `--slow`), layout widths, `--tap-min` (44px) and a
z-index ladder (`--z-sidebar` → `--z-skip`).

New CSS uses tokens, not raw values. New stacking contexts use the ladder so
the topbar, popovers and the idle dialog never fight.

## 4. Separation of concerns (SOP §2.4)

- A page file may contain business logic and markup, but **no** `<script>`.
  Behaviour goes in `assets/js/app.js` and is bound through `data-*` hooks or
  a class, so the same module works on any page that uses that markup.
- `app.js` modules are opt-in: each returns immediately when its markup is
  absent, and `boot()` catches module errors so one failure cannot take the
  page down.
- Components never query Supabase; pages pass in the data.

Modules today: `authTabs`, `passwordToggles`, `termsChecklist`, `scrollSpy`,
`notifications`, `countdowns`, `tableHints`, `copyButtons`, `adminPanels`,
`statusFields`, `idleWatch`.

## 5. Accessibility (SOP §2.6)

- Skip link first on every page; `<main id="main" tabindex="-1">` as target.
- Semantic landmarks: `header`, `nav` (labelled), `main`, `aside`, `section`.
- One focus style, defined once on `:focus-visible`; focus is never removed.
- Alerts render inside `role="status" aria-live="polite"`; the error block
  adds `role="alert"` and lists problems with a count.
- Active navigation carries `aria-current`; icon-only controls carry labels;
  decorative SVG is `aria-hidden`.
- Wide tables are focusable `role="region"` so they scroll from the keyboard.
- Touch targets are at least 44×44 on coarse pointers.
- `prefers-reduced-motion: reduce` disables animation and smooth scrolling
  globally.

## 6. Responsive (SOP §2.5)

Breakpoints: 480px (phone), 900px (tablet / sidebar collapse), plus
height-based rules for short laptop screens on the auth page. The sidebar
becomes a horizontally scrolling tab strip under 900px; stat grids go 4 → 2 →
1 column; the topbar drops the role badge on narrow phones.

## 7. Performance (SOP §2.7)

- One stylesheet, one script, both cache-busted by mtime — change a file and
  the new version is picked up with no hand-edited version strings.
- `app.js` is `defer`red; fonts `preconnect`.
- No frontend dependencies, no bundler, no runtime framework.

## 8. Security (SOP §5)

- No keys in frontend code; all Supabase calls are server-side.
- Every state-changing request is `POST` + CSRF token (`csrf_check()`).
- All interpolated output goes through `e()`.
- Signed-in pages send `no-store`; the session cookie is AES-256-GCM sealed,
  `HttpOnly`, `SameSite=Lax`, `Secure` behind HTTPS.
- **Inactivity sign-out: 5 minutes, every signed-in account.** See below.

## 9. Inactivity sign-out

| Layer | File | Behaviour |
|---|---|---|
| Rule | `api/lib/supabase.php` | `IDLE_TIMEOUT = 300`, `IDLE_WARNING = 60` |
| Enforcement | `require_login()` | Idle session → cleared, audit-logged (`logout_idle`), redirected to `/index.php?timeout=1` |
| Activity stamp | `session_touch()` | Updates `seen` in the session cookie, at most once per 15s. Ignores automated loads carrying `?auto=1` |
| Heartbeat | `api/session.php` | `POST` (+CSRF) marks activity; `GET` reports remaining seconds without extending |
| Warning UI | `ui_idle_watchdog()` + `.idle-dialog` | Modal at 60s left with a live countdown, "Stay signed in" / "Sign out now", focus trapped, Esc keeps the session |
| Browser clock | `idleWatch` in `app.js` | Tracks real interaction only, shares the last-activity time across tabs via `localStorage`, pings the server at most once a minute, submits the timeout logout at 0 |

The server is the authority: with JavaScript disabled or the tab killed, the
next request still ends an idle session. The browser half exists so the user
gets a warning instead of a silent failure.

To change the timeout, edit `IDLE_TIMEOUT` only — every message, the warning
and the markup read from it.

## 10. Quality checklist (SOP §7)

Before delivery:

- [ ] Works at 360px, 768px, 1280px and 1920px, no horizontal page scroll
- [ ] Keyboard only: skip link, every control reachable, focus always visible
- [ ] Alerts announced; headings in order; images labelled
- [ ] No inline `<script>` added; new behaviour is a module in `app.js`
- [ ] New CSS uses tokens; no new hardcoded colour or spacing value
- [ ] `php -l` clean on every changed file; no console errors in the browser
- [ ] CSRF token on every new form; all output escaped with `e()`
- [ ] New route added to `vercel.json`

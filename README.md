# PayCycle — Subscription Tracker

A PHP + SQLite app for tracking your personal subscriptions: when they
started, how many payments you have already made, when the next one is due and
how much you will pay next month. It also has a statistics dashboard with
Chart.js charts.

It is based on an Excel sheet ("Subscription Tracking.xlsx") that was kept by hand.

The interface is available in **Greek, English and German**, with a light/dark theme.

---

## 1. Requirements

- PHP 8.1+ with the **pdo_sqlite** extension (almost always enabled by default)
- Apache2 (or any server that runs PHP). No mod_rewrite is needed and there are
  no "pretty" URLs.
- Nothing else. No Composer, npm or build step. Bootstrap 5, Bootstrap Icons
  and Chart.js are loaded from a CDN in the page (so the user's browser needs
  internet access to render the styling — your server does not).

## 2. Installation

1. Copy the whole folder to your server, e.g.:
   ```
   /var/www/html/subscriptions/
   ```
2. Give the web server user (usually `www-data`) write access to the `data/`
   folder so it can create the SQLite file the first time:
   ```
   chown -R www-data:www-data /var/www/html/subscriptions/data
   chmod 775 /var/www/html/subscriptions/data
   ```
3. Open the address in your browser, e.g. `https://.../subscriptions/`.
   The database (`data/subscriptions.sqlite`) is created automatically with an
   empty schema as soon as the first page loads — there is nothing to run by hand.

There are no sample subscriptions — you start from an empty list.

## 3. Admin password

The password is **not** set in a file. The first time you open the **Login**
page, while no password exists, the app asks you to create one (at least 8
characters). It is stored in the database only as a bcrypt hash, never in plain
text. You can change it later from the **Settings** page.

> Create the password right after installing: whoever opens the Login page
> first on a new database with no password sets the password.

Upgrading from an old version: if a `config.local.php` file with
`APP_PASSWORD_HASH` exists, that hash is imported into the database the first
time, and you can then delete the file. If you lose the password, delete the
`admin_password_hash` row from the `app_settings` table and a new one will be
requested.

**Viewing** (subscription list, statistics dashboard) is open to anyone who
has the link — no password is required. The password is only needed for
**management**: adding, editing, freezing, canceling, deleting and changing settings.

## 4. How the "payment ledger" works

This is the most important part of the logic, so it is worth understanding:

Every time a subscription is "charged" (monthly/yearly/etc.), the app
**records a real row** in a payments table — it does not just calculate things
"theoretically" each time you open the page.

- **When you add a subscription with a start date in the past** (e.g. you add
  today a subscription that actually started 8 months ago), the app **fills in
  the whole history automatically** — it records all the payments you would have
  made up to today — and correctly works out when the next one is due. You will
  see a message like "8 previous payments up to today were recorded automatically".
- **For subscriptions you already track**, every time you open the app it checks
  whether the next payment date, counted from the **last recorded payment** (not
  from the original start date), has passed and, if so, records it too. This way
  the history is built up gradually and correctly, without ever recounting from
  the beginning.
- If you add a price with an effective date in the **past** (e.g. "for the
  first 3 months it was cheaper"), the app retroactively corrects the amounts of
  the already recorded payments from that date onward.
- Periods that fall inside a **freeze** are not recorded as payments.
- In each subscription's modal (the pencil/info button) there is a **Payments**
  tab showing exactly which payments have been recorded.
- If you change a subscription's **frequency or start date**, its recorded
  payments are recalculated with the new values.

### Freeze vs. Cancel + Reactivate

- **Freeze/Unfreeze**: for a temporary pause where you know you will continue
  (e.g. you stopped a gym app for 2 months). The frozen months are correctly
  excluded from the calculation and the next payment shifts accordingly.
- **Cancel/Reactivate**: if you cancel and later reactivate the same
  subscription, the app automatically records the period in between as a
  "freeze", so no fictitious payments are created for a period when the
  subscription was not actually running.

## 5. Usage guide

- **New subscription**: button at the top right of the main page. Enter the
  real start date (even if it is old) — the history fills itself in.
- **Edit / Details**: the pencil button on each row opens a modal with 4 tabs:
  Details (name/category/frequency/date/notes), Payments (the actual history),
  Price history (add a new price here when the cost changes), Freezes.
- **Freeze / Unfreeze / Cancel / Reactivate / Delete**: from the (⋮) menu on
  each row.
- **Statistics**: second item in the top menu. Cost by category, 12-month
  history and forecast, spending per year, most expensive subscriptions,
  subscription status, totals by frequency.
- **Filters** on the main page: status, category, search by name.

## 6. Languages and Settings

- Available languages: **Greek, English, German**. A visitor can switch
  language from the menu at the top right (this only affects them, via a cookie).
- The **Settings** page (login required to save) sets, for everyone:
  the default language, the theme (light / dark / automatic) and the default
  filter of the subscription list (status and category). The default filter
  only applies when the list is opened without a filter chosen.
- To add a language: copy `lang/en.php` to `lang/<code>.php`, translate it and
  add it to the `SUPPORTED_LANGUAGES` constant in `includes/i18n.php`.

## 7. Database and migrations

- The database `data/subscriptions.sqlite` is created automatically if it does
  not exist and is **not committed to git** (`.gitignore`).
- Schema changes are done with migrations (`includes/migrations.php`) that run
  automatically and never delete data. Before a migration is applied to an
  existing database, a copy is saved in `data/backups/`.

## 8. File structure

```
config.php                  settings (timezone, database path)
includes/db.php             SQLite connection (creates the database if missing)
includes/migrations.php     schema migrations (with a backup before upgrading)
includes/i18n.php           translations t() + settings (language/theme/filters)
includes/auth.php           login gate + password (hash in the database) + CSRF
includes/functions.php      calculation core + the "payment ledger"
includes/stats_helpers.php  aggregates for the dashboard
lang/el.php, en.php, de.php translations
index.php                   main subscription list
stats.php                   dashboard with charts
settings.php                settings page
login.php / logout.php
actions/*.php               management actions (POST only, CSRF + login required)
assets/style.css, assets/app.js
data/                       subscriptions.sqlite is created here automatically
```

## 9. Security

- The password is stored only as a bcrypt hash in the database, never in plain text.
- All management forms are protected with a CSRF token.
- All SQL queries use prepared statements (PDO).
- `data/` contains your SQLite file with all your data. It ships with a
  `.htaccess` that denies direct web access (Apache 2.4+). If your server is
  not Apache, make sure the folder is not reachable from the web, or place it
  outside the document root.

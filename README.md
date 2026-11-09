# Birthday Reminders — a webtrees module

A custom [webtrees](https://webtrees.net) 2.x module that emails family members a
personalised digest of **upcoming birthdays** among their relatives, and adds a
relationship-aware panel to every individual's page.

This is a fork from https://github.com/jbh4x82/wt_reminders

## What it does

- **Daily birthday digest by email.** A token-guarded cron route builds, for
  each subscriber, a digest of the birthdays coming up in their chosen window
  and emails it to them — localised to each subscriber's own webtrees language.
- **Relationship-aware.** Every birthday in the digest is annotated with **how
  that person is related to the subscriber** (e.g. *"daughter"*, *"first
  cousin"*), resolved with webtrees' relationship engine.
- **Distance filter + follow specific people.** Each subscriber chooses how
  closely related someone must be to appear, and can also **follow** specific
  individuals by name — whose birthdays then always appear, regardless of the
  distance filter.
- **Individual-page panel.** Via `ModuleSidebarInterface`, every person's page
  gains a card showing the viewer's **relationship to that person** plus a
  one-click **"remind me of this birthday"** follow toggle.
- **Self-service settings + sensible auto-subscribe.** A per-user settings page;
  a member who has never visited it is auto-subscribed with safe defaults
  (never clobbering an existing subscriber's preferences).

The interface strings are shipped in several languages (English, German, French,
Italian, Spanish).

## Requirements

- webtrees 2.2.x (PHP 8).

## Install

1. Copy this folder into your webtrees `modules_v4/` directory (e.g.
   `modules_v4/mitalteli-reminders/`).
2. Enable the module in the webtrees control panel.
3. The module self-creates its two tables on first use:

   | Table | Purpose |
   |---|---|
   | `mitalteli_reminders_subscriptions` | one row per subscriber: `gedid` (XREF), `days_ahead`, `max_distance`, `include_deceased` |
   | `mitalteli_reminders_follows` | followed birthdays: `(gedid, target)` |

## Module preferences

Edit them under **Control panel → Modules → Reminders** (the wrench icon). They are plain module settings, stored via webtrees' module preferences:

| Setting | Purpose |
|---|---|
| `cron_token` | **required** secret for the cron route — no token ⇒ the route returns 403 |
| `sidebar_mode` | how the panel on individual pages looks: `full` (default), `compact`, `collapsed` or `hidden` |
| `sidebar_user_choice` | `1` (default) lets each member override `sidebar_mode` on their own Reminders page |
| `tree` | tree name for the cron route — **leave empty to use the first/only tree** |
| `email_from` | the From address for digest emails (e.g. `reminders@example.org`) |
| `email_from_name` | the From display name (e.g. `Birthday Reminders`) |
| `email_subject_prefix` | optional brand prefixed to the subject (e.g. your site's name); empty ⇒ just *"Birthdays"* |
| `allow_recipients` | optional CSV of emails; if set, only those addresses receive mail (handy for a first live run). Empty ⇒ all subscribers |

## Cron

The cron endpoint is **POST-only**: the token never appears in a URL (so not in
access logs, browser history or `Referer`). Each subscriber is processed in its
own request, **with that subscriber's own privileges** (their account is
logged in for the duration of that request), so a digest never contains more
than that member can see on the site. Only accounts that are approved and
e-mail-verified are ever used, and the account must be linked to the
subscriber's XREF in the configured tree.

`GET /reminders-cron` only returns the session's CSRF token (webtrees requires
one on every POST). `POST /reminders-cron` takes these form fields:

| Field | Meaning |
|---|---|
| `_csrf` | the token returned by the GET (same session cookie) |
| `token` | your `cron_token` |
| `action` | `list` (subscriber XREFs), `run` (one subscriber) or `preview` (their digest as HTML, no mail) |
| `xref` | the subscriber's XREF (`run`, `preview`) |
| `send` | `1` to really send; anything else is a **dry run** (`run`) |
| `lang` | language tag to render the preview in (`preview`) |

`bin/wt-reminders-cron.php` does all of this (PHP 8.1+ CLI, no curl/wget needed),
without ever putting a secret on a command line:

```
install -m 700 bin/wt-reminders-cron.php /usr/local/bin/
install -m 600 bin/wt-reminders-cron.conf.example /etc/wt-reminders-cron.conf   # then edit it
wt-reminders-cron.php dry      # builds every digest, sends nothing
wt-reminders-cron.php send     # sends
```

```
# crontab: every day at 07:00
0 7 * * * /usr/local/bin/wt-reminders-cron.php send
```

Recommended first run: `dry`; then set `allow_recipients` to your own address
and run `send` (it goes only to you); then clear `allow_recipients`.

## Licence

GPL-3.0-or-later, matching webtrees.

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

These are plain module settings (stored via webtrees' module preferences):

| Setting | Purpose |
|---|---|
| `cron_token` | **required** secret for the cron route — no token ⇒ the route returns 403 |
| `run_as_user_id` | the user id the cron runs as (a tree member/admin), so webtrees privacy lets the engine read relationships on a private tree |
| `tree` | tree name for the cron route — **leave empty to use the first/only tree** |
| `email_from` | the From address for digest emails (e.g. `reminders@example.org`) |
| `email_from_name` | the From display name (e.g. `Birthday Reminders`) |
| `email_subject_prefix` | optional brand prefixed to the subject (e.g. your site's name); empty ⇒ just *"Birthdays"* |
| `allow_recipients` | optional CSV of emails; if set, only those addresses receive mail (handy for a first live run). Empty ⇒ all subscribers |

## Cron

Call the cron route once a day from your scheduler:

```
GET /reminders-cron?token=YOUR_TOKEN&send=1
```

Without `&send=1` it performs a **dry run** — it builds and logs previews but
sends no mail. Recommended first run:

1. Set `cron_token`; hit the URL **without** `send=1` and review the previews.
2. Set `allow_recipients` to your own address; run with `send=1` (the first live
   send then goes only to you).
3. Clear `allow_recipients` to go live for everyone.

You can also preview a single subscriber's digest in any language (no mail sent):

```
GET /reminders-cron?token=YOUR_TOKEN&preview_xref=X123&lang=de
```

## Licence

GPL-3.0-or-later, matching webtrees.

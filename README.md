Module for webtrees genealogy software: Event Reminders.
===============================================================

[![Latest Release](https://img.shields.io/github/release/elysch/webtrees-mitalteli-reminders.svg)][1]
[![webtrees major version](https://img.shields.io/badge/webtrees-v2.2.6-green)][2]
[![webtrees major version](https://img.shields.io/badge/webtrees-v2.3.x-green)][2]
[![Downloads](https://img.shields.io/github/downloads/elysch/webtrees-mitalteli-reminders/total.svg)]()
[![image](https://img.shields.io/github/downloads/elysch/webtrees-mitalteli-reminders/latest/total)][1]

[![paypal](https://www.paypalobjects.com/en_US/i/btn/btn_donateCC_LG.gif)](https://www.paypal.com/donate/?business=EU37HN97QD9EU&no_recurring=0&currency_code=MXN)


Description
------------

A custom [webtrees](https://webtrees.net) 2.x module that emails family members a
personalised digest of **upcoming events** among their relatives (birthdays and,
optionally, wedding anniversaries), and adds a relationship-aware panel to every
individual's page.

This is a fork from https://github.com/jbh4x82/wt_reminders

The module is listed as **⛮ Event reminders** (the ⛮ prefix, U+26EE, keeps it sorted
next to the author's other modules in the module list).
## What it does

- **Daily event digest by email.** A token-guarded cron endpoint builds, for
  each subscriber, a digest of the events coming up in their chosen window
  (**0 days = the same day only**, up to 7 days) and emails it to them — localised
  to each subscriber's own webtrees language.
- **Birthdays and wedding anniversaries.** Birthdays are always included. Each
  member can also tick **Include wedding anniversaries**: a marriage is listed
  when at least one of the spouses is within the member's distance limit (and not
  only deceased people, unless the member includes deceased people). A member's
  own birthday and own wedding anniversary are never listed.
- **Relationship-aware.** Every event in the digest is annotated with **how
  that person is related to the subscriber** (e.g. *"daughter"*, *"first
  cousin"*), resolved with webtrees' relationship engine.
- **Distance filter + follow specific people.** Each subscriber chooses how
  closely related someone must be to appear, and can also **follow** specific
  individuals by name — whose birthdays then always appear, regardless of the
  distance filter. Each followed person can also have **their own "days ahead"**
  (0 = same day, up to 7) that replaces the general one for that person; "Use my
  default" follows the general setting. (Following applies to birthdays only.)
- **Upcoming events list.** The member's page lists the events of the next month
  that the digest would mention. Each entry shows the person, then the **date of
  the original event** (birth or marriage date), then the relationship.
- **Individual-page panel.** Via `ModuleSidebarInterface`, every person's page
  gains a card showing the viewer's **relationship to that person** plus a
  one-click **"remind me of this birthday"** follow toggle.
- **Self-service settings + sensible auto-subscribe.** A per-user settings page;
  a member who has never visited it is auto-subscribed with safe defaults
  (never clobbering an existing subscriber's preferences).

The interface strings are shipped in Catalan, Dutch, French, German, Italian and
Spanish (`resources/lang/<language>.php`, one file per language).

Requirements
---------------

- webtrees 2.2.6 or newer, including the 2.3 development line (PHP 8.3+, as webtrees requires).

The module registers no routes of its own: every page is a *module action* served
by webtrees' built-in module route (`/module/_mitalteli-reminders_/<Action>[/<tree>]`),
the part of the routing API that is the same in 2.2 and 2.3.

Installation & upgrading
------------------------

1. Copy this folder into your webtrees `modules_v4/` directory (e.g.
   `modules_v4/mitalteli-reminders/`).
2. Enable the module in the webtrees control panel.
3. The module self-creates (and upgrades) its two tables on first use:

   | Table | Purpose |
   |---|---|
   | `mitalteli_reminders_subscriptions` | one row per subscriber: `gedid` (XREF), `days_ahead`, `max_distance`, `include_deceased`, `include_marriages` |
   | `mitalteli_reminders_follows` | followed birthdays: `(gedid, target, days_ahead)` (`days_ahead` NULL = the member's general setting) |

Module preferences (administration)
-----------------------------------

Edit them under **Control panel → Modules → ⛮ Event reminders** (the wrench icon). They are plain module settings, stored via webtrees' module preferences:

| Setting | Purpose |
|---|---|
| `cron_token` | **required** secret for the cron endpoint — no token ⇒ the endpoint returns 403 |
| `sidebar_mode` | how the panel on individual pages looks: `full` (default), `compact`, `collapsed` or `hidden` |
| `sidebar_user_choice` | `1` (default) lets each member override `sidebar_mode` on their own page |
| `tree` | tree name for the cron endpoint — **leave empty to use the first/only tree** |
| `email_from` | the From address for digest emails (e.g. `reminders@example.org`) |
| `email_from_name` | the From display name (e.g. `Event Reminders`) |
| `email_subject_prefix` | optional brand prefixed to the subject (e.g. your site's name); empty ⇒ just *"Event reminders"* |
| `allow_recipients` | optional CSV of emails; if set, only those addresses receive mail (handy for a first live run). Empty ⇒ all subscribers |

### The cron token

- Leave the token field empty and save: the module generates a random token.
  **A generated token is displayed exactly once**, inside the *Cron configuration
  file* box of the settings page that appears right after saving (a plain
  selectable text box, not a form field). Copy it then.
- The box has a **Copy** button that copies its whole content to the clipboard.
- From then on the box only shows a marker (`<<< PASTE YOUR CRON TOKEN HERE >>>`)
  where the token goes. If you lose the token, tick *Generate a new random token*
  and save; the old token stops working.
- You can also paste your own token (32–128 characters: letters, digits, `-`, `_`);
  it is never displayed back.

Cron
----

The cron endpoint is **POST-only**: the token never appears in a URL (so not in
access logs, browser history or `Referer`). Each subscriber is processed in its
own request, **with that subscriber's own privileges** (their account is
logged in for the duration of that request), so a digest never contains more
than that member can see on the site. Only accounts that are approved and
e-mail-verified are ever used, and the account must be linked to the
subscriber's XREF in the configured tree.

`GET <cron endpoint>` only returns the session's CSRF token (webtrees requires
one on every POST). `POST <cron endpoint>` takes these form fields:

| Field | Meaning |
|---|---|
| `_csrf` | the token returned by the GET (same session cookie) |
| `token` | your `cron_token` |
| `action` | `list` (subscriber XREFs), `run` (one subscriber) or `preview` (their digest as HTML, no mail) |
| `xref` | the subscriber's XREF (`run`, `preview`) |
| `send` | `1` to really send; anything else is a **dry run** (`run`) |
| `lang` | language tag to render the preview in (`preview`) |

`bin/webtrees-reminders-cron.php` does all of this (PHP 8.1+ CLI with the `openssl`
extension; no curl/wget needed), without ever putting a secret on a command line.

### Step 1 — get the two values from the module settings

1. In webtrees open **Control panel → Modules → ⛮ Event reminders** (wrench icon).
2. Copy the value of the **Cron endpoint** field (in Spanish: *Endpoint del cron*).
   It is the value of `CRON_URL`. Do not build the URL by hand.
3. Save the settings with the token field empty (or tick *Generate a new random
   token*) and copy the token from the *Cron configuration file* box. It is shown
   only once. It is the value of `CRON_TOKEN`.

The configuration file (`.conf`) must look like this
(see `bin/webtrees-reminders-cron.conf.example`):

```
CRON_URL="<the value of Cron endpoint>"
CRON_TOKEN="<the token shown once by the module settings>"
```

### Step 2 — Linux / macOS

```
install -m 700 bin/webtrees-reminders-cron.php /usr/local/bin/
install -m 600 bin/webtrees-reminders-cron.conf.example /etc/webtrees-reminders-cron.conf
# edit /etc/webtrees-reminders-cron.conf: paste CRON_URL and CRON_TOKEN
webtrees-reminders-cron.php dry      # builds every digest, sends nothing
webtrees-reminders-cron.php send     # sends
```

The configuration file must be owned by the user that runs the script and have
mode `600` or `400`, otherwise the script refuses to read it. To keep it somewhere
else, set the `WEBTREES_REMINDERS_CONFIG` environment variable.

```
# crontab -e   (every day at 07:00)
0 7 * * * /usr/local/bin/webtrees-reminders-cron.php send
```

### Step 2 — Windows

1. Install PHP 8.1+ for Windows and enable `extension=openssl` in `php.ini`. For
   HTTPS also set `openssl.cafile` to a CA bundle (e.g. `cacert.pem`), otherwise
   the certificate check fails.
2. Copy `bin\webtrees-reminders-cron.php` to a folder such as `C:\webtrees-reminders\`.
3. Create the configuration file `%APPDATA%\webtrees-reminders-cron.conf` (of the
   user that will run the scheduled task) with the two values from step 1, and make
   it private (Command Prompt):

   ```
   icacls "%APPDATA%\webtrees-reminders-cron.conf" /inheritance:r /grant:r "%USERNAME%:R"
   ```

   Windows has no `600` mode, so the script does not check the file mode there.
   Set `WEBTREES_REMINDERS_CONFIG` to keep the file somewhere else.
4. Test it:

   ```
   "C:\php\php.exe" "C:\webtrees-reminders\webtrees-reminders-cron.php" dry
   ```

5. Schedule it every day at 07:00 with Task Scheduler (Command Prompt):

   ```
   schtasks /Create /SC DAILY /ST 07:00 /TN "webtrees-event-reminders" /TR "\"C:\php\php.exe\" \"C:\webtrees-reminders\webtrees-reminders-cron.php\" send"
   ```

### Recommended first run

`dry`; then set `allow_recipients` to your own address and run `send` (it goes
only to you); then clear `allow_recipients`.

### Diagnostics

Whenever a request does not return HTTP 200 the script prints one line to STDERR
with the failing step, the HTTP status and a hint (wrong URL, wrong token,
redirect, server error…), in both `dry` and `send` modes, and exits with status 1.
The token, the CSRF token and the response body are never printed in `send` mode
(cron usually mails that output, and a server error page can leak paths or
versions). In `dry` mode, run by hand, it additionally prints the URL, the
transport error and a sanitised excerpt of the response body.

Licence
-------

GPL-3.0-or-later, matching webtrees.

[1]: https://github.com/elysch/webtrees-mitalteli-reminders/releases/latest
[2]: https://webtrees.net

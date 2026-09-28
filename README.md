# Check Page Type Statuses

A Silverstripe BuildTask that provides a visual interface for testing all page types in your site. Useful for verifying pages work correctly after upgrades or major changes.

![Check Page Type Statuses](docs/screenshot.png)

## Features

- Lists all page types with CMS edit and frontend links
- Automatically checks HTTP status codes for all links
- Detects and tests controller `$allowed_actions`
- Detects forms on pages (flags for manual testing)
- "Create" buttons to add a test page for any page type that has none, and delete them again afterwards
- Tick off each page type and admin section as you test it by hand, with a progress count and a filter to hide the ones already done
- "Open All" buttons to open CMS or frontend links in new tabs
- Optional page preview thumbnails
- Randomise selected pages for broader testing
- Works in both browser and CLI

## Requirements

- Silverstripe 6.0+
- PHP 8.1+

## Installation

```bash
composer require purplespider/silverstripe-page-type-status-checker
```

## Usage

### Browser (recommended)

Visit `/dev/tasks/check-page-type-statuses` in your browser while logged in as admin. Checks start automatically.

### CLI

```bash
vendor/bin/sake tasks:check-page-type-statuses
```

The CLI has no CMS session, so CMS and admin URLs will always redirect to the login screen. 

The command exits with a non-zero status if any check fails, so it can be used in a pipeline.

### Options

| Option | Description |
| --- | --- |
| `--skip-actions` | Skip checking `allowed_actions` URLs |
| `--skip-admin` | Skip ModelAdmin sections and SiteConfig |
| `--randomise` | Pick a random example page per type instead of the first |
| `--live-domain=URL` | Add "Live CMS" and "Live Page" comparison links, and a side-by-side Compare button |
| `--verify-ssl=0\|1` | Verify TLS certificates. Defaults to off in dev mode, on elsewhere |

All options also work as query string parameters in the browser.

## Configuration

```yaml
PurpleSpider\PageTypeTester\ActionLinkFinder:
  # Actions that resolve without an ID, so a URL can be built even when the page
  # contains no link to them.
  direct_actions:
    - rss
    - index

PurpleSpider\PageTypeTester\Report\HtmlReport:
  # How many checks the browser runs at once. Lower this for slow or rate-limited sites.
  check_concurrency: 6
```

## How it decides what passed

- Redirects are **not** followed. A page that redirects is reported with its own status rather than the status of wherever it points, so a page silently redirecting to the home page shows as a failure rather than a pass. The CLI and browser reports agree on this.
- `ErrorPage` is expected to return 404 or 500, `RedirectorPage` a 3xx, and everything else a 200.
- An action URL is only matched when it sits beneath the page's own URL. Links elsewhere on the page (navigation, footer) are ignored, since those belong to other page types.
- Where no link to an action is found, it is reported as needing a manual check rather than passed or failed.

## Comparing against the live site

Setting a live domain adds a "Live CMS" and "Live Page" link beside each local link, and a **Compare** button after each pair. Compare opens the local and live version of the same thing in two windows, side by side, half the screen each.

### Why do the live CMS links ask me to log in? (Silverstripe 6+)

If the LIVE site is on Silverstripe 6, and you add it's URL for previewing, you may keep getting asked to login every time you click a "Live CMS" link. This is because starting in Silverstripe 6, the default value of `Session.cookie_samesite` changed from `Lax` to `Strict`, which tells the browser to withhold the login cookie on any navigation *initiated by another site*.

To fix it, set the pre-6.0 behaviour on the site being linked to:

```yaml
SilverStripe\Control\Session:
  cookie_samesite: 'Lax'
```

Lax still sends the session cookie on top-level cross-site GET navigations, which is the link-click case, while withholding it on cross-site POSTs and subresource loads. It was Silverstripe's own default until 6.0, and state-changing requests are protected by `SecurityToken` regardless. There is no middle setting: SameSite has no per-origin allowlist, and `None` is weaker still.

Otherwise, just log in when you land there - the redirect carries a `BackURL`, so you end up on the page you clicked for. Live frontend links are unaffected.

## Tracking what you have tested

The status checks only prove a page responds. Each page type and admin section also has a **Tested** checkbox for marking it once you have been through it by hand. Each table shows how many are done and has its own **Hide Tested** button, which leaves only the ones still to do. Hovering the date under a tick shows exactly when it was ticked.

Ticks are per page type rather than per page, so they survive Randomise picking a different example. Types with no pages can be ticked too, for example once you have created one and checked it, or decided the type is unused. They are stored in your browser's local storage, which means:

- nothing to install and no database table
- they survive logging out, and pulling a fresh copy of the live database midway through testing
- they belong to that browser only, so they are not shared with colleagues or between browsers

Use a table's **Clear** button to start that table again, for example before the next upgrade.

## Creating and deleting pages

Page types with no instances get a "Create" button, so a type can be checked without hand-building a page in the CMS. 

Deleting archives the page.

## License

BSD-3-Clause

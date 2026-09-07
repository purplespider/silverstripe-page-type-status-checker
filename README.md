# Check Page Type Statuses

A Silverstripe BuildTask that provides a visual interface for testing all page types in your site. Useful for verifying pages work correctly after upgrades or major changes.

![Check Page Type Statuses](docs/screenshot.png)

## Features

- Lists all page types with CMS edit and frontend links
- Automatically checks HTTP status codes for all links
- Shows page count per type (live + draft) sorted by count
- Handles ErrorPage (expects 404/500) and RedirectorPage (expects a redirect)
- Visual status indicators, with an icon as well as a colour so pass/fail is never signalled by colour alone
- Detects when you are not logged in to the CMS and says so, rather than reporting every CMS URL as a 302 failure
- Status badges are buttons: click or tab to them and press Enter to re-check a single link
- Detects and tests controller `$allowed_actions`
- Detects forms on pages (flags for manual testing)
- "Open All" buttons to open CMS or frontend links in new tabs
- Optional page preview thumbnails
- Randomise selected pages for broader testing
- Progress indicator with stop functionality
- Prioritises live pages over draft pages
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

Click "Check Links & Actions" to check all links including controller actions, or "Check Links Only" for a faster check of just the main pages. While checks are running, the active button becomes a Stop control.

If you are not logged in to the CMS, every CMS and admin URL redirects to the login screen. Rather than filling the table with 302s, the task detects this, shows a banner with a "Log in to the CMS" button, and marks those rows `login` instead of failed. Frontend pages are still checked as normal.

### CLI

```bash
vendor/bin/sake tasks:check-page-type-statuses
```

The CLI has no CMS session, so CMS and admin URLs will always redirect to the login screen. These are reported as `login required` rather than counted as passed or failed. Use the browser version to check them properly.

The command exits with a non-zero status if any check fails, so it can be used in a pipeline.

### Options

| Option | Description |
| --- | --- |
| `--skip-actions` | Skip checking `allowed_actions` URLs |
| `--skip-admin` | Skip ModelAdmin sections and SiteConfig |
| `--randomise` | Pick a random example page per type instead of the first |
| `--live-domain=URL` | Add "Live CMS" and "Live Page" comparison links |
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

## Creating pages

Page types with no instances get a "Create" button. Creating a page:

- requires POST with a valid security token, so it cannot be triggered by following a URL, by a crawler, or by browser prefetch;
- respects the page type's `canCreate()`, so page types that restrict creation (for example to enforce a single instance) are not bypassed.

## Development

`PageTypeTesterTask` handles options and orchestration only. The collectors gather page types and admin sections, `UrlChecker` performs the requests, and the two classes under `Report/` render the terminal and browser output from the same data.

The browser report writes a JSON config block and loads `client/dist/page-type-tester.js`, so no server data is interpolated into JavaScript. Those client files are plain CSS and JS exposed via `extra.expose`; if either 404s after a change, run `composer vendor-expose`.

## License

BSD-3-Clause

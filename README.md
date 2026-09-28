# Check Page Type Statuses

A Silverstripe BuildTask that provides a visual interface for testing all page types in your site. Useful for verifying pages work correctly after upgrades or major changes.

![Check Page Type Statuses](docs/screenshot.png)

## Features

- Lists all page types with CMS edit and frontend links
- Automatically checks HTTP status codes for all links
- Detects and tests controller `$allowed_actions`
- Detects forms on pages (flags for manual testing)
- Flags page types, block types and admin sections whose code sends email, and says where, so you know which forms to submit and which inboxes to check
- With [Elemental](https://github.com/silverstripe/silverstripe-elemental) installed, checks one block of each block type: its summary in the CMS block list, its CMS edit form, and how it renders on its own. Also checks each page's block list along with its CMS edit form
- "Create" buttons to add a test page for any page type, or a test block for any block type, that has none, and delete them again afterwards
- Tick off each page type and admin section as you test it by hand, with a progress count and a filter to hide the ones already done
- "Open All" buttons above each table, to open that table's CMS or frontend links in new tabs
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
| `--skip-blocks` | Skip the Elemental block types |
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

PurpleSpider\PageTypeTester\Report\HtmlReport:
  # How many checks the browser runs at once. Lower this for slow or rate-limited sites.
  check_concurrency: 6

PurpleSpider\PageTypeTester\EmailUsageFinder:
  # Classes whose use counts as sending email, subclasses included. Add a third-party
  # mail client here if the site sends through one directly.
  mail_classes:
    - SilverStripe\Control\Email\Email
    - Symfony\Component\Mailer\MailerInterface
  # Global functions that send email.
  mail_functions:
    - mail
  # How many classes deep to follow from a type's own code into the site's other classes.
  reference_depth: 2
```

## How it decides what passed

- Redirects are **not** followed. A page that redirects is reported with its own status rather than the status of wherever it points, so a page silently redirecting to the home page shows as a failure rather than a pass. The CLI and browser reports agree on this.
- `ErrorPage` is expected to return 404 or 500, `RedirectorPage` a 3xx, and everything else a 200.
- An action URL is only matched when it sits beneath the page's own URL. Links elsewhere on the page (navigation, footer) are ignored, since those belong to other page types.
- Where no link to an action is found, it is reported as needing a manual check rather than passed or failed.
- An action that is a form on the page (the form's id is `Form_{Action}`, or it submits to `.../{Action}`) is shown once, as a "form action" linking to the form, rather than as a separate action to check. `index` is never listed, as it is the page itself.
- On a page with Elemental blocks, the CMS check also requests each of the page's block lists (`admin/elemental-area/api/readElements/{id}`). The edit form itself answers 200 even when its blocks editor cannot load, because the block list is fetched afterwards, so a block that fails there would otherwise go unnoticed.

### Blocks

Each block type is checked through one example block, preferring a published one that sits on a page. All three checks expect a 200.

- **CMS Summary** builds the block's entry in its page's CMS block list, making the same calls the CMS makes, including the block's `getSummary()`, which is what usually fails. A block that throws here stops the whole block list loading. Elemental has no URL that lists a single block, so the task answers this itself. A failure shows the exception message.
- **CMS Edit Form** requests the block's standalone edit form (`getCMSEditLink(true)`), as opposed to the inline one in the blocks editor.
- **Frontend** renders the block on its own, through the task, with its page set up as the current page. Elemental's own `/element/{id}` route answers 200 without rendering the block, so it cannot be used. Rendering errors are left to Silverstripe's error handling, so a broken block answers with a real 500, and opening the link in dev mode shows the full error.

The CMS checks, and the frontend of a block that is only in draft, need a CMS login. Like the rest of the task these URLs are open to anyone in dev mode and need ADMIN elsewhere, and they read nothing in draft without CMS access.

## Code that sends email

A page can answer 200 while the email its form sends never arrives, and after an upgrade that is one of the first things to break, especially one past Silverstripe 5, which replaced SwiftMailer with Symfony Mailer and its config. So each page type, block type and admin section whose code sends email lists where, as an **email** entry in the same list as its actions and forms, labelled with the method that sends it. Hovering or tabbing to the badge shows the class, file and line, what it sends with, and how it was reached. Block types show the list under their frontend link. In admin sections, a record's usages sit beside its edit form, where saving one would be tested, and the admin's own under its section link. The CLI prints the same beneath each type.

This reads the source rather than running it, so nothing is sent to find out. It counts as sending email:

- creating an `Email` or a subclass of it (`Email::create()`, `new Email()`, `Injector::inst()->create(Email::class)`), or taking one as a parameter, as an `updateEmail()` hook does
- using Symfony's `MailerInterface`
- calling `mail()`

Static helpers such as `Email::is_valid_address()` do not count.

What is read for each type:

- **Page types:** the page class and its controller, and their parents up to `Page` and `PageController`. A type extending `UserDefinedForm` is flagged through `UserDefinedFormController`, but a form every page has through `PageController`, such as a newsletter signup in the footer, is flagged once, on `Page`, rather than on every type
- **Block types:** the block class and its `controller_class`, up to `BaseElement` and `ElementController`
- **Admin sections:** the `ModelAdmin`, and separately each class it manages, such as a DataObject that sends a notification in `onAfterWrite()`. A managed class with no record to link to is listed by name in the Edit Form column. Settings reads `SiteConfig`
- extensions applied directly to any of those classes, and traits they use

From there it follows the other classes that code uses, such as a form class the controller builds or a notification service the form calls, up to two classes deep (`reference_depth`). These show as "via ContactPageController > EnquiryForm". Only the site's own code is followed, not `vendor`, or everything that touches `Member` would be flagged.

It shows where email could be sent, not that it is: a UserDefinedForm is flagged whether or not any recipients are set up in the CMS. Code it cannot see, such as a call made through a string or a closure passed in from elsewhere, is missed.

## Comparing against the live site

Setting a live domain adds a "Live CMS" and "Live Page" link beside each page type's local links, and a "Live CMS" link beside each block type's edit form, with a **Compare** button after each pair. The live links reuse the local record IDs, so they are only right when the local database is a copy of live. Compare opens the local and live version of the same thing in two windows, side by side, half the screen each.

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

The status checks only prove a page responds. Each page type, block type and admin section also has a **Tested** checkbox for marking it once you have been through it by hand. Each table shows how many are done and has its own **Hide Tested** button, which leaves only the ones still to do. Hovering the date under a tick shows exactly when it was ticked.

Ticks are per page type rather than per page, so they survive Randomise picking a different example. Types with no pages can be ticked too, for example once you have created one and checked it, or decided the type is unused. They are stored in your browser's local storage, which means:

- nothing to install and no database table
- they survive logging out, and pulling a fresh copy of the live database midway through testing
- they belong to that browser only, so they are not shared with colleagues or between browsers

Use a table's **Clear** button to start that table again, for example before the next upgrade.

## Creating and deleting pages

Page types with no instances get a "Create" button, so a type can be checked without hand-building a page in the CMS. 

Block types with no blocks on a page get one too. A block has to sit on a page, and adding one to an existing page would change real content, so this creates a draft test page to hold it: the most used page type that allows that block. Both stay in draft.

Deleting archives the page, along with any blocks on it. Elemental does not do this itself, so without it a deleted page would leave its blocks behind.

## License

BSD-3-Clause

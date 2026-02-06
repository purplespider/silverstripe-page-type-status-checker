# Silverstripe Page Type Status Checker

A Silverstripe BuildTask that provides a visual interface for testing all page types in your site. Useful for verifying pages work correctly after upgrades or major changes.

![Check Page Type Statuses](docs/screenshot.png)

## Features

- Lists all page types with CMS edit and frontend links
- Automatically checks HTTP status codes for all links
- Shows page count per type (live + draft) sorted by count
- Handles ErrorPage correctly (expects 404/500)
- Visual status indicators (✓ pass / ✗ fail)
- Click status badges to re-check individual links
- Detects and tests controller `$allowed_actions`
- Detects forms on pages (flags for manual testing)
- "Open All" buttons to open CMS or frontend links in new tabs
- Optional page preview thumbnails
- Randomise selected pages for broader testing
- Progress indicator with stop functionality
- Prioritises live pages over draft pages
- Responsive design for narrower screens
- Works in both browser and CLI

## Requirements

- Silverstripe 6.0+
- PHP 8.1+

## Installation

```bash
composer require purplespider/silverstripe-page-type-status-checker
```

## Usage

### Browser (Recommended)

Visit `/dev/tasks/check-page-type-statuses` in your browser while logged in as admin.

Click "Check Links & Actions" to check all links including controller actions, or "Check Links Only" for a faster check of just the main pages.

The task will display:
- Page type names with counts (live + draft)
- CMS edit form links with status codes
- Frontend links with status codes
- Controller actions with status codes (if `$allowed_actions` defined)
- Form detection indicators
- Example page titles and URLs

### CLI

```bash
vendor/bin/sake tasks:check-page-type-statuses
```

The CLI output shows a simplified list of all page types with their CMS and frontend URLs.

## License

BSD-3-Clause

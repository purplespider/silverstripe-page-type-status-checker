<?php

namespace PurpleSpider\PageTypeTester;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\VersionProvider;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * What the report was run against: which site, and which Silverstripe version.
 *
 * The whole point of the task is checking a site after an upgrade, so both belong on
 * the report itself rather than being something you have to go and look up separately.
 * Either can come back empty, in which case nothing is rendered for it.
 */
class ReportMeta
{
    /**
     * The SiteConfig title, e.g. "Sympatric".
     */
    public static function siteName(): string
    {
        if (!class_exists(SiteConfig::class)) {
            return '';
        }

        // Deliberately not SiteConfig::current_site_config(), which writes a default
        // record when none exists. A report should not create data as a side effect.
        return trim((string) (SiteConfig::get()->first()?->Title ?? ''));
    }

    /**
     * e.g. "Silverstripe 5.4.2". Empty when the version cannot be determined, which
     * happens if composer.lock is not shipped with the site.
     */
    public static function cmsVersion(): string
    {
        $version = Injector::inst()->get(VersionProvider::class)->getModuleVersion('silverstripe/framework');

        return $version === '' ? '' : 'Silverstripe ' . $version;
    }
}

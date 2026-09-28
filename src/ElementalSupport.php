<?php

namespace PurpleSpider\PageTypeTester;

use DNADesign\Elemental\Controllers\ElementalAreaController;
use DNADesign\Elemental\Models\BaseElement;
use SilverStripe\Admin\AdminRootController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Config\Config;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

/**
 * The places the report touches Elemental.
 *
 * Elemental is optional. Everything that names one of its classes checks
 * isInstalled() first, so a site without it never loads them.
 */
class ElementalSupport
{
    public static function isInstalled(): bool
    {
        return class_exists(BaseElement::class);
    }

    /**
     * The URLs the blocks editor loads each of the page's block lists from.
     *
     * The page's own CMS URL returns 200 even when these fail, because the block list
     * is fetched afterwards. A block that throws while being listed breaks the whole
     * editor, so the page's CMS check has to include them to mean anything.
     *
     * @return string[]
     */
    public static function blockListUrlsFor(SiteTree $page): array
    {
        if (!static::isInstalled() || !$page->hasMethod('getElementalRelations')) {
            return [];
        }

        $relations = $page->getElementalRelations();
        if (!$relations) {
            return [];
        }

        $segment = (string) Config::inst()->get(ElementalAreaController::class, 'url_segment');

        $urls = [];
        foreach ($relations as $relation) {
            $areaId = (int) $page->getField($relation . 'ID');

            // A page that has never been saved since Elemental was added has no area yet,
            // and so nothing for the editor to load.
            if ($areaId > 0) {
                $urls[] = Director::absoluteURL(
                    AdminRootController::admin_url(Controller::join_links($segment, 'api/readElements', $areaId))
                );
            }
        }

        return $urls;
    }

    /**
     * Sends anyone without CMS access to the login screen, before any draft or CMS data
     * is read.
     *
     * A redirect rather than a 403, so both reports see it as "log in first" rather than
     * as the block failing. In dev mode dev/tasks is open to anyone, so this is the only
     * thing standing between a visitor and draft content.
     *
     * @throws HTTPResponse_Exception when access is denied.
     */
    public static function requireCmsAccess(HTTPRequest $request): void
    {
        if (Permission::check('CMS_ACCESS_CMSMain')) {
            return;
        }

        $loginUrl = Controller::join_links(
            Director::absoluteURL(Security::login_url()),
            '?BackURL=' . urlencode((string) Director::absoluteURL($request->getURL(true)))
        );

        throw new HTTPResponse_Exception(HTTPResponse::create()->redirect($loginUrl, 302));
    }

    /**
     * A URL back into this task that handles one block.
     *
     * Built from the task's own path rather than the current request, so options such
     * as ?randomise=1 on the report do not end up on every block URL.
     */
    public static function taskEndpointUrl(string $param, int $id): string
    {
        return Controller::join_links(
            Director::absoluteBaseURL(),
            'dev/tasks',
            PageTypeTesterTask::getNameWithoutNamespace()
        ) . '?' . http_build_query([$param => $id]);
    }
}

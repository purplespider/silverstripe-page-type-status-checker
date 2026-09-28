<?php

namespace PurpleSpider\PageTypeTester;

use Page;
use PurpleSpider\PageTypeTester\Collector\PageTypeCollector;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Security\SecurityToken;
use Throwable;

/**
 * Handles the "create a page of this type" button.
 *
 * This writes to the database, so unlike the rest of the task it is not something that
 * should happen on the strength of a URL alone. It requires POST, a valid security
 * token, and the current member's permission to create that page type.
 */
class PageCreator
{
    use RespondsWithJson;

    public const PARAM = 'createPage';

    /**
     * Creates the page and terminates the request with a JSON response.
     *
     * @throws HTTPResponse_Exception always - this never returns normally.
     */
    public function handle(HTTPRequest $request, string $className): void
    {
        // A GET that writes to the database can be triggered by anything that follows a
        // link, including browser prefetch and crawlers.
        if (!$request->isPOST()) {
            $this->respond(['success' => false, 'error' => 'Page creation must be sent as POST'], 405);
        }

        if (!SecurityToken::inst()->checkRequest($request)) {
            $this->respond(['success' => false, 'error' => 'Invalid or missing security token'], 403);
        }

        if (!class_exists($className) || !is_subclass_of($className, Page::class)) {
            $this->respond(['success' => false, 'error' => 'Invalid page type'], 400);
        }

        $singleton = $className::singleton();

        // Page types commonly restrict creation, for example to enforce that only one
        // instance may exist. Writing directly would quietly break that guarantee.
        if (!$singleton->canCreate()) {
            $shortName = ClassInfo::shortName($className);
            $this->respond([
                'success' => false,
                'error' => "You do not have permission to create a {$shortName}, or the type does not allow it",
            ], 403);
        }

        try {
            $this->respond($this->createPage($request, $className), 200);
        } catch (HTTPResponse_Exception $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->respond(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function createPage(HTTPRequest $request, string $className): array
    {
        $shortName = ClassInfo::shortName($className);

        /** @var SiteTree $page */
        $page = $className::create();
        $page->Title = 'New ' . $shortName;
        $page->write();

        // Remembered so the report can offer to delete it again.
        (new CreatedPageRegistry($request))->add((int) $page->ID);

        $baseUrl = Director::absoluteBaseURL();
        $frontendLink = (string) $page->AbsoluteLink();

        return [
            'success' => true,
            'id' => $page->ID,
            'title' => $page->Title,
            'shortClass' => $shortName,
            'editLink' => Controller::join_links($baseUrl, 'admin/pages/edit/show', $page->ID),
            'frontendLink' => $frontendLink,
            'pageUrl' => '/' . ltrim(str_replace(rtrim($baseUrl, '/'), '', $frontendLink), '/'),
            'allowedActions' => PageTypeCollector::allowedActionsFor($className),
            'expectedStatus' => ExpectedStatus::forShortName($shortName),
            'blockListUrls' => ElementalSupport::blockListUrlsFor($page),
            'cmsScreenChecks' => PageTypeCollector::cmsScreenChecksFor($page),
            'gridFields' => GridFieldFormFinder::gridFieldsFor($page),
        ];
    }
}

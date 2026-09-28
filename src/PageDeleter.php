<?php

namespace PurpleSpider\PageTypeTester;

use Page;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;
use Throwable;

/**
 * Handles the "delete" button on a page this report created.
 *
 * Test pages made by the Create buttons are litter once the check has been run, and
 * clearing them out by hand in the CMS is tedious. This removes them again from the
 * report, under the same protections as PageCreator plus one more: the page must be
 * one this tool created during this session, so the endpoint can never be turned on
 * pre-existing content.
 *
 * Deleting archives the page, which is what the CMS delete button does. It comes off
 * draft and live but stays recoverable, so a misclick is not final. Any Elemental
 * blocks on the page are archived with it, which is how the test pages BlockCreator
 * makes are cleared away.
 */
class PageDeleter
{
    use RespondsWithJson;

    public const PARAM = 'deletePage';

    /**
     * Deletes the page and terminates the request with a JSON response.
     *
     * @throws HTTPResponse_Exception always - this never returns normally.
     */
    public function handle(HTTPRequest $request, string $id): void
    {
        if (!$request->isPOST()) {
            $this->respond(['success' => false, 'error' => 'Page deletion must be sent as POST'], 405);
        }

        if (!SecurityToken::inst()->checkRequest($request)) {
            $this->respond(['success' => false, 'error' => 'Invalid or missing security token'], 403);
        }

        $pageId = (int) $id;
        $registry = new CreatedPageRegistry($request);

        if ($pageId <= 0 || !$registry->has($pageId)) {
            $this->respond([
                'success' => false,
                'error' => 'That page was not created here, so it cannot be deleted from this report',
            ], 403);
        }

        try {
            $page = $this->findDraftPage($pageId);

            // Already gone, deleted in the CMS since it was created. Forget it rather
            // than reporting a failure the user can do nothing about.
            if (!$page) {
                $registry->forget($pageId);
                $this->respond(['success' => true, 'id' => $pageId, 'remaining' => 0], 200);
            }

            if (!$page->canDelete()) {
                $this->respond([
                    'success' => false,
                    'error' => 'You do not have permission to delete this page',
                ], 403);
            }

            $className = (string) $page->ClassName;

            // Elemental leaves a deleted page's blocks behind, so they go first. This
            // includes any test block made to sit on the page.
            ElementalSupport::archiveElementalAreas($page);
            $page->doArchive();
            $registry->forget($pageId);

            $data = [
                'success' => true,
                'id' => $pageId,
                'remaining' => $this->remainingOfType($className),
            ];

            // A block row asks how many of its type are left, for the same reason.
            $blockClass = (string) $request->postVar('blockClass');
            if ($blockClass !== '' && ElementalSupport::isBlockClass($blockClass)) {
                $data['remainingBlocks'] = $this->remainingOfType($blockClass);
            }

            $this->respond($data, 200);
        } catch (HTTPResponse_Exception $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->respond(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Created pages are drafts, which the default reading mode may not see.
     */
    private function findDraftPage(int $id): ?SiteTree
    {
        return Versioned::withVersionedMode(function () use ($id) {
            Versioned::set_stage(Versioned::DRAFT);

            return DataObject::get(Page::class)->byID($id);
        });
    }

    /**
     * How many pages, or blocks, of this type are left, so the report knows whether the
     * row can go back to its "create one" prompt or should reload to show another
     * example.
     */
    private function remainingOfType(string $className): int
    {
        if (!class_exists($className)) {
            return 0;
        }

        return Versioned::withVersionedMode(function () use ($className) {
            Versioned::set_stage(Versioned::DRAFT);

            return DataObject::get($className)->filter('ClassName', $className)->count();
        });
    }
}

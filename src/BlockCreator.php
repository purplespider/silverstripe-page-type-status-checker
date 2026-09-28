<?php

namespace PurpleSpider\PageTypeTester;

use PurpleSpider\PageTypeTester\Collector\BlockTypeCollector;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use RuntimeException;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;
use Throwable;

/**
 * Handles the "create a block of this type" button.
 *
 * A block has to sit on a page, and adding one to an existing page would change real
 * content. So this creates a draft test page to hold it, of a page type that allows
 * the block, and puts one new block on that. The page is remembered like any other
 * page this report creates, and deleting it takes the block with it.
 *
 * Same protections as PageCreator: POST, a valid security token, and permission to
 * create both the page and the block.
 */
class BlockCreator
{
    use RespondsWithJson;

    public const PARAM = 'createBlock';

    /**
     * Creates the page and block and terminates the request with a JSON response.
     *
     * @throws HTTPResponse_Exception always - this never returns normally.
     */
    public function handle(HTTPRequest $request, string $className): void
    {
        if (!$request->isPOST()) {
            $this->respond(['success' => false, 'error' => 'Block creation must be sent as POST'], 405);
        }

        if (!SecurityToken::inst()->checkRequest($request)) {
            $this->respond(['success' => false, 'error' => 'Invalid or missing security token'], 403);
        }

        if (!ElementalSupport::isBlockClass($className)) {
            $this->respond(['success' => false, 'error' => 'Invalid block type'], 400);
        }

        $shortName = ClassInfo::shortName($className);

        if (!$className::singleton()->canCreate()) {
            $this->respond([
                'success' => false,
                'error' => "You do not have permission to create a {$shortName}",
            ], 403);
        }

        $pageClass = ElementalSupport::hostPageClassFor($className);
        if (!$pageClass) {
            $this->respond([
                'success' => false,
                'error' => "No page type you can create allows a {$shortName}",
            ], 400);
        }

        try {
            $this->respond($this->createBlock($request, $className, $pageClass), 200);
        } catch (HTTPResponse_Exception $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->respond(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function createBlock(HTTPRequest $request, string $className, string $pageClass): array
    {
        return Versioned::withVersionedMode(function () use ($request, $className, $pageClass) {
            // Written to draft only. Nothing a visitor can see changes.
            Versioned::set_stage(Versioned::DRAFT);

            $shortName = ClassInfo::shortName($className);

            /** @var SiteTree $page */
            $page = $pageClass::create();
            $page->Title = 'Test page for ' . $shortName;
            $page->write();

            // Remembered before the block is written, so a block that fails to save
            // still leaves a page the report can delete.
            (new CreatedPageRegistry($request))->add((int) $page->ID);

            // Elemental gives the page its areas as it is written. The block goes in
            // the first.
            $relations = ElementalSupport::elementalRelationsFor($page);
            $areaId = $relations ? (int) $page->getField($relations[0] . 'ID') : 0;
            if ($areaId <= 0) {
                throw new RuntimeException("The new {$page->i18n_singular_name()} has no block area");
            }

            $block = $className::create();
            $block->Title = 'New ' . $shortName;
            $block->ParentID = $areaId;
            $block->write();

            $example = (new BlockTypeCollector())->describe($block, $page, false);
            $id = (int) $block->ID;

            return [
                'success' => true,
                'shortClass' => $shortName,
                'singularName' => (string) $block->i18n_singular_name(),
                'liveCount' => (int) Versioned::get_by_stage($className, Versioned::LIVE)
                    ->filter('ClassName', $className)->count(),
                'totalCount' => (int) Versioned::get_by_stage($className, Versioned::DRAFT)
                    ->filter('ClassName', $className)->count(),
                'pageId' => (int) $page->ID,
                'title' => $example['title'],
                'pageTitle' => $example['pageTitle'],
                'pageLink' => $example['pageLink'],
                'pageCmsLink' => $example['pageCmsLink'],
                'editorCheckUrl' => ElementalSupport::taskEndpointUrl(BlockEditorChecker::PARAM, $id),
                'editFormUrl' => $example['editFormUrl'],
                'frontendUrl' => ElementalSupport::taskEndpointUrl(BlockRenderer::PARAM, $id),
                'frontendNeedsLogin' => true,
            ];
        });
    }
}

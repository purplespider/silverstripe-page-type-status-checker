<?php

namespace PurpleSpider\PageTypeTester;

use DNADesign\Elemental\Models\BaseElement;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Versioned\Versioned;
use Throwable;

/**
 * Checks that one block can be listed in the blocks editor.
 *
 * The editor loads every block on a page from a single request, so one block that
 * throws while being listed stops the whole editor loading, and there is no Elemental
 * URL that lists a single block. This makes the same calls the editor's list endpoint
 * (ElementalAreaController::apiReadElements) makes for each block, for one block only,
 * so a failure can be pinned to the block type that causes it.
 *
 * Only reads, so a GET is fine. It does read draft content, so it needs CMS access.
 */
class BlockEditorChecker
{
    use RespondsWithJson;

    public const PARAM = 'checkBlock';

    /**
     * Checks the block and terminates the request with a JSON response.
     *
     * @throws HTTPResponse_Exception always - this never returns normally.
     */
    public function handle(HTTPRequest $request, string $id): void
    {
        ElementalSupport::requireCmsAccess($request);

        Versioned::withVersionedMode(function () use ($id) {
            // The editor works on draft, so the check does too.
            Versioned::set_stage(Versioned::DRAFT);

            $element = BaseElement::get()->byID((int) $id);
            if (!$element) {
                $this->respond(['success' => false, 'error' => 'Block not found'], 404);
            }

            if (!$element->canView()) {
                $this->respond(['success' => false, 'error' => 'You do not have permission to view this block'], 403);
            }

            try {
                // Encoded here rather than in respond(), which would quietly turn a value
                // that cannot be encoded into an empty body and a pass.
                json_encode($this->listEntryFor($element), JSON_THROW_ON_ERROR);
            } catch (HTTPResponse_Exception $e) {
                throw $e;
            } catch (Throwable $e) {
                $this->respond(['success' => false, 'error' => $e->getMessage()], 500);
            }

            $this->respond(['success' => true], 200);
        });
    }

    /**
     * Mirrors the entry apiReadElements builds for each block. getBlockSchema() is the
     * one most likely to fail, since it calls the block's getSummary().
     */
    private function listEntryFor(BaseElement $element): array
    {
        return [
            'id' => $element->ID,
            'title' => $element->Title,
            'blockSchema' => $element->getBlockSchema(),
            'obsoleteClassName' => $element->getObsoleteClassName(),
            'version' => $element->Version,
            'isPublished' => $element->isPublished(),
            'isLiveVersion' => $element->isLiveVersion(),
            'canDelete' => $element->canDelete(),
            'canPublish' => $element->canPublish(),
            'canUnpublish' => $element->canUnpublish(),
            'canCreate' => $element->canCreate(),
            'statusFlags' => $element->getStatusFlags(),
        ];
    }
}

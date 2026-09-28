<?php

namespace PurpleSpider\PageTypeTester;

use DNADesign\Elemental\Models\BaseElement;
use SilverStripe\CMS\Controllers\ModelAsController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Renders one block on its own, so its frontend can be checked apart from the rest of
 * the page it sits on.
 *
 * Elemental's own /element/{id} route on a page answers 200 without rendering the
 * block, so it cannot be used for this.
 *
 * Rendering errors are deliberately not caught. They reach Silverstripe's own error
 * handling, which answers with a real 500 and, in dev mode, the full trace for anyone
 * who opens the link to find out why.
 */
class BlockRenderer
{
    public const PARAM = 'renderBlock';

    /**
     * Renders the block and terminates the request.
     *
     * @throws HTTPResponse_Exception always, unless rendering throws.
     */
    public function handle(HTTPRequest $request, string $id): void
    {
        $blockId = (int) $id;

        // Published blocks are rendered as visitors see them, without needing a login.
        if ($this->renderInStage($request, $blockId, Versioned::LIVE)) {
            return;
        }

        ElementalSupport::requireCmsAccess($request);

        if (!$this->renderInStage($request, $blockId, Versioned::DRAFT)) {
            $this->respond('<p>Block not found, or it is not on a page.</p>', 'Block not found', 404);
        }
    }

    /**
     * @return bool False when the block, or the page it is on, does not exist in this
     *              stage. Otherwise it ends the request and does not return.
     */
    private function renderInStage(HTTPRequest $request, int $id, string $stage): bool
    {
        return Versioned::withVersionedMode(function () use ($request, $id, $stage) {
            Versioned::set_stage($stage);

            $element = BaseElement::get()->byID($id);
            $page = $element?->getPage();
            if (!$page) {
                return false;
            }

            if (!$element->canView()) {
                $this->respond('<p>You do not have permission to view this block.</p>', 'Forbidden', 403);
            }

            $markup = $this->render($request, $element, $page);
            $this->respond($markup, (string) ($element->Title ?: $element->i18n_singular_name()), 200);
        });
    }

    /**
     * Blocks are written to render inside their page, and commonly reach for it through
     * the current controller: $Top, $CurrentPage, Link() and so on. So the page's
     * controller is made current for the duration, as it would be on the page itself.
     */
    private function render(HTTPRequest $request, BaseElement $element, DataObject $page): string
    {
        if (!$page instanceof SiteTree) {
            return $element->getController()->forTemplate();
        }

        $controller = ModelAsController::controller_for($page);
        $controller->setRequest($request);
        $controller->pushCurrent();

        try {
            return $element->getController()->forTemplate();
        } finally {
            $controller->popCurrent();
        }
    }

    /**
     * @throws HTTPResponse_Exception always - this never returns normally.
     */
    private function respond(string $body, string $title, int $code): void
    {
        $html = "<!DOCTYPE html>\n<html lang='en'><head><meta charset='utf-8'>"
            . "<meta name='robots' content='noindex'>"
            . "<title>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</title></head>"
            . "<body>{$body}</body></html>";

        $response = HTTPResponse::create($html, $code);
        $response->addHeader('Content-Type', 'text/html; charset=utf-8');

        throw new HTTPResponse_Exception($response);
    }
}

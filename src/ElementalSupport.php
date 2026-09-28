<?php

namespace PurpleSpider\PageTypeTester;

use DNADesign\Elemental\Controllers\ElementalAreaController;
use DNADesign\Elemental\Forms\ElementalAreaField;
use DNADesign\Elemental\Models\BaseElement;
use DNADesign\Elemental\Models\ElementalArea;
use Page;
use ReflectionClass;
use SilverStripe\Admin\AdminRootController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\FormField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;

/**
 * The places the report touches Elemental.
 *
 * Elemental is optional. Everything that names one of its classes checks
 * isInstalled() first, so a site without it never loads them.
 */
class ElementalSupport
{
    /**
     * Page types that can hold blocks, most used first. Built once per request.
     *
     * @var string[]|null
     */
    private static ?array $hostPageClasses = null;

    public static function isInstalled(): bool
    {
        return class_exists(BaseElement::class);
    }

    /**
     * True for a block class that can actually be created: not BaseElement itself,
     * and not abstract.
     */
    public static function isBlockClass(string $class): bool
    {
        return static::isInstalled()
            && class_exists($class)
            && is_subclass_of($class, BaseElement::class)
            && !(new ReflectionClass($class))->isAbstract();
    }

    /**
     * @return string[] The page's has_one relations to an ElementalArea.
     */
    public static function elementalRelationsFor(DataObject $page): array
    {
        if (!static::isInstalled() || !$page->hasMethod('getElementalRelations')) {
            return [];
        }

        return $page->getElementalRelations() ?: [];
    }

    /**
     * True for the blocks editor on a page's edit form, which is a GridField.
     */
    public static function isAreaField(FormField $field): bool
    {
        return static::isInstalled() && $field instanceof ElementalAreaField;
    }

    /**
     * The page type a test block of this class should be created on.
     *
     * Prefers the most used page type, since that is where the block is most likely to
     * be used for real. The page type has to allow the block, through Elemental's
     * allowed_elements and disallowed_elements, and the current member has to be able
     * to create both.
     */
    public static function hostPageClassFor(string $blockClass): ?string
    {
        foreach (static::hostPageClasses() as $pageClass) {
            if (array_key_exists($blockClass, singleton($pageClass)->getElementalTypes())) {
                return $pageClass;
            }
        }

        return null;
    }

    /**
     * Archives the page's block areas and the blocks in them.
     *
     * Elemental does not cascade a page's deletion to its area, so archiving a page on
     * its own would leave its blocks behind: still counted, but on no page. Only used
     * on pages this report created.
     */
    public static function archiveElementalAreas(SiteTree $page): void
    {
        Versioned::withVersionedMode(function () use ($page) {
            // Test pages and their blocks are drafts, which other stages cannot see.
            Versioned::set_stage(Versioned::DRAFT);

            foreach (static::elementalRelationsFor($page) as $relation) {
                $areaId = (int) $page->getField($relation . 'ID');
                $area = $areaId > 0 ? DataObject::get_by_id(ElementalArea::class, $areaId) : null;
                if (!$area) {
                    continue;
                }

                foreach ($area->Elements() as $element) {
                    $element->doArchive();
                }

                $area->hasExtension(Versioned::class) ? $area->doArchive() : $area->delete();
            }
        });
    }

    /**
     * @return string[]
     */
    private static function hostPageClasses(): array
    {
        if (static::$hostPageClasses !== null) {
            return static::$hostPageClasses;
        }

        $counts = [];
        foreach (ClassInfo::subclassesFor(Page::class) as $class) {
            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $page = singleton($class);
            if (!$page->hasMethod('supportsElemental') || !$page->supportsElemental()
                || !static::elementalRelationsFor($page) || !$page->canCreate()
            ) {
                continue;
            }

            $counts[$class] = (int) Versioned::get_by_stage($class, Versioned::DRAFT)
                ->filter('ClassName', $class)
                ->count();
        }

        arsort($counts);

        return static::$hostPageClasses = array_keys($counts);
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
        $relations = static::elementalRelationsFor($page);
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

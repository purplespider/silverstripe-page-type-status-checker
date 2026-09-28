<?php

namespace PurpleSpider\PageTypeTester;

use SilverStripe\Admin\LeftAndMain;
use SilverStripe\CMS\Controllers\CMSPageEditController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Model\List\SS_List;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\RelationList;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Requirements;
use SilverStripe\View\Requirements_Backend;
use Throwable;

/**
 * The GridFields in a CMS edit form, and the edit and add forms behind them.
 *
 * A grid only lists its records, so the form holding it answers 200 however broken
 * they are. Each record's own form is a separate request that builds that record's
 * getCMSFields(), and the add form builds them for an empty record, which is where
 * code that assumes a saved record falls over.
 */
class GridFieldFormFinder
{
    /**
     * Each grid on the page's CMS edit form, with the forms to check in it: one record
     * of each class the grid lists, as each class builds its own fields, and the add
     * form.
     *
     * @return array<int, array{title: string, forms: array<int, array{model: string, title: string, url: string, isNew: bool}>}>
     */
    public static function gridFieldsFor(SiteTree $page): array
    {
        // Without a CMS login the form cannot be built, and the checks would only land
        // on the login screen anyway, as the CLI's do.
        if (!Security::getCurrentUser()) {
            return [];
        }

        // The CMS edits the draft, so the grids list what it would.
        $grids = Versioned::withVersionedMode(function () use ($page) {
            Versioned::set_stage(Versioned::DRAFT);

            return static::gridFieldsIn(
                CMSPageEditController::create(),
                fn (CMSPageEditController $cms) => $cms->getEditForm($page->ID)
            );
        });

        $found = [];
        foreach ($grids as $grid) {
            if (!$grid->getConfig()->getComponentByType(GridFieldDetailForm::class)) {
                continue;
            }

            $forms = [];
            foreach (static::recordsByClass($grid->getList()) as $record) {
                $forms[] = [
                    'model' => ClassInfo::shortName($record),
                    'title' => (string) ($record->getTitle() ?: '(untitled)'),
                    'url' => Director::absoluteURL($grid->Link('item/' . $record->ID)),
                    'isNew' => false,
                ];
            }

            if (static::canAddTo($grid)) {
                $forms[] = [
                    'model' => ClassInfo::shortName($grid->getModelClass()),
                    'title' => 'Add form',
                    'url' => Director::absoluteURL($grid->Link('item/new')),
                    'isNew' => true,
                ];
            }

            // An empty grid that cannot be added to has nothing to check.
            if ($forms) {
                $found[] = [
                    'title' => trim(strip_tags((string) $grid->Title())) ?: $grid->getName(),
                    'forms' => $forms,
                ];
            }
        }

        return $found;
    }

    /**
     * The first record of each class in the list, in the list's own order. Taken from
     * the grid's list rather than the model's table, as the grid 404s any record it
     * does not list itself.
     *
     * @return DataObject[]
     */
    private static function recordsByClass(SS_List $list): array
    {
        if ($list instanceof DataList) {
            $records = [];
            // columnUnique() alone is not enough: DISTINCT also covers the list's sort
            // columns, so a grid sorted by date returns its class once per date.
            foreach (array_unique($list->columnUnique('ClassName')) as $class) {
                $record = $list->filter('ClassName', $class)->first();
                if ($record) {
                    $records[] = $record;
                }
            }

            return $records;
        }

        // Lists built in memory are small enough to walk.
        $records = [];
        foreach ($list as $record) {
            if ($record instanceof DataObject && !isset($records[$record->ClassName])) {
                $records[$record->ClassName] = $record;
            }
        }

        return array_values($records);
    }

    /**
     * Builds a CMS controller's edit form and returns the GridFields in it, keyed by
     * name, so their links are whatever the CMS itself would use.
     *
     * Returns none where the form cannot be built outside a CMS request, or throws, in
     * which case the form's own check will already be failing.
     *
     * @param callable(LeftAndMain): ?Form $buildForm
     * @return array<string, GridField>
     */
    public static function gridFieldsIn(LeftAndMain $controller, callable $buildForm): array
    {
        // A fresh GET request, so none of this task's query string reaches the admin,
        // but with the current session, which pushCurrent() requires.
        $request = new HTTPRequest('GET', '/');
        $currentRequest = Controller::curr()?->getRequest();
        $request->setSession($currentRequest?->hasSession() ? $currentRequest->getSession() : new Session([]));
        $controller->setRequest($request);

        // Anything the form requires would otherwise end up in this report's page.
        $requirements = Requirements::backend();
        Requirements::set_backend(Requirements_Backend::create());

        $controller->pushCurrent();
        try {
            $fields = $buildForm($controller)?->Fields()->dataFields() ?? [];
        } catch (Throwable) {
            return [];
        } finally {
            $controller->popCurrent();
            Requirements::set_backend($requirements);
        }

        // Elemental's block area is a GridField too, but its blocks are checked separately.
        return array_filter(
            $fields,
            fn ($field) => $field instanceof GridField && !ElementalSupport::isAreaField($field)
        );
    }

    /**
     * Only grids that offer an Add button, as the add form refuses anybody the button
     * would be hidden from.
     */
    public static function canAddTo(GridField $grid): bool
    {
        if (!$grid->getConfig()->getComponentByType(GridFieldAddNewButton::class)) {
            return false;
        }

        // Only filter by permission when somebody is logged in, or the CLI report would
        // never list an add form.
        if (!Security::getCurrentUser()) {
            return true;
        }

        // The context the Add button itself passes, so a relation's parent can decide.
        $context = [];
        $parent = $grid->getList() instanceof RelationList ? $grid->getForm()?->getRecord() : null;
        if ($parent instanceof DataObject) {
            $context['Parent'] = $parent;
        }

        try {
            return (bool) singleton($grid->getModelClass())->canCreate(null, $context);
        } catch (Throwable) {
            return false;
        }
    }
}

<?php

namespace PurpleSpider\PageTypeTester\Collector;

use PurpleSpider\PageTypeTester\Model\AdminEditLink;
use PurpleSpider\PageTypeTester\Model\AdminSection;
use ReflectionProperty;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Security;
use SilverStripe\View\Requirements;
use SilverStripe\View\Requirements_Backend;
use Throwable;

/**
 * Builds the list of ModelAdmin sections and the SiteConfig settings screen.
 */
class AdminSectionCollector
{
    /**
     * @return AdminSection[]
     */
    public function collect(): array
    {
        $baseUrl = Director::absoluteBaseURL();
        $member = Security::getCurrentUser();

        $sections = [];
        $editIndex = 0;
        $index = 0;

        foreach (ClassInfo::subclassesFor(ModelAdmin::class) as $adminClass) {
            if ($adminClass === ModelAdmin::class) {
                continue;
            }

            $urlSegment = Config::inst()->get($adminClass, 'url_segment');
            if (!$urlSegment) {
                continue;
            }

            // Only filter by permission when somebody is actually logged in. With no
            // member every canView() returns false, which would empty the CLI report
            // entirely; the "not logged in" banner covers that case instead.
            if ($member && !$this->canView($adminClass)) {
                continue;
            }

            $shortClass = ClassInfo::shortName($adminClass);
            $menuTitle = Config::inst()->get($adminClass, 'menu_title');

            $sections[] = new AdminSection(
                (string) ($menuTitle ?: $shortClass),
                'ModelAdmin',
                Controller::join_links($baseUrl, 'admin', $urlSegment),
                $index++,
                $this->editLinksFor($adminClass, $editIndex)
            );
        }

        $sections[] = new AdminSection(
            'Settings',
            'Settings',
            Controller::join_links($baseUrl, 'admin/settings'),
            $index
        );

        return $sections;
    }

    /**
     * @return AdminEditLink[]
     */
    private function editLinksFor(string $adminClass, int &$editIndex): array
    {
        // Admins that work out their tabs at runtime rather than from config, such as
        // ArchiveAdmin with a tab per versioned class, are not checked record by record.
        if (!Config::inst()->get($adminClass, 'managed_models')) {
            return [];
        }

        try {
            $managedModels = Injector::inst()->get($adminClass)->getManagedModels();
        } catch (Throwable) {
            return [];
        }

        $links = [];
        foreach ($managedModels as $tab => $spec) {
            $dataClass = $spec['dataClass'] ?? $tab;
            if (!class_exists($dataClass)) {
                continue;
            }

            $grid = $this->gridFieldFor($adminClass, $tab, $dataClass);

            // No detail form means the grid has no edit screens to check.
            if (!$grid || !$grid->getConfig()->getComponentByType(GridFieldDetailForm::class)) {
                continue;
            }

            // Taken from the grid's own list rather than the model's table, as the grid
            // 404s any record it does not list itself (e.g. a filtered getList()).
            $record = $grid->getList()->first();
            if ($record instanceof DataObject) {
                $links[] = new AdminEditLink(
                    ClassInfo::shortName($dataClass),
                    Director::absoluteURL($grid->Link('item/' . $record->ID)),
                    (string) ($record->getTitle() ?: '(untitled)'),
                    $editIndex++
                );
            }

            // The add form builds its fields for an empty record, which is where
            // getCMSFields() code that assumes a saved record falls over.
            if ($this->canAddTo($grid)) {
                $links[] = new AdminEditLink(
                    ClassInfo::shortName($dataClass),
                    Director::absoluteURL($grid->Link('item/new')),
                    'Add new',
                    $editIndex++,
                    true
                );
            }
        }

        return $links;
    }

    /**
     * Only grids that offer an Add button, as the add form refuses anybody the button
     * would be hidden from.
     */
    private function canAddTo(GridField $grid): bool
    {
        if (!$grid->getConfig()->getComponentByType(GridFieldAddNewButton::class)) {
            return false;
        }

        // As with canView() on the sections, only filter by permission when somebody is
        // logged in, or the CLI report would never list an add form.
        if (!Security::getCurrentUser()) {
            return true;
        }

        try {
            return (bool) singleton($grid->getModelClass())->canCreate();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Builds the tab's real edit form and returns its GridField, so the edit link is
     * built from whatever the admin actually uses. Admins are free to replace or rename
     * the default GridField (QueuedJobsAdmin does both), and guessing its URL reports
     * those edit screens as 404s.
     *
     * Returns null where the form cannot be built outside a CMS request, in which case
     * the tab's edit link is skipped rather than guessed.
     */
    private function gridFieldFor(string $adminClass, string $tab, string $dataClass): ?GridField
    {
        /** @var ModelAdmin $admin */
        $admin = Injector::inst()->create($adminClass);

        // What ModelAdmin::init() sets from the URL's ModelClass param. init() itself is
        // not run, as LeftAndMain's version loads the CMS UI and can redirect.
        (new ReflectionProperty(ModelAdmin::class, 'modelTab'))->setValue($admin, $tab);
        (new ReflectionProperty(ModelAdmin::class, 'modelClass'))->setValue($admin, $dataClass);

        // A fresh GET request, so none of this task's query string reaches the admin,
        // but with the current session, which pushCurrent() requires.
        $request = new HTTPRequest('GET', '/');
        $currentRequest = Controller::curr()?->getRequest();
        $request->setSession($currentRequest?->hasSession() ? $currentRequest->getSession() : new Session([]));
        $admin->setRequest($request);

        // Anything the form requires would otherwise end up in this report's page.
        $requirements = Requirements::backend();
        Requirements::set_backend(Requirements_Backend::create());

        $admin->pushCurrent();
        try {
            $fields = $admin->getEditForm()->Fields()->dataFields();
        } catch (Throwable) {
            return null;
        } finally {
            $admin->popCurrent();
            Requirements::set_backend($requirements);
        }

        $grids = array_filter($fields, fn ($field) => $field instanceof GridField);

        // Prefer ModelAdmin's default field, in case the form has more than one grid.
        return $grids[str_replace('\\', '-', $tab)] ?? reset($grids) ?: null;
    }

    private function canView(string $adminClass): bool
    {
        try {
            return (bool) Injector::inst()->get($adminClass)->canView();
        } catch (Throwable) {
            // A section that cannot even be constructed is not one we can report on.
            return false;
        }
    }
}

<?php

namespace PurpleSpider\PageTypeTester\Collector;

use PurpleSpider\PageTypeTester\Model\AdminEditLink;
use PurpleSpider\PageTypeTester\Model\AdminSection;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Security;
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
                $this->editLinksFor($adminClass, $urlSegment, $baseUrl, $editIndex)
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
    private function editLinksFor(string $adminClass, string $urlSegment, string $baseUrl, int &$editIndex): array
    {
        $managedModels = Config::inst()->get($adminClass, 'managed_models');
        if (!$managedModels || !is_array($managedModels)) {
            return [];
        }

        $links = [];
        foreach ($managedModels as $key => $value) {
            [$tabKey, $dataClass] = $this->resolveManagedModel($key, $value);

            if (!$dataClass || !class_exists($dataClass)) {
                continue;
            }

            $record = DataObject::get($dataClass)->first();
            if (!$record) {
                continue;
            }

            $sanitisedTab = str_replace('\\', '-', $tabKey);

            $links[] = new AdminEditLink(
                ClassInfo::shortName($dataClass),
                Controller::join_links(
                    $baseUrl,
                    'admin',
                    $urlSegment,
                    $sanitisedTab,
                    'EditForm/field',
                    $sanitisedTab,
                    'item',
                    $record->ID
                ),
                (string) ($record->getTitle() ?: '(untitled)'),
                $editIndex++
            );
        }

        return $links;
    }

    /**
     * managed_models accepts several shapes: a plain list of class names, a map of tab
     * key to class name, or a map of tab key to a config array containing dataClass.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveManagedModel(int|string $key, mixed $value): array
    {
        if (is_string($value)) {
            return is_int($key) ? [$value, $value] : [$key, $value];
        }

        if (is_array($value)) {
            if (isset($value['dataClass'])) {
                return [is_int($key) ? $value['dataClass'] : $key, $value['dataClass']];
            }

            return is_int($key) ? [null, null] : [$key, $key];
        }

        return [null, null];
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

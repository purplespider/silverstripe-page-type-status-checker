<?php

namespace PurpleSpider\PageTypeTester;

use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;

/**
 * Locates URLs for a controller's $allowed_actions by scraping the rendered page.
 *
 * Shared by the CLI report and (via the same rules reimplemented in JavaScript) the
 * browser report, so that both agree on which URL an action maps to.
 */
class ActionLinkFinder
{
    use Configurable;

    /**
     * Actions that resolve without needing an ID or other parameter, so a URL can be
     * built directly when no link to them appears in the markup.
     *
     * @config
     */
    private static array $direct_actions = ['rss'];

    /**
     * @return string[]
     */
    public static function getDirectActions(): array
    {
        // Config merges the YAML's list onto the default, repeating any shared entries.
        return array_values(array_unique((array) Config::inst()->get(self::class, 'direct_actions')));
    }

    /**
     * @param string[] $actions
     * @return array<string, string> Action name => absolute URL
     */
    public function find(string $html, array $actions, string $pageUrl, string $baseUrl): array
    {
        $found = [];
        preg_match_all('/href=["\']([^"\']+)["\']/i', $html ?? '', $matches);
        $links = array_unique($matches[1] ?? []);

        $pagePath = rtrim((string) parse_url($pageUrl, PHP_URL_PATH), '/');

        foreach ($actions as $action) {
            // A controller action lives directly beneath the page that owns it. Matching
            // any href containing the action name picks up navigation and footer links to
            // unrelated page types, which then get reported under the wrong page type.
            $expectedPath = strtolower($pagePath . '/' . $action);

            foreach ($links as $link) {
                $absolute = $this->toAbsoluteUrl($link, $pageUrl, $baseUrl);
                $path = strtolower(rtrim((string) parse_url($absolute, PHP_URL_PATH), '/'));

                if ($path === $expectedPath || str_starts_with($path, $expectedPath . '/')) {
                    $found[$action] = $absolute;
                    break;
                }
            }

            if (!isset($found[$action]) && in_array(strtolower($action), self::getDirectActions(), true)) {
                // join_links() keeps a draft page's ?stage=Stage after the action.
                $found[$action] = Controller::join_links($pageUrl, $action);
            }
        }

        return $found;
    }

    private function toAbsoluteUrl(string $link, string $pageUrl, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $link)) {
            return $link;
        }

        if (str_starts_with($link, '//') || str_starts_with($link, '#') || preg_match('#^[a-z]+:#i', $link)) {
            // Protocol-relative, in-page anchors and mailto:/tel: links are not actions.
            return '';
        }

        if (str_starts_with($link, '/')) {
            return rtrim($baseUrl, '/') . $link;
        }

        return Controller::join_links($pageUrl, $link);
    }
}

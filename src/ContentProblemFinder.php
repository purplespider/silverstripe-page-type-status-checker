<?php

namespace PurpleSpider\PageTypeTester;

use PurpleSpider\PageTypeTester\Model\CheckResult;
use SilverStripe\Control\Director;

/**
 * Looks inside a 200 response for signs that it did not really work: error output,
 * template code or shortcodes that were never rendered, and a page that is cut off or
 * has no title. Broken links are warnings rather than failures, as the page itself
 * works.
 *
 * Shared by the CLI report and (via the same rules reimplemented in JavaScript as
 * findContentProblem) the browser report, so that both agree on what fails.
 *
 * In dev mode Silverstripe answers most PHP warnings with a 500 already. These catch
 * what still gets through as a 200: errors raised after output has started, debug
 * output left in the code, and anything that renders without error but wrongly.
 */
class ContentProblemFinder
{
    /**
     * Returns a description of the first problem found, or '' for none.
     *
     * @param bool $isDocument True for a whole page, which should also have a title and
     *                         end properly. Actions and blocks are only searched for
     *                         error output and unrendered code.
     */
    public function find(CheckResult $result, bool $isDocument): string
    {
        return $this->isSearchable($result) ? $this->findInHtml($result->body, $isDocument) : '';
    }

    public function findInHtml(string $html, bool $isDocument): string
    {
        return $this->errorOutput($html)
            ?: $this->unrenderedCode($html)
            ?: ($isDocument ? $this->documentProblem($html) : '');
    }

    /**
     * Returns a description of the first broken link found, or '' for none.
     */
    public function findWarning(CheckResult $result): string
    {
        return $this->isSearchable($result) ? $this->brokenLink($result->body) : '';
    }

    private function isSearchable(CheckResult $result): bool
    {
        return $result->status === 200 && $result->body !== '' && $result->isHtml();
    }

    private function errorOutput(string $html): string
    {
        // Silverstripe's own error view, e.g. <h1>[Warning] Undefined variable $x</h1>.
        if (preg_match('/<div class="header info [a-z]+"><h1>\[([^\]]+)\]\s*(.*?)<\/h1>/s', $html, $match)) {
            return "Shows a PHP {$match[1]}: " . $this->excerpt($match[2]);
        }

        // Debug::message() and Debug::show() left in the code.
        if (preg_match('/<b>Debug \(line (\d+) of ([^)]+)\):<\/b>/', $html, $match)) {
            return 'Shows debug output from ' . basename($match[2]) . " line {$match[1]}";
        }

        // PHP's own error display, with and without html_errors.
        $pattern = '/(?:<b>)?(Fatal error|Parse error|Warning|Notice|Deprecated)(?:<\/b>)?:\s+(.+?)'
            . ' in (?:<b>)?\S+?(?:<\/b>)? on line (?:<b>)?\d+/';
        if (preg_match($pattern, $html, $match)) {
            return "Shows a PHP {$match[1]}: " . $this->excerpt($match[2]);
        }

        return '';
    }

    private function unrenderedCode(string $html): string
    {
        $markup = $this->withoutCode($html);

        // Template control blocks, raw or escaped: <% if $Foo %>, &lt;% loop %&gt;.
        $blocks = '/(?:<|&lt;)%-?\s*(?:if|else_if|else|end_[a-z]+|loop|with|include|require|base_tag|cached'
            . '|uncached|_t)\b.*?%(?:>|&gt;)/s';
        if (preg_match($blocks, $markup, $match)) {
            return 'Shows unrendered template code: ' . $this->excerpt($match[0], false);
        }

        // A bare $Title is left out: it also matches prices and copy like "$Millions".
        if (preg_match('/\{\$[A-Z]\w*(?:\.\w+)*\}/', $markup, $match)) {
            return 'Shows unrendered template code: ' . $match[0];
        }

        // Shortcodes that were never parsed, usually a link or image in the content.
        if (preg_match('/\[(?:sitetree_link|file_link|image|embed)[\s,]+\w+\s*=[^\]]*\]/i', $markup, $match)) {
            return 'Shows an unparsed shortcode: ' . $this->excerpt($match[0], false);
        }

        return '';
    }

    private function brokenLink(string $html): string
    {
        $markup = $this->withoutCode($html);

        // The site's own address run straight into a path, as in
        // https://example.comSecurity/logout. Silverstripe 6 dropped the trailing slash
        // from $AbsoluteBaseURL, so templates that join it to a path break like this.
        $site = preg_replace('#^https?://#i', '', rtrim(Director::absoluteBaseURL(), '/'));
        $pattern = '#\b(?:href|src|action)\s*=\s*["\']\s*((?:https?:)?//' . preg_quote($site, '#')
            . '[a-z0-9_][^"\'\s]*)#i';
        if ($site !== '' && preg_match($pattern, $markup, $match)) {
            return 'Links to ' . $this->excerpt($match[1]) . ', with no / after the site\'s address';
        }

        // A link whose text is an address with a different scheme from where it goes,
        // usually a template that puts its own http:// in front of the URL.
        $anchors = '#<a\b[^>]*?\bhref\s*=\s*["\']\s*((https?)://[^"\']*)["\'][^>]*>(.*?)</a\s*>#is';
        preg_match_all($anchors, $markup, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (!str_contains($match[3], '://')) {
                continue;
            }

            $text = $this->excerpt($match[3]);
            if (preg_match('#^(https?)://#i', $text, $shown) && strcasecmp($shown[1], $match[2]) !== 0) {
                return 'Has a link to ' . $this->excerpt($match[1]) . " with {$text} as its text";
            }
        }

        return '';
    }

    private function documentProblem(string $html): string
    {
        // Something that is not a whole HTML document has no title or end to check.
        if (!preg_match('/<html[\s>]/i', $html)) {
            return '';
        }

        if (!preg_match('/<\/html\s*>/i', $html)) {
            return 'Stops before </html>, so the page may have been cut off';
        }

        if (!preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match)) {
            return 'Has no <title>';
        }

        if (trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '') {
            return 'Has an empty <title>';
        }

        return '';
    }

    /**
     * The markup with scripts, styles, comments and code samples taken out, as template
     * syntax is expected in those and would only be a false alarm.
     */
    private function withoutCode(string $html): string
    {
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

        return preg_replace('/<(script|style|template|textarea|pre|code)\b[^>]*>.*?<\/\1\s*>/is', '', $html)
            ?? $html;
    }

    /**
     * @param bool $stripTags Off for template code, which strip_tags() would take for a
     *                        tag and remove.
     */
    private function excerpt(string $value, bool $stripTags = true): string
    {
        $value = $stripTags ? strip_tags($value) : $value;
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_strlen($text) > 160 ? mb_substr($text, 0, 157) . '...' : $text;
    }
}

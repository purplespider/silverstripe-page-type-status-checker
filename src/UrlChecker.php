<?php

namespace PurpleSpider\PageTypeTester;

use PurpleSpider\PageTypeTester\Model\CheckResult;
use SilverStripe\Control\Director;
use SilverStripe\Security\Security;

/**
 * Performs the HTTP checks behind the CLI report.
 *
 * Redirects are deliberately not followed. A page that redirects is itself something
 * worth reporting, and following the redirect would report the destination's status
 * instead of the page's own - a page silently redirecting to the home page would
 * otherwise be reported as a pass. This also matches what the browser report sees,
 * which uses fetch() with `redirect: 'manual'`.
 */
class UrlChecker
{
    private bool $verifySsl;

    private string $loginUrl;

    private string $loginPath;

    public function __construct(?bool $verifySsl = null)
    {
        // Local development sites are usually served over a locally-trusted CA that
        // curl does not know about, which would report every check as a connection
        // error. Verify by default everywhere else.
        $this->verifySsl = $verifySsl ?? !Director::isDev();
        $this->loginUrl = Director::absoluteURL(Security::login_url());
        $this->loginPath = '/' . trim((string) parse_url($this->loginUrl, PHP_URL_PATH), '/');
    }

    public function getLoginUrl(): string
    {
        return $this->loginUrl;
    }

    public function getLoginPath(): string
    {
        return $this->loginPath;
    }

    public function isVerifyingSsl(): bool
    {
        return $this->verifySsl;
    }

    public function check(string $url): CheckResult
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'SilverStripe-PageTypeTester/2.0',
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectUrl = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        return new CheckResult(
            $status,
            is_string($body) ? $body : '',
            $this->isLoginRedirect($status, $redirectUrl),
            $redirectUrl,
            $contentType
        );
    }

    /**
     * True where a request was redirected to the login screen, which means it had no
     * valid CMS session rather than that the URL is broken.
     */
    private function isLoginRedirect(int $status, string $redirectUrl): bool
    {
        if ($status < 300 || $status >= 400 || $redirectUrl === '') {
            return false;
        }

        $path = '/' . ltrim((string) parse_url($redirectUrl, PHP_URL_PATH), '/');

        return str_starts_with($path, $this->loginPath);
    }
}

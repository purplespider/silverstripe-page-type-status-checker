<?php

namespace PurpleSpider\PageTypeTester;

use InvalidArgumentException;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Security\MemberAuthenticator\CookieAuthenticationHandler;
use SilverStripe\Security\Permission;
use SilverStripe\Security\RememberLoginHash;
use SilverStripe\Security\Security;

/**
 * Logs the CLI into the CMS for one run, so it checks what the browser report checks.
 *
 * The CLI has no session, so without this every CMS URL lands on the login screen, and
 * the parts of the report that are built in-process for the current user (the forms
 * behind each GridField, which admin sections and Add buttons the user can see) come
 * out differently from the browser's.
 *
 * Uses Silverstripe's own "remember me" login rather than anything of this module's: a
 * RememberLoginHash, sent as the alc_enc and alc_device cookies, which core's
 * CookieAuthenticationHandler accepts on any request and turns into a normal session.
 * It is made to expire within the hour and deleted when the run ends. Anybody who can
 * run the CLI can already write to the database, so this gives them nothing new.
 */
class CliLogin
{
    /**
     * How long the login lasts if the run is killed before it can be removed.
     */
    private const EXPIRY_MINUTES = 60;

    /**
     * silverstripe/session-manager, part of recipe-cms, logs out any login that is not
     * tied to one of its LoginSessions. Named as a string, as it is optional.
     */
    private const LOGIN_SESSION_CLASS = 'SilverStripe\\SessionManager\\Models\\LoginSession';

    private bool $ended = false;

    private function __construct(
        private readonly Member $member,
        private readonly RememberLoginHash $hash,
        private readonly string $token
    ) {
    }

    /**
     * Logs in as the member with this email, or the first admin when none is given.
     * Null where there is no admin to log in as, so the run carries on logged out.
     *
     * @throws InvalidArgumentException when the named member does not exist or has no
     *                                  CMS access.
     */
    public static function start(?string $email = null): ?self
    {
        $member = static::findMember($email);
        if (!$member) {
            return null;
        }

        $hash = RememberLoginHash::generate($member);
        if (!$hash) {
            return null;
        }

        // generate() gives it the site's usual lifetime, 30 days by default.
        $hash->ExpiryDate = date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() + self::EXPIRY_MINUTES * 60);
        static::addLoginSession($hash, $member);
        $hash->write();

        $login = new static($member, $hash, (string) $hash->getToken());
        Security::setCurrentUser($member);

        // A backstop for a run that exits early. A run that is killed outright leaves
        // the login to expire.
        register_shutdown_function($login->end(...));

        return $login;
    }

    public function getMember(): Member
    {
        return $this->member;
    }

    /**
     * The cookies that log a request in, by name.
     *
     * @return array<string, string>
     */
    public function getCookies(): array
    {
        $handler = Injector::inst()->get(CookieAuthenticationHandler::class);

        return [
            $handler->getTokenCookieName() => $this->member->ID . ':' . $this->token,
            $handler->getDeviceCookieName() => (string) $this->hash->DeviceID,
        ];
    }

    /**
     * Removes this run's login, and only that. RememberLoginHash::clear() would, with
     * logout_across_devices on, log the member out of every browser too.
     */
    public function end(): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;

        $hash = RememberLoginHash::get()->byID($this->hash->ID);
        if ($hash?->LoginSessionID && class_exists(self::LOGIN_SESSION_CLASS)) {
            DataObject::get(self::LOGIN_SESSION_CLASS)->byID($hash->LoginSessionID)?->delete();
        }
        $hash?->delete();

        Security::setCurrentUser(null);
    }

    /**
     * Ties the login to a session-manager LoginSession, as logging in through the login
     * form would. It shows in the member's list of sessions in the CMS while the run
     * lasts.
     */
    private static function addLoginSession(RememberLoginHash $hash, Member $member): void
    {
        if (!class_exists(self::LOGIN_SESSION_CLASS) || !$hash->hasField('LoginSessionID')) {
            return;
        }

        $request = new HTTPRequest('GET', '/');
        $request->setIP('127.0.0.1');
        $request->addHeader('User-Agent', UrlChecker::USER_AGENT);

        $hash->LoginSessionID = (self::LOGIN_SESSION_CLASS)::generate($member, true, $request)->ID;
    }

    private static function findMember(?string $email): ?Member
    {
        if ($email === null || $email === '') {
            return Permission::get_members_by_permission('ADMIN')->sort('ID')->first();
        }

        $field = Member::config()->get('unique_identifier_field') ?: 'Email';
        $member = Member::get()->find($field, $email);
        if (!$member) {
            throw new InvalidArgumentException("No member with {$field} \"{$email}\".");
        }

        if (!Permission::checkMember($member, 'CMS_ACCESS_CMSMain')) {
            throw new InvalidArgumentException("\"{$email}\" has no access to the CMS pages section.");
        }

        return $member;
    }
}

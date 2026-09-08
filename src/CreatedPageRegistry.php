<?php

namespace PurpleSpider\PageTypeTester;

use Page;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Remembers which pages the "Create" buttons made, so the report can offer to delete
 * them again.
 *
 * The list lives in the session rather than the database. That means no dev/build to
 * install it, and more importantly it bounds what the delete endpoint can reach: only
 * a page this tool created, in this session, can be deleted through the report.
 */
class CreatedPageRegistry
{
    private const SESSION_KEY = 'PageTypeTesterCreatedPageIDs';

    public function __construct(private readonly ?HTTPRequest $request)
    {
    }

    public static function forCurrentRequest(): self
    {
        // Null under the CLI, where there is no controller and no session.
        return new self(Controller::curr()?->getRequest());
    }

    public function has(int $id): bool
    {
        return in_array($id, $this->all(), true);
    }

    public function add(int $id): void
    {
        $this->store(array_merge($this->all(), [$id]));
    }

    public function forget(int $id): void
    {
        $this->store(array_diff($this->all(), [$id]));
    }

    /**
     * The remembered IDs, minus anything since removed in the CMS. Without the pruning
     * a stale entry would inflate the "Delete Created Pages" count and offer a button
     * for a page that is no longer there.
     *
     * @return int[]
     */
    public function existing(): array
    {
        $ids = $this->all();
        if ($ids === []) {
            return [];
        }

        $found = Versioned::withVersionedMode(function () use ($ids) {
            Versioned::set_stage(Versioned::DRAFT);

            return array_map('intval', DataObject::get(Page::class)->filter('ID', $ids)->columnUnique('ID'));
        });

        $existing = array_values(array_intersect($ids, $found));
        if (count($existing) !== count($ids)) {
            $this->store($existing);
        }

        return $existing;
    }

    /**
     * @return int[]
     */
    private function all(): array
    {
        $ids = $this->session()?->get(self::SESSION_KEY);

        return is_array($ids) ? $this->normalise($ids) : [];
    }

    /**
     * @param int[] $ids
     */
    private function store(array $ids): void
    {
        // SessionMiddleware saves in a finally block, so this is still written even
        // though the endpoints end the request by throwing.
        $this->session()?->set(self::SESSION_KEY, $this->normalise($ids));
    }

    /**
     * @return int[]
     */
    private function normalise(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function session(): ?Session
    {
        return $this->request?->hasSession() ? $this->request->getSession() : null;
    }
}

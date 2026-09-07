<?php

namespace PurpleSpider\PageTypeTester\Model;

/**
 * The outcome of a single HTTP check. A status of 0 means the request never
 * completed, for example a DNS, TLS or timeout failure.
 */
class CheckResult
{
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly bool $loginRequired = false,
        public readonly string $redirectUrl = ''
    ) {
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    public function getLabel(): string
    {
        return $this->status === 0 ? 'ERR' : (string) $this->status;
    }

    /**
     * @param int[] $expected
     */
    public function matches(array $expected): bool
    {
        return in_array($this->status, $expected, true);
    }
}

<?php

namespace PurpleSpider\PageTypeTester\Model;

/**
 * A place in the code that sends, or builds, an email.
 *
 * via lists the classes the report followed to reach it, starting from the page type,
 * block type or admin section's own code. It is empty where that code sends the email
 * itself.
 */
class EmailUsage
{
    /**
     * @param string[] $via Short class names.
     */
    public function __construct(
        public readonly string $className,
        public readonly string $method,
        public readonly int $line,
        public readonly string $file,
        public readonly string $mailer,
        public readonly array $via = []
    ) {
    }

    /**
     * Where it is, as a developer would look for it, e.g. "ContactPageController::doSend()".
     */
    public function getLocation(): string
    {
        return $this->method === '' ? $this->className : "{$this->className}::{$this->method}()";
    }

    /**
     * The line number, what it sends with and how it was reached, e.g.
     * "line 42, Email, via ContactForm".
     */
    public function getDetail(): string
    {
        $parts = ["line {$this->line}", $this->mailer];
        if ($this->via) {
            $parts[] = 'via ' . implode(' > ', $this->via);
        }

        return implode(', ', $parts);
    }
}

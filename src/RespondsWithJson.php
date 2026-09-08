<?php

namespace PurpleSpider\PageTypeTester;

use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;

/**
 * Shared by the endpoints that write to the database. Both answer a fetch() with JSON
 * and must not fall through into the report markup, so they end the request rather
 * than returning.
 */
trait RespondsWithJson
{
    /**
     * @throws HTTPResponse_Exception always - this never returns normally.
     */
    private function respond(array $data, int $code): void
    {
        $response = HTTPResponse::create((string) json_encode($data), $code);
        $response->addHeader('Content-Type', 'application/json');

        throw new HTTPResponse_Exception($response);
    }
}

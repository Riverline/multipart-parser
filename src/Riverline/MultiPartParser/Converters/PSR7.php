<?php

/*
 * This file is part of the MultiPartParser package.
 *
 * (c) Romain Cambien <romain@cambien.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Riverline\MultiPartParser\Converters;

use Psr\Http\Message\ServerRequestInterface;
use Riverline\MultiPartParser\StreamedPart;

/**
 * Class PSR7
 */
class PSR7
{
    /**
     * @param ServerRequestInterface $serverRequest
     *
     * @return StreamedPart
     */
    public static function convert(ServerRequestInterface $serverRequest)
    {
        $stream = fopen('php://temp', 'rw');

        foreach ($serverRequest->getHeaders() as $key => $values) {
            foreach ($values as $value) {
                fwrite($stream, "$key: $value\r\n");
            }
        }
        fwrite($stream, "\r\n");

        $body = $serverRequest->getBody();
        $body->rewind();

        while (!$body->eof()) {
            fwrite($stream, $body->read(1024));
        }

        rewind($stream);

        return new StreamedPart($stream);
    }
}

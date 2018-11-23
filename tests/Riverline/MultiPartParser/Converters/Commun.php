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

/**
 * Class Commun
 */
class Commun extends \PHPUnit_Framework_TestCase
{
    /**
     * @return resource
     */
    public function createBodyStream()
    {
        $content = file_get_contents(__DIR__.'/../../../data/simple_multipart.txt');

        list(, $body) = preg_split("/(\r\n){2}/", $content, 2);

        $stream = fopen('php://temp', 'rw');
        fwrite($stream, $body);

        rewind($stream);

        return $stream;
    }
}

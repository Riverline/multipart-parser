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

use Riverline\MultiPartParser\StreamedPart;

/**
 * Class GlobalsTest
 */
class Globals
{
    /**
     * @param resource|null $input
     *
     * @return StreamedPart
     */
    public static function convert($input = null)
    {
        $stream = fopen('php://temp', 'rw');

        foreach ($_SERVER as $key => $value) {
            if (0 === strpos($key, 'HTTP_')) {
                $key = str_replace('_', '-', strtolower(substr($key, 5)));
                fwrite($stream, "$key: $value\r\n");
            } elseif (in_array($key, ['CONTENT_LENGTH', 'CONTENT_MD5', 'CONTENT_TYPE'])) {
                $key = str_replace('_', '-', strtolower($key));
                fwrite($stream, "$key: $value\r\n");
            }
        }

        fwrite($stream, "\r\n");
        $inputStream = self::getInputStreamByInput($input);
        stream_copy_to_stream($inputStream, $stream);

        rewind($stream);

        return new StreamedPart($stream);
    }

    /**
     * @param resource|null $input
     * @return resource
     */
    static private function getInputStreamByInput($input = null)
    {
        if (is_resource($input)) {
            return $input;
        }

        return self::getStdinStream();
    }

    static private function getStdinStream()
    {
        return fopen('php://stdin', 'r');
    }
}

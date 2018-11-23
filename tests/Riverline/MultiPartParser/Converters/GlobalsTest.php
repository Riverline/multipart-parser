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
 * Class GlobalsTest
 */
class GlobalsTest extends Commun
{
    /**
     * Test the parser
     */
    public function testParser()
    {
        // Create PSR7 server request

        $_SERVER['HTTP_CONTENT_TYPE'] = 'multipart/form-data; boundary=----------------------------83ff53821b7c';

        // Test the converter
        $part = Globals::convert($this->createBodyStream());

        self::assertTrue($part->isMultiPart());
        self::assertCount(3, $part->getParts());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("MultiPart content, there aren't body");
        $part->getBody();
    }
}

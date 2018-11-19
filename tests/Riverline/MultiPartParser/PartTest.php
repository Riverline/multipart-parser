<?php

/*
 * This file is part of the MultiPartParser package.
 *
 * (c) Romain Cambien <romain@cambien.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Riverline\MultiPartParser;

/**
 * Class PartTest
 */
class PartTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Test a empty document
     */
    public function testEmptyPart()
    {
        $this->setExpectedException('\LogicException', "Content is not valid");
        new Part('');
    }

    /**
     * Test a simple multipart document
     */
    public function testSimpleMultiPart()
    {
        $content = file_get_contents(__DIR__.'/../../data/simple_multipart.txt');

        $part = new Part($content);

        self::assertTrue($part->isMultiPart());
        self::assertCount(3, $part->getParts());

        $this->setExpectedException('\LogicException', "MultiPart content, there aren't body");
        $part->getBody();
    }
}

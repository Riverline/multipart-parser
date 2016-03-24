<?php

namespace Riverline\MultiPartParser;

/**
 * Class PartTest
 * @package Riverline\MultiPartParser
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
     * Test a multipart document without boundary header
     */
    public function testNoBoundaryPart()
    {
        $content = file_get_contents(__DIR__.'/../../data/no_boundary.txt');

        $this->setExpectedException('\LogicException', "Can't find boundary in content type");
        new Part($content);
    }

    /**
     * Test a multipart document without first boundary
     */
    public function testNoFirstBoundaryPart()
    {
        $content = file_get_contents(__DIR__.'/../../data/no_first_boundary.txt');

        $this->setExpectedException('\LogicException', "Can't find multi-part content");
        new Part($content);
    }

    /**
     * Test a multipart document without last boundary
     */
    public function testNoLastBoundaryPart()
    {
        $content = file_get_contents(__DIR__.'/../../data/no_last_boundary.txt');

        $this->setExpectedException('\LogicException', "Can't find multi-part content");
        new Part($content);
    }

    /**
     * Test a document without multipart
     */
    public function testNoMultiPart()
    {
        $content = file_get_contents(__DIR__.'/../../data/no_multipart.txt');

        $part = new Part($content);

        self::assertFalse($part->isMultiPart());
        self::assertEquals('bar', $part->getBody());
    }

    /**
     * Test a simple multipart document
     */
    public function testSimpleMultiPart()
    {
        $content = file_get_contents(__DIR__.'/../../data/simple_multipart.txt');

        $part = new Part($content);

        self::assertTrue($part->isMultiPart());
        self::assertCount(2, $part->getParts());

        $this->setExpectedException('\LogicException', "MultiPart content, there aren't body");
        $part->getBody();
    }

    /**
     * Test multi line header
     */
    public function testMultiLineHeader()
    {
        $content = file_get_contents(__DIR__.'/../../data/simple_multipart.txt');

        $part = new Part($content);

        self::assertEquals('line one line two with space line three with tab', $part->getHeader('X-Multi-Line'));
    }

    /**
     * Test filter by name on a simple multipart document
     */
    public function testFilterByName()
    {
        $content = file_get_contents(__DIR__.'/../../data/simple_multipart.txt');

        $part = new Part($content);

        self::assertCount(1, $part->getPartsByName('foo'));
    }

    /**
     * Test correct part content (e.g. \r of separator not part of body)
     */
    public function testPartContent()
    {
        $content = file_get_contents(__DIR__.'/../../data/simple_multipart.txt');

        $part = new Part($content);

        $foo = $part->getPartsByName('foo');

        self::assertEquals("bar", $foo[0]->getBody());
    }

    /**
     * Test header helpers
     */
    public function testHeaderHelpers()
    {
        $content = file_get_contents(__DIR__.'/../../data/simple_multipart.txt');

        $part = new Part($content);
        $parts = $part->getParts();
        $header = $parts[1]->getHeader('Content-Disposition');

        self::assertEquals('form-data', Part::getHeaderValue($header));
        self::assertEquals('foo', Part::getHeaderOption($header, 'name'));
    }

    /**
     * Test file helper
     */
    public function testFileHelper()
    {
        $content = file_get_contents(__DIR__.'/../../data/simple_multipart.txt');

        $part = new Part($content);
        $parts = $part->getParts();

        self::assertTrue($parts[0]->isFile());
        self::assertEquals('a.png', $parts[0]->getFileName());
    }


    /**
     * Test a nested multipart document
     */
    public function testNestedMultiPart()
    {
        $content = file_get_contents(__DIR__.'/../../data/nested_multipart.txt');

        $part = new Part($content);

        self::assertTrue($part->isMultiPart());

        /** @var Part[] $parts */
        $parts = $part->getParts();

        self::assertTrue($parts[0]->isMultiPart());
    }

    /**
     * Test a email document with base64 encoding
     */
    public function testEmailBase64()
    {
        $content = file_get_contents(__DIR__.'/../../data/email_base64.txt');

        $part = new Part($content);

        self::assertTrue($part->isMultiPart());

        $this->setExpectedException('\LogicException', "MultiPart content, there aren't body");
        $part->getBody();

        self::assertEquals('This is thé subject', $part->getHeader('Subject'));

        /** @var Part[] $parts */
        $parts = $part->getParts();

        self::assertEquals('This is the content', $parts[0]->getBody());
        self::assertEquals('This is the côntént', $parts[1]->getBody());
    }
}

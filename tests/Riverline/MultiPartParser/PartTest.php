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

        $this->setExpectedException('\LogicException', "Can't find first boundary in content");
        new Part($content);
    }

    /**
     * Test a multipart document without last boundary
     */
    public function testNoLastBoundaryPart()
    {
        $content = file_get_contents(__DIR__.'/../../data/no_last_boundary.txt');

        $this->setExpectedException('\LogicException', "Content is incomplete, missing end boundary");
        new Part($content);
    }

    /**
     * Test a document without multipart
     */
    public function testNoMultiPart()
    {
        $content = file_get_contents(__DIR__.'/../../data/no_multipart.txt');

        $part = new Part($content);

        $this->assertFalse($part->isMultiPart());
        $this->assertEquals('bar', $part->getBody());
    }

    /**
     * Test a simple multipart document
     */
    public function testSimpleMultiPart()
    {
        $content = file_get_contents(__DIR__.'/../../data/simple_multipart.txt');

        $part = new Part($content);

        $this->assertTrue($part->isMultiPart());
        $this->assertCount(2, $part->getParts());

        $this->setExpectedException('\LogicException', "MultiPart content, there aren't body");
        $part->getBody();
    }

    /**
     * Test filter by name on a simple multipart document
     */
    public function testFilterByName()
    {
        $content = file_get_contents(__DIR__.'/../../data/simple_multipart.txt');

        $part = new Part($content);

        $this->assertCount(1, $part->getPartsByName('foo'));
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

        $this->assertEquals('form-data', Part::getHeaderValue($header));
        $this->assertEquals('foo', Part::getHeaderOption($header, 'name'));
    }

    /**
     * Test file helper
     */
    public function testFileHelper()
    {
        $content = file_get_contents(__DIR__.'/../../data/simple_multipart.txt');

        $part = new Part($content);
        $parts = $part->getParts();

        $this->assertTrue($parts[0]->isFile());
        $this->assertEquals('a.png', $parts[0]->getFileName());
    }


    /**
     * Test a nested multipart document
     */
    public function testNestedMultiPart()
    {
        $content = file_get_contents(__DIR__.'/../../data/nested_multipart.txt');

        $part = new Part($content);

        $this->assertTrue($part->isMultiPart());

        /** @var Part[] $parts */
        $parts = $part->getParts();

        $this->assertTrue($parts[0]->isMultiPart());
    }

    /**
     * Test a email document with base64 encoding
     */
    public function testEmailBase64()
    {
        $content = file_get_contents(__DIR__.'/../../data/email_base64.txt');

        $part = new Part($content);

        $this->assertTrue($part->isMultiPart());

        $this->setExpectedException('\LogicException', "MultiPart content, there aren't body");
        $part->getBody();

        $this->assertEquals('This is thé subject', $part->getHeader('Subject'));

        /** @var Part[] $parts */
        $parts = $part->getParts();

        $this->assertEquals('This is the content', $parts[0]->getBody());
        $this->assertEquals('This is the côntént', $parts[1]->getBody());
    }
}
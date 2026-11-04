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

use PHPUnit\Framework\TestCase;

/**
 * Class StreamedPartTest
 */
class StreamedPartTest extends TestCase
{
    /**
     * Test a multipart with invalid stream resource
     */
    public function testInvalidStreamResource()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Input is not a stream");
        new StreamedPart('invalid stream resource');
    }

    /**
     * Test a multipart document without boundary header
     */
    public function testNoBoundaryPart()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Can't find boundary in content type");
        new StreamedPart(fopen(__DIR__ . '/_data/no_boundary.txt', 'r'));
    }

    /**
     * Test a multipart document without first boundary
     */
    public function testNoFirstBoundaryPart()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Can't find multi-part content");
        new StreamedPart(fopen(__DIR__ . '/_data/no_first_boundary.txt', 'r'));
    }

    /**
     * Test a multipart document without last boundary
     */
    public function testNoLastBoundaryPart()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Can't find multi-part content");
        new StreamedPart(fopen(__DIR__ . '/_data/no_last_boundary.txt', 'r'));
    }

    /**
     * Test a document without multipart
     */
    public function testNoMultiPart()
    {
        $part = new StreamedPart(fopen(__DIR__ . '/_data/no_multipart.txt', 'r'));

        self::assertFalse($part->isMultiPart());
        self::assertEquals('bar', $part->getBody());
        self::assertEquals(array(
            'user-agent' => 'curl/7.21.2 (x86_64-apple-darwin)',
            'host' => 'localhost:8080',
            'accept' => '*/*',
            'expect' => '100-continue',
            'content-type' => 'text/plain',
        ), $part->getHeaders());
    }

    /**
     * Test that is not possible to get a body for a multi part document
     */
    public function testCantGetBodyForAMultiPartMessage()
    {
        $part = new StreamedPart(fopen(__DIR__ . '/_data/simple_multipart.txt', 'rb'));

        self::assertTrue($part->isMultiPart());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("MultiPart content, there aren't body");
        $part->getBody();
    }

    /**
     * Test multipart without new line at the end
     */
    public function testNoNewLineAtTheEndOfTheParts()
    {
        $content = "Content-Type: multipart/related; boundary=delimiter\r\n".
            "\r\n" .
            "--delimiter\r\n" .
            "Content-Type:mime/type\r\n" .
            "\r\n" .
            "Content\r\n" .
            "--delimiter--";


        $stream = fopen('php://temp', 'rw');
        fwrite($stream, $content);
        rewind($stream);

        $part = new StreamedPart($stream);
          /** @var Part[] $parts */
        $parts = $part->getParts();
        self::assertEquals('Content', $parts[0]->getBody());

    }

    /**
     * Test multipart without new line at the end
     */
    public function testNoNewLineAtTheEndOfThePartsWhenNewLineIsOneCharacterLong()
    {
        $content = "Content-Type: multipart/related; boundary=delimiter\n".
            "\n" .
            "--delimiter\n" .
            "Content-Type:mime/type\n" .
            "\n" .
            "Content\n" .
            "--delimiter--";


        $stream = fopen('php://temp', 'rw');
        fwrite($stream, $content);
        rewind($stream);

        $part = new StreamedPart($stream);
        /** @var Part[] $parts */
        $parts = $part->getParts();
        self::assertEquals('Content', $parts[0]->getBody());
    }

    /**
     * Test that is not possible to get parts for a not multi part document
     */
    public function testCantGetPartsForANotMultiPartMessage()
    {
        $part = new StreamedPart(fopen(__DIR__ . '/_data/no_multipart.txt', 'r'));

        self::assertFalse($part->isMultiPart());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Not MultiPart content, there aren't any parts");
        $part->getParts();
    }

    /**
     * Test a simple multipart document
     */
    public function testSimpleMultiPart()
    {
        $part = new StreamedPart(fopen(__DIR__ . '/_data/simple_multipart.txt', 'rb'));

        self::assertTrue($part->isMultiPart());
        self::assertCount(3, $part->getParts());

        /** @var Part[] $parts */
        $parts = $part->getParts();

        self::assertEquals('image/png', $parts[0]->getMimeType());
        self::assertEquals(
            "\x89\x50\x4e\x47\x0d\x0a\x1a\x0a\x00\x00\x00\x0d\x49\x48\x44\x52\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\x0a\x49\x44\x41\x54\x78\x9c\x63\x00\x01\x00\x00\x05\x00\x01",
            $parts[0]->getBody()
        );
        self::assertEquals('bar', $parts[1]->getBody());
        self::assertEquals('rfc', $parts[2]->getBody());
    }

    /**
     * Test multi line header
     */
    public function testMultiLineHeader()
    {
        $part = new StreamedPart(fopen(__DIR__ . '/_data/simple_multipart.txt', 'rb'));

        self::assertEquals('line one line two with space line three with tab', $part->getHeader('X-Multi-Line'));
    }

    /**
     * Test filter by name on a simple multipart document
     */
    public function testFilterByName()
    {
        $part = new StreamedPart(fopen(__DIR__ . '/_data/simple_multipart.txt', 'rb'));

        self::assertCount(1, $part->getPartsByName('foo'));
    }

    /**
     * Test correct part content (e.g. \r of separator not part of body)
     */
    public function testPartContent()
    {
        $part = new StreamedPart(fopen(__DIR__ . '/_data/simple_multipart.txt', 'rb'));

        $foo = $part->getPartsByName('foo');

        self::assertEquals("bar", $foo[0]->getBody());
    }

    /**
     * Test RFC 5987 encoded header
     */
    public function testRFC5987Header()
    {
        $part = new StreamedPart(fopen(__DIR__ . '/_data/simple_multipart.txt', 'rb'));

        $parts = $part->getParts();
        $header = $parts[2]->getHeader('Content-Disposition');

        self::assertEquals('£ rates', StreamedPart::getHeaderOption($header, 'text1'));
        self::assertEquals('£ and € rates', StreamedPart::getHeaderOption($header, 'text2'));
    }

    /**
     * Test header helpers
     */
    public function testHeaderHelpers()
    {
        $part = new StreamedPart(fopen(__DIR__ . '/_data/simple_multipart.txt', 'rb'));

        $parts = $part->getParts();
        $header = $parts[1]->getHeader('Content-Disposition');

        self::assertEquals('form-data', StreamedPart::getHeaderValue($header));
        self::assertEquals('foo', StreamedPart::getHeaderOption($header, 'name'));
    }

    /**
     * Test reading headers with very long values that could cause a DoS or DoW
     */
    public function testInvalidHeadersAreRejected()
    {
        $content = "Content-Type: multipart/form-data; boundary=a\n".
            "\n" .
            "--a\n" .
            "Content-Disposition: form-data; name=\"0\";\n" .
            "Content-Type: ; *=auto''" . str_repeat('a', 10000) .
            "\r\n\r\n\r\n--a--\r\n";
        $stream = fopen('php://temp', 'rw');
        fwrite($stream, $content);
        rewind($stream);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed header: header value is too long');
        new StreamedPart($stream);
    }

    /**
     * Test file helper
     */
    public function testFileHelper()
    {
        $part = new StreamedPart(fopen(__DIR__ . '/_data/simple_multipart.txt', 'rb'));

        $parts = $part->getParts();

        self::assertTrue($parts[0]->isFile());
        self::assertEquals('a.png', $parts[0]->getFileName());
    }


    /**
     * Test a nested multipart document
     */
    public function testNestedMultiPart()
    {
        $part = new StreamedPart(fopen(__DIR__ . '/_data/nested_multipart.txt', 'r'));

        self::assertTrue($part->isMultiPart());

        $parts = $part->getParts();

        self::assertTrue($parts[0]->isMultiPart());
    }

    /**
     * Test a email document with base64 encoding
     */
    public function testEmailBase64()
    {
        $part = new StreamedPart(fopen(__DIR__ . '/_data/email_base64.txt', 'r'));

        self::assertTrue($part->isMultiPart());
        self::assertEquals('This is thé subject', $part->getHeader('Subject'));

        /** @var Part[] $parts */
        $parts = $part->getParts();

        self::assertEquals('This is the content', $parts[0]->getBody());
        self::assertEquals('This is the côntént', $parts[1]->getBody());
    }

    /**
     * Test binary file with base64 encoding
     */
    public function testBase64WithOctetStream()
    {
        $part = new StreamedPart(fopen(__DIR__ . '/_data/binary_base64.txt', 'r'));

        self::assertTrue($part->isMultiPart());

        /** @var Part[] $parts */
        $parts = $part->getParts();

        self::assertCount(2, $parts);
        self::assertEquals('bar', $parts[0]->getBody());
        self::assertEquals('test.bin', $parts[1]->getFileName());
        self::assertEquals(hex2bin('6566ffff'), $parts[1]->getBody());
    }

    /**
     * Test a quoted printable decoding
     */
    public function testQuotedPrintable()
    {
        $part = new StreamedPart(fopen(__DIR__ . '/_data/quoted_printable.txt', 'r'));

        self::assertTrue($part->isMultiPart());
        self::assertEquals('mûltîpàrt', $part->getHeader('Subject'));

        /** @var Part[] $parts */
        $parts = $part->getParts();

        self::assertEquals('This is the content', $parts[0]->getBody());
        self::assertEquals('This is the côntént', $parts[1]->getBody());
    }

    /**
     * Test capitalized content type like "Multipart/Related"
     */
    public function testCapitalized()
    {
        $part = new StreamedPart(fopen(__DIR__ . '/_data/capitalized_multipart.txt', 'r'));

        self::assertTrue($part->isMultiPart());
    }

    /**
     * Test multipart with missing Content-Type header in parts (PHP 8.4 compatibility)
     * This reproduces the scenario where text form fields don't include Content-Type headers
     */
    public function testMissingContentTypeHeader()
    {
        // Create multipart content similar to standard browser form submissions
        $content = "Content-Type: multipart/form-data; boundary=----WebKitFormBoundary\r\n";
        $content .= "\r\n";
        $content .= "------WebKitFormBoundary\r\n";
        $content .= "Content-Disposition: form-data; name=\"title\"\r\n";
        $content .= "\r\n";
        $content .= "Hello World\r\n";
        $content .= "------WebKitFormBoundary\r\n";
        $content .= "Content-Disposition: form-data; name=\"description\"\r\n";
        $content .= "\r\n";
        $content .= "Test description\r\n";
        $content .= "------WebKitFormBoundary\r\n";
        $content .= "Content-Disposition: form-data; name=\"image\"; filename=\"photo.png\"\r\n";
        $content .= "Content-Type: image/png\r\n";
        $content .= "\r\n";
        $content .= "image data\r\n";
        $content .= "------WebKitFormBoundary--\r\n";

        $stream = fopen('php://temp', 'rw');
        fwrite($stream, $content);
        rewind($stream);

        $part = new StreamedPart($stream);

        self::assertTrue($part->isMultiPart());
        self::assertCount(3, $part->getParts());

        $parts = $part->getParts();

        // First part without Content-Type header should work without errors
        self::assertEquals('title', $parts[0]->getName());
        self::assertEquals('Hello World', $parts[0]->getBody());

        // Second part without Content-Type header should also work
        self::assertEquals('description', $parts[1]->getName());
        self::assertEquals('Test description', $parts[1]->getBody());

        // Third part with Content-Type header should work as before
        self::assertEquals('image', $parts[2]->getName());
        self::assertTrue($parts[2]->isFile());
        self::assertEquals('image/png', $parts[2]->getMimeType());
        self::assertEquals('image data', $parts[2]->getBody());
    }

    /**
     * @param string $headers
     *
     * @return StreamedPart
     */
    private function createPartFromHeaders($headers)
    {
        $stream = fopen('php://temp', 'rw');
        fwrite($stream, $headers."\r\n\r\nContent");
        rewind($stream);

        return new StreamedPart($stream);
    }

    /**
     * Test raw UTF-8 in headers, it's how browsers send non-ASCII names ( RFC 7578 )
     */
    public function testRawUtf8Header()
    {
        $part = $this->createPartFromHeaders(
            "Content-Disposition: form-data; name=\"prénom\"; filename=\"日本語 résumé.png\""
        );

        self::assertEquals('prénom', $part->getName());
        self::assertEquals('日本語 résumé.png', $part->getFileName());
    }

    /**
     * Test raw UTF-8 next to an encoded-word
     */
    public function testRawUtf8NextToEncodedWord()
    {
        $part = $this->createPartFromHeaders("Subject: =?UTF-8?B?w6l0w6k=?= et été");

        self::assertEquals('été et été', $part->getHeader('Subject'));
    }

    /**
     * Test encoded-words decoding ( RFC 2047 )
     */
    public function testEncodedWords()
    {
        $part = $this->createPartFromHeaders(
            "X-Latin1: =?ISO-8859-1?Q?caf=E9_cr=E8me?=\r\n".
            "X-Lowercase: =?utf-8?q?caf=c3=a9?=\r\n".
            "X-Mime-Name: =?Shift_JIS?B?k/qWe4zq?=\r\n".
            "X-Language: =?UTF-8*fr?Q?=C3=A9t=C3=A9?=\r\n".
            "X-In-Quotes: attachment; filename=\"=?UTF-8?B?w6l0w6kucGRm?=\"\r\n".
            "X-Separated: =?UTF-8?Q?a?= - =?UTF-8?Q?b?="
        );

        self::assertEquals('café crème', $part->getHeader('X-Latin1'));
        self::assertEquals('café', $part->getHeader('X-Lowercase'));
        self::assertEquals('日本語', $part->getHeader('X-Mime-Name'));
        self::assertEquals('été', $part->getHeader('X-Language'));
        self::assertEquals('attachment; filename="été.pdf"', $part->getHeader('X-In-Quotes'));
        self::assertEquals('a - b', $part->getHeader('X-Separated'));
    }

    /**
     * Test malformed encoded-words, decoded as mb_decode_mimeheader() did
     */
    public function testMalformedEncodedWords()
    {
        $part = $this->createPartFromHeaders(
            "X-Missing-Padding: =?UTF-8?B?w6l0w6k?=\r\n".
            "X-Spaces-Q: =?UTF-8?Q?caf=C3=A9 cr=C3=A8me?=\r\n".
            "X-Spaces-B: =?UTF-8?B?w6l0 w6k=?=\r\n".
            "X-Question-Mark: =?UTF-8?Q?what?_ok?=\r\n".
            "X-Unclosed: =?UTF-8?Q?abc\r\n".
            "X-Adjacent: =?UTF-8?Q?a?= =?UTF-8?Q?b?="
        );

        self::assertEquals('été', $part->getHeader('X-Missing-Padding'));
        self::assertEquals('café crème', $part->getHeader('X-Spaces-Q'));
        self::assertEquals('été', $part->getHeader('X-Spaces-B'));
        self::assertEquals('what? ok', $part->getHeader('X-Question-Mark'));
        self::assertEquals('abc', $part->getHeader('X-Unclosed'));
        self::assertEquals('a b', $part->getHeader('X-Adjacent'));
    }

    /**
     * Test that encoded-words that can't be decoded are kept as is
     */
    public function testUndecodableEncodedWords()
    {
        $part = $this->createPartFromHeaders(
            "X-Unknown-Charset: =?x-unknown?Q?abc?=\r\n".
            "X-Not-Charset: =?base64?Q?abc?=\r\n".
            "X-Not-Encoded: a=?b"
        );

        self::assertEquals('=?x-unknown?Q?abc?=', $part->getHeader('X-Unknown-Charset'));
        self::assertEquals('=?base64?Q?abc?=', $part->getHeader('X-Not-Charset'));
        self::assertEquals('a=?b', $part->getHeader('X-Not-Encoded'));
    }

    /**
     * Test that header values are always valid UTF-8 and bare CR are replaced
     */
    public function testHeadersSanitization()
    {
        $part = $this->createPartFromHeaders(
            "X-Invalid-Utf8: caf\xE9\r\n".
            "X-Bare-Cr: a\rb"
        );

        self::assertEquals('caf?', $part->getHeader('X-Invalid-Utf8'));
        self::assertEquals('a b', $part->getHeader('X-Bare-Cr'));
    }

    /**
     * @param string $headers
     * @param string $body
     *
     * @return StreamedPart
     */
    private function createPartWithBody($headers, $body)
    {
        $stream = fopen('php://temp', 'rw');
        fwrite($stream, $headers."\r\n\r\n".$body);
        rewind($stream);

        return new StreamedPart($stream);
    }

    /**
     * Test that binary bodies are never charset converted, whatever their Content-Type
     * ( these bytes are detected as ASCII by mb_detect_encoding() on PHP 8.3 )
     */
    public function testBinaryBodyNotConverted()
    {
        $binary = "\xE9\xFF\x00abc";

        foreach (array('image/png', 'application/pdf', 'application/octet-stream; name="a.bin"') as $contentType) {
            $part = $this->createPartWithBody(
                "Content-Type: $contentType\r\nContent-Transfer-Encoding: base64",
                base64_encode($binary)
            );

            self::assertTrue($binary === $part->getBody(), $contentType);
        }
    }

    /**
     * Test that text bodies are still converted
     */
    public function testTextBodyConverted()
    {
        $part = $this->createPartWithBody(
            "Content-Type: text/plain; charset=ISO-8859-1\r\nContent-Transfer-Encoding: quoted-printable",
            'caf=E9'
        );
        self::assertEquals('café', $part->getBody());

        // A declared charset is used whatever the Content-Type
        $part = $this->createPartWithBody(
            "Content-Type: application/json; charset=ISO-8859-1\r\nContent-Transfer-Encoding: base64",
            base64_encode("\"caf\xE9\"")
        );
        self::assertEquals('"café"', $part->getBody());
    }

    /**
     * Test that an unsupported charset doesn't throw
     */
    public function testUnsupportedCharset()
    {
        $part = $this->createPartWithBody(
            "Content-Type: text/plain; charset=x-unknown\r\nContent-Transfer-Encoding: 8bit\r\n".
            "Content-Disposition: attachment; filename*=x-unknown''caf%E9.txt",
            'body'
        );

        self::assertEquals('body', $part->getBody());
        self::assertEquals('caf?.txt', $part->getFileName());
    }

}

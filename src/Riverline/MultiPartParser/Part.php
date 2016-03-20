<?php

namespace Riverline\MultiPartParser;
use InvalidArgumentException;
use LogicException;

/**
 * Class Part
 * @package Riverline\MultiPartParser
 */
class Part
{
    const CRLF = "\r\n";

    /**
     * @var array
     */
    protected $headers;

    /**
     * @var null|resource
     */
    protected $bodyStream;

    /**
     * @var int
     */
    protected $size = 0;

    /**
     * @var Part[]
     */
    protected $parts = array();

    /**
     * @var bool
     */
    protected $multipart;

    /**
     * @var string
     */
    protected $boundary;

    /**
     * MultiPart constructor
     *
     * @deprecated Use `::fromString()` and `::fromStream()` instead, constructor will become protected in future
     *
     * @param string $content
     *
     * @throws InvalidArgumentException
     */
    public function __construct($content = null) {
        if ($content !== null) {
            $this->parseContentStream(fopen('php://memory', 'r'), 1024, $content);
        }
    }

    /**
     * MultiPart constructor from string input
     *
     * @param string $content Request content as string
     *
     * @return Part
     *
     * @throws InvalidArgumentException
     */
    static public function fromString($content)
    {
        $object = new static();
        return $object->parseContentStream(fopen('php://memory', 'r'), 1024, $content);
    }

    /**
     * MultiPart constructor from stream input
     *
     * @param string|resource $stream    Request content as stream
     * @param int             $chunkSize Stream content is fetched in chunks, this parameter defines
     *                                   size of chunk in bytes, bigger value means higher memory usage
     * @param string          $content   Content prefix; if main stream contains body only (like
     *                                   `fopen('php://input', 'br')`), here you can specify headers with
     *                                   trailing `\r\n\r\n` (`Content-Type` is the only header required)
     *
     * @throws InvalidArgumentException
     */
    public function fromStream($stream, $chunkSize = 1024, $content = '')
    {
        $object = new static();
        return $object->parseContentStream($stream, $chunkSize, $content);
    }

    /**
     * Parse content stream
     *
     * @param resource $stream
     * @param int      $chunkSize
     * @param string   $content
     *
     * @return Part
     *
     * @throws InvalidArgumentException
     */
    protected function parseContentStream($stream, $chunkSize = 1024, &$content = '')
    {
        $body = $this->parseContentStreamHeader($stream, $chunkSize, $content);

        if (!$this->multipart) {
            $this->bodyStream = fopen('php://temp', 'bw+');
            fwrite($this->bodyStream, $body.stream_get_contents($stream));
            rewind($this->bodyStream);
            return $this;
        }
        // Get multi-part content
        // @todo boundary might appear on boundary, we need to handle this
        while (false === ($startBoundaryOccurrence = strpos($body, '--'.$this->boundary.self::CRLF))) {
            if (feof($stream)) {
                throw new InvalidArgumentException("Can't find multi-part content");
            }

            $body = fread($stream, $chunkSize);
        }

        // strlen doesn't take into account trailing CRLF since we'll need it below
        $body = substr($body, $startBoundaryOccurrence + strlen('--'.$this->boundary));

        while (0 === strpos($body, self::CRLF)) {
            $part = new static();
            $part->parseContentStreamHeader($stream, $chunkSize, $body);
            $body = $part->parseContentStreamBody($stream, $chunkSize, $body, $this->boundary);
            $this->parts[] = $part;
        }

        if (0 !== strpos($body, '--')) {
            throw new InvalidArgumentException(
                "Unexpected stream end, --$this->boundary-- expected, got: --$this->boundary".substr($body, 0, 2)
            );
        }
        return $this;
    }

    /**
     * @param resource $stream
     * @param int      $chunkSize
     * @param string   $content
     *
     * @return string Remainder from stream that appeared after headers
     *
     * @throws InvalidArgumentException
     */
    protected function parseContentStreamHeader($stream, $chunkSize, $content = '')
    {
        while (false === strpos($content, self::CRLF.self::CRLF)) {
            if (feof($stream)) {
                throw new InvalidArgumentException("Content is not valid, can't split headers and content");
            }
            $content .= fread($stream, $chunkSize);
        }

        list($headers, $remainder) = explode(self::CRLF.self::CRLF, $content, 2);

        $this->parseHeaders($headers);

        return $remainder;
    }

    /**
     * @todo boundary might appear on boundary, we need to handle this
     *
     * @param resource $stream
     * @param int      $chunkSize
     * @param string   $content
     * @param string   $boundary
     *
     * @return string Remainder from stream that appeared after parsed body and doesn't relate to
     *                current part
     *
     * @throws InvalidArgumentException
     */
    protected function parseContentStreamBody($stream, $chunkSize, $content, $boundary)
    {
        $this->bodyStream = fopen('php://temp', 'bw+');
        while (false === strpos($content, self::CRLF.'--'.$boundary)) {
            if (feof($stream)) {
                throw new InvalidArgumentException("Can't find multi-part content");
            }
            fwrite($this->bodyStream, $content);
            $this->size += strlen($content);
            $content = fread($stream, $chunkSize);
        }

        list($body, $remainder) = explode(self::CRLF.'--'.$boundary, $content, 2);

        $this->size += strlen($content);
        fwrite($this->bodyStream, $body);

        // Decode
        $encoding = strtolower($this->getHeader('Content-Transfer-Encoding'));
        switch ($encoding) {
            case 'base64':
                $body = base64_decode($body);
                break;
            case 'quoted-printable':
                $body = quoted_printable_decode($body);
                break;
        }

        // Convert to UTF-8 ( Not if binary or 7bit ( aka Ascii ) )
        if (!in_array($encoding, array('binary', '7bit'))) {
            // Charset
            $charset = static::getHeaderOption($this->getHeader('Content-Type'), 'charset');
            if (null === $charset) {
                // Try to detect
                $charset = mb_detect_encoding($body) ?: 'utf-8';
            }

            // Only convert if not UTF-8
            if ('utf-8' !== strtolower($charset)) {
                $body = mb_convert_encoding($body, 'utf-8', $charset);
                $this->size = strlen($body);
                ftruncate($this->bodyStream, 0);
                rewind($this->bodyStream);
                fwrite($this->bodyStream, $body);
            }
        }

        rewind($this->bodyStream);

        return $remainder;
    }

    /**
     * @param string $headers
     *
     * @return bool
     *
     * @throws InvalidArgumentException
     */
    protected function parseHeaders($headers)
    {
        // Regroup multiline headers
        $currentHeader = '';
        $headerLines = array();
        foreach (explode(self::CRLF, $headers) as $line) {
            if (empty($line)) {
                continue;
            }
            if (preg_match('/^\h+(.+)/', $line, $matches)) {
                // Multi line header
                $currentHeader .= ' '.$matches[1];
            } else {
                if (!empty($currentHeader)) {
                    $headerLines[] = $currentHeader;
                }
                $currentHeader = trim($line);
            }
        }

        if (!empty($currentHeader)) {
            $headerLines[] = $currentHeader;
        }

        // Parse headers
        $this->headers = array();
        foreach ($headerLines as $line) {
            $lineSplit = explode(':', $line, 2);
            if (2 === count($lineSplit)) {
                list($key, $value) = $lineSplit;
                // Decode value
                $value = mb_decode_mimeheader(trim($value));
            } else {
                // Bogus header
                $key = $lineSplit[0];
                $value = '';
            }
            // Case-insensitive key
            $key = strtolower($key);
            if (!isset($this->headers[$key])) {
                $this->headers[$key] = $value;
            } else {
                if (!is_array($this->headers[$key])) {
                    $this->headers[$key] = (array)$this->headers[$key];
                }
                $this->headers[$key][] = $value;
            }
        }

        $contentType = $this->getHeader('Content-Type');
        $this->multipart = 0 === strpos(static::getHeaderValue($contentType), 'multipart/');

        // If multipart - determine boundary
        if ($this->multipart) {
            $this->boundary = static::getHeaderOption($contentType, 'boundary');

            if (null === $this->boundary) {
                throw new InvalidArgumentException("Can't find boundary in content type");
            }
        }
    }

    /**
     * @return bool
     */
    public function isMultiPart()
    {
        return $this->multipart;
    }

    /**
     * @return string
     *
     * @throws LogicException if is multipart
     */
    public function getBody()
    {
        if ($this->isMultiPart()) {
            throw new LogicException("MultiPart content, there aren't body");
        } else {
            rewind($this->bodyStream);
            return stream_get_contents($this->bodyStream);
        }
    }

    /**
     * @return resource
     *
     * @throws LogicException if is multipart
     */
    public function getBodyStream()
    {
        if ($this->isMultiPart()) {
            throw new LogicException("MultiPart content, there aren't body stream");
        } else {
            rewind($this->bodyStream);
            return $this->bodyStream;
        }
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function getHeader($key, $default = null)
    {
        // Case-insensitive key
        $key = strtolower($key);
        if (isset($this->headers[$key])) {
            return $this->headers[$key];
        } else {
            return $default;
        }
    }

    /**
     * @param string $content
     *
     * @return array
     */
    static protected function parseHeaderContent($content)
    {
        $parts = explode(';', $content);
        $headerValue = array_shift($parts);
        $options = array();
        // Parse options
        foreach ($parts as $part) {
            if (!empty($part)) {
                $partSplit = explode('=', $part, 2);
                if (2 === count($partSplit)) {
                    list ($key, $value) = $partSplit;
                    $options[trim($key)] = trim($value, ' "');
                } else {
                    // Bogus option
                    $options[$partSplit[0]] = '';
                }
            }
        }

        return array($headerValue, $options);
    }

    /**
     * @param string $header
     *
     * @return string
     */
    static public function getHeaderValue($header)
    {
        list($value) = static::parseHeaderContent($header);

        return $value;
    }

    /**
     * @param string $header
     *
     * @return string
     */
    static public function getHeaderOptions($header)
    {
        list(,$options) = static::parseHeaderContent($header);

        return $options;
    }

    /**
     * @param string $header
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    static public function getHeaderOption($header, $key, $default = null)
    {
        $options = static::getHeaderOptions($header);

        if (isset($options[$key])) {
            return $options[$key];
        } else {
            return $default;
        }
    }

    /**
     * @return string
     */
    public function getMimeType()
    {
        // Find Content-Disposition
        $contentType = $this->getHeader('Content-Type');

        return static::getHeaderValue($contentType) ?: 'application/octet-stream';
    }

    /**
     * @return string|null
     */
    public function getName()
    {
        // Find Content-Disposition
        $contentDisposition = $this->getHeader('Content-Disposition');

        return static::getHeaderOption($contentDisposition, 'name');
    }

    /**
     * @return string|null
     */
    public function getFileName()
    {
        // Find Content-Disposition
        $contentDisposition = $this->getHeader('Content-Disposition');

        return static::getHeaderOption($contentDisposition, 'filename');
    }

    /**
     * @return int
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * @return bool
     */
    public function isFile()
    {
        return (bool)$this->getFileName();
    }

    /**
     * @return Part[]
     *
     * @throws LogicException if is not multipart
     */
    public function getParts()
    {
        if ($this->isMultiPart()) {
            return $this->parts;
        } else {
            throw new LogicException("Not MultiPart content, there aren't any parts");
        }
    }

    /**
     * @param string $name
     *
     * @return Part[]
     *
     * @throws LogicException if is not multipart
     */
    public function getPartsByName($name)
    {
        $parts = array();

        foreach ($this->getParts() as $part) {
            if ($part->getName() === $name) {
                $parts[] = $part;
            }
        }

        return $parts;
    }
}

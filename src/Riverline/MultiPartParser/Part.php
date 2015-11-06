<?php

namespace Riverline\MultiPartParser;

/**
 * Class Part
 * @package Riverline\MultiPartParser
 */
class Part
{
    const NEW_LINE = "\r\n";

    /**
     * @var array
     */
    protected $headers;

    /**
     * @var string
     */
    protected $body;

    /**
     * @var Part[]
     */
    protected $parts = array();

    /**
     * @var bool
     */
    protected $multipart = false;

    /**
     * MultiPart constructor.
     * @param string $content
     */
    public function __construct($content)
    {
        // Detect new line stype
        if (false !== strpos($content, "\r\n\r\n")) {
            $newLine = "\r\n";
        } else {
            $newLine = "\n";
        }

        // Split headers and body
        $splits = explode($newLine.$newLine, $content, 2);

        if (count($splits) < 2) {
            throw new \InvalidArgumentException("Content is not valid");
        }

        list ($headers, $body) = $splits;

        // Regroup multiline headers
        $currentHeader = '';
        $headerLines = array();
        foreach (explode($newLine, $headers) as $line) {
            if (empty($line)) {
                // Skip empty line
                continue;
            }
            if (' ' === $line{0}) {
                // Multi line header
                $currentHeader .= ' '.trim($line);
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
            list($key, $value) = explode(': ', $line, 2);
            // Decode value
            $value = mb_decode_mimeheader($value);
            // Case-insensitive key
            $key = strtolower($key);
            if (!isset($this->headers[$key])) {
                $this->headers[$key] = $value;
            } else {
                if (!is_array($this->headers[$key])) {
                    $this->headers[$key] = ((array) $this->headers[$key]);
                }
                $this->headers[$key][] = $value;
            }
        }

        // Is MultiPart ?
        $contentType = $this->getHeader('Content-Type');
        if (null !== $contentType) {
            if ('multipart' === strstr(self::getHeaderValue($contentType), '/', true)) {
                // MultiPart !
                $this->multipart = true;
                $boundary = self::getHeaderOption($contentType, 'boundary');

                if (null === $boundary) {
                    throw new \InvalidArgumentException("Can't find boundary in content type");
                }

                $separator = '--'.$boundary;

                // Find start boundary
                $firstSeparatorPos = strpos($body, $separator.$newLine);
                if (false === $firstSeparatorPos) {
                    throw new \InvalidArgumentException("Can't find first boundary in content");
                }

                // Find end boundary
                $lastSeparatorPos = strpos($body, $newLine.$separator.'--', $firstSeparatorPos);
                if (false === $lastSeparatorPos) {
                    throw new \InvalidArgumentException("Content is incomplete, missing end boundary");
                }

                // Get content
                $body = substr($body, $firstSeparatorPos+strlen($separator), $lastSeparatorPos - $firstSeparatorPos - strlen($separator));

                // Get parts
                $parts = explode($newLine.$separator.$newLine, $body);

                foreach ($parts as $part) {
                    $this->parts[] = new self($part);
                }
            }
        }

        // Process Body if not Multipart
        if (!$this->isMultiPart()) {
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
                $charset = self::getHeaderOption($contentType, 'charset');
                if (null === $charset) {
                    // Try to detect
                    $detectedCharset = mb_detect_encoding($body);
                    if (false !== $detectedCharset) {
                        $charset = $detectedCharset;
                    } else {
                        // Default
                        $charset = 'utf-8';
                    }
                }

                // Only convert if not UTF-8
                if ('utf-8' !== strtolower($charset)) {
                    $body = mb_convert_encoding($body, 'utf-8', $charset);
                }
            }

            $this->body = $body;
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
     * @throw \LogicException if is multipart
     */
    public function getBody()
    {
        if ($this->isMultiPart()) {
            throw new \LogicException("MultiPart content, there aren't body");
        } else {
            return $this->body;
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
     * @return array
     */
    static protected function parseHeaderContent($content)
    {
        $parts = explode(';', $content);
        $headerValue = array_shift($parts);
        $options = array();
        if (count($parts) > 0) {
            // Parse options
            foreach ($parts as $part) {
                list ($key, $value) = explode('=', $part, 2);
                $options[trim($key)] = trim($value, ' "');
            }
        }

        return array($headerValue, $options);
    }

    /**
     * @param string $header
     * @return string
     */
    static public function getHeaderValue($header)
    {
        list($value) = self::parseHeaderContent($header);

        return $value;
    }

    /**
     * @param string $header
     * @return string
     */
    static public function getHeaderOptions($header)
    {
        list(,$options) = self::parseHeaderContent($header);

        return $options;
    }

    /**
     * @param string $header
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    static public function getHeaderOption($header, $key, $default = null)
    {
        list(,$options) = self::parseHeaderContent($header);

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
        if (null !== $contentType) {
            return self::getHeaderValue($contentType);
        }

        return 'application/octet-stream';
    }

    /**
     * @return string|null
     */
    public function getName()
    {
        // Find Content-Disposition
        $contentDisposition = $this->getHeader('Content-Disposition');
        if (null !== $contentDisposition) {
            return self::getHeaderOption($contentDisposition, 'name');
        }

        return null;
    }

    /**
     * @return Part[]
     * @throw \LogicException if is not multipart
     */
    public function getParts()
    {
        if ($this->isMultiPart()) {
            return $this->parts;
        } else {
            throw new \LogicException("Not MultiPart content, there aren't any parts");
        }
    }

    /**
     * @param string $name
     * @return Part[]
     * @throw \LogicException if is not multipart
     */
    public function getPartsByName($name)
    {
        if ($this->isMultiPart()) {
            $parts = array();

            foreach ($this->parts as $part) {
                if ($part->getName() === $name) {
                    $parts[] = $part;
                }
            }

            return $parts;
        } else {
            throw new \LogicException("Not MultiPart content, there aren't any parts");
        }
    }
}
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
     * @var string
     */
    protected $mimeType = 'application/octet-stream';

    /**
     * @var array
     */
    protected $mimeOptions = array();

    /**
     * @var array
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
        // Split headers and body
        $splits = explode(self::NEW_LINE.self::NEW_LINE, $content, 2);

        if (count($splits) < 2) {
            throw new \InvalidArgumentException("Content is not valid");
        }

        list ($headers, $body) = $splits;

        // Parse headers
        $this->headers = $this->parseHeaders($headers);

        // Is MultiPart ?
        $contentType = $this->getHeader('Content-Type');
        if (null !== $contentType) {
            // Extract MimeType
            list($this->mimeType, $this->mimeOptions) = $this->parseHeaderContent($contentType);

            if ('multipart' === strstr($this->mimeType, '/', true)) {
                // MultiPart !
                $this->multipart = true;

                if (!isset($this->mimeOptions['boundary'])) {
                    throw new \InvalidArgumentException("Can't find boundary in content type");
                }

                $boundary = '--'.$this->mimeOptions['boundary'];

                // Find start boundary
                $firstBoundaryPos = strpos($body, $boundary.self::NEW_LINE);
                if (false === $firstBoundaryPos) {
                    throw new \InvalidArgumentException("Can't find first boundary in content");
                }

                // Find end boundary
                $lastBoundaryPos = strpos($body, self::NEW_LINE.$boundary.'--', $firstBoundaryPos);
                if (false === $lastBoundaryPos) {
                    throw new \InvalidArgumentException("Content is incomplete, missing end boundary");
                }

                // Get content
                $body = substr($body, $firstBoundaryPos+strlen($boundary), $lastBoundaryPos - $firstBoundaryPos - strlen($boundary));

                // Get parts
                $parts = explode(self::NEW_LINE.$boundary.self::NEW_LINE, $body);

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

            // Normalize ( Not if binary or 7bit ( aka Ascii ) )
            if (!in_array($encoding, array('binary', '7bit'))) {
                // Charset
                $charset = 'utf-8';
                if (isset($this->mimeOptions['charset'])) {
                    $charset = $this->mimeOptions['charset'];
                } else {
                    // Try to detect
                    $detectedCharset = mb_detect_encoding($body, array('UTF-8', 'ISO-8859-15', 'ISO-8859-1'));
                    if (false !== $detectedCharset) {
                        $charset = $detectedCharset;
                    }
                }

                // Only convert if not UTF-8
                if ('utf-8' !== strtolower($charset)) {
                    $body = mb_convert_encoding($body, 'utf-8', $this->mimeOptions['charset']);
                }
            }

            $this->body = $body;
        }
    }

    /**
     * @param string $content
     * @return array
     */
    protected function parseHeaders($content)
    {
        $currentHeader = '';
        $headerLines = array();

        // Regroup multiline headers
        foreach (explode(self::NEW_LINE, $content) as $line) {
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
        $headers = array();
        foreach ($headerLines as $line) {
            list($key, $value) = explode(': ', $line, 2);
            // Decode value
            $value = mb_decode_mimeheader($value);
            // Case-insensitive key
            $key = strtolower($key);
            if (!isset($headers[$key])) {
                $headers[$key] = $value;
            } else {
                if (!is_array($headers[$key])) {
                    $headers[$key] = ((array) $headers[$key]);
                }
                $headers[$key][] = $value;
            }
        }

        return $headers;
    }

    /**
     * @param string $content
     * @return array
     */
    protected function parseHeaderContent($content)
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
     * @return array
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
}
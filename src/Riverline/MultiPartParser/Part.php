<?php

namespace Riverline\MultiPartParser;

/**
 * Class Part
 * @package Riverline\MultiPartParser
 */
class Part
{
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
     * @throws \InvalidArgumentException
     */
    public function __construct($content)
    {
        $this->parseContentString($content);
    }

    /**
     * Parse content string
     * @param string $content
     * @throws \InvalidArgumentException
     */
    protected function parseContentString($content)
    {
        // Split headers and body
        $splits = preg_split('/(\r?\n){2}/', $content, 2);

        if (count($splits) < 2) {
            throw new \InvalidArgumentException("Content is not valid, can't split headers and content");
        }

        list ($headers, $body) = $splits;

        $this->parseHeaders($headers);

        // Is MultiPart ?
        $contentType = $this->getHeader('Content-Type');
        if ('multipart' === strstr(self::getHeaderValue($contentType), '/', true)) {
            // MultiPart !
            $this->multipart = true;
            $boundary = self::getHeaderOption($contentType, 'boundary');

            if (null === $boundary) {
                throw new \InvalidArgumentException("Can't find boundary in content type");
            }

            $separator = '--'.preg_quote($boundary, '/');

            // Get multi-part content
            if (0 === preg_match('/'.$separator.'\r?\n(.+)\r?\n'.$separator.'--/s', $body, $matches)) {
                throw new \InvalidArgumentException("Can't find multi-part content");
            }

            // Get parts
            $parts = preg_split('/\r?\n'.$separator.'\r?\n/', $matches[1]);

            foreach ($parts as $part) {
                $this->parts[] = new self($part);
            }
        } else {
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
                    $charset = mb_detect_encoding($body) ?: 'utf-8';
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
     * @param string $headers
     *
     * @return bool
     */
    protected function parseHeaders($headers)
    {
        // Regroup multiline headers
        $currentHeader = '';
        $headerLines = array();
        foreach (preg_split('/\r?\n/', $headers) as $line) {
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
     * @throws \LogicException if is multipart
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
        $options = self::getHeaderOptions($header);

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

        return self::getHeaderValue($contentType) ?: 'application/octet-stream';
    }

    /**
     * @return string|null
     */
    public function getName()
    {
        // Find Content-Disposition
        $contentDisposition = $this->getHeader('Content-Disposition');

        return self::getHeaderOption($contentDisposition, 'name');
    }

    /**
     * @return string|null
     */
    public function getFileName()
    {
        // Find Content-Disposition
        $contentDisposition = $this->getHeader('Content-Disposition');

        return self::getHeaderOption($contentDisposition, 'filename');
    }

    /**
     * @return bool
     */
    public function isFile()
    {
        return !is_null($this->getFileName());
    }

    /**
     * @return Part[]
     * @throws \LogicException if is not multipart
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
     * @throws \LogicException if is not multipart
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

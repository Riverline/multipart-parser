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
 * Class StreamedPart
 */
class StreamedPart
{
    /**
     * @var resource
     */
    private $stream;

    /**
     * @var array
     */
    private $headers;

    /**
     * @var int
     */
    private $bodyOffset;

    /**
     * @var StreamedPart[]
     */
    private $parts = array();

    /**
     * StreamParser constructor.
     *
     * @param resource $stream
     */
    public function __construct($stream)
    {
        if (false === is_resource($stream)) {
            throw new \InvalidArgumentException('Input is not a stream');
        }

        $this->stream = $stream;

        // Reset the stream
        rewind($this->stream);

        // Parse headers
        $endOfHeaders = false;
        $bufferSize = 8192;
        $headerLines = [];
        $buffer = '';

        while (false !== ($line = fgets($this->stream, $bufferSize))) {
            // Append to buffer
            $buffer .= rtrim($line, "\r\n");

            if (strlen($line) === $bufferSize-1) {
                // EOL not reached, continue
                continue;
            }

            if ('' === $buffer) {
                // Empty line cause by double new line, we reached the end of the headers section
                $endOfHeaders = true;
                break;
            }

            // Detect horizontal whitescapes before header
            $trimmed = ltrim($buffer);
            if (strlen($buffer) > strlen($trimmed)) {
                // Multi lines header, append to previous line
                $headerLines[count($headerLines)-1] .= "\x20".$trimmed;
            } else {
                $headerLines[] = $buffer;
            }

            // Reset buffer
            $buffer = '';
        }

        if (false === $endOfHeaders) {
            throw new \InvalidArgumentException('Content is not valid');
        }

        $this->headers = [];
        foreach ($headerLines as $line) {
            // We don't allow malformed headers that could have a very long length.
            // Indeed, in HTTP contexts these could be used for DoS/DoW attacks by slowing down the parsing.
            // Most web server allow a maximum of 8192 characters for an header line, so we'll use that value.
            if (strlen($line) > 8192) {
                throw new \InvalidArgumentException('Malformed header: header value is too long');
            }

            $lineSplit = explode(':', $line, 2);

            if (2 === count($lineSplit)) {
                list($key, $value) = $lineSplit;
                // Decode value
                $value = self::decodeHeaderValue(trim($value));
            } else {
                // Bogus header
                $key = $lineSplit[0];
                $value = '';
            }

            // Case-insensitive key
            $key = strtolower($key);
            if (false === key_exists($key, $this->headers)) {
                $this->headers[$key] = $value;
            } else {
                // Already got an header with this key, convert to array
                if (false === is_array($this->headers[$key])) {
                    $this->headers[$key] = (array) $this->headers[$key];
                }
                $this->headers[$key][] = $value;
            }
        }

        $this->bodyOffset = ftell($stream);

        // Is MultiPart ?
        if ($this->isMultiPart()) {
            // MultiPart !
            $boundary = self::getHeaderOption($this->getHeader('Content-Type'), 'boundary');

            if (null === $boundary) {
                throw new \InvalidArgumentException("Can't find boundary in content type");
            }

            $separator = '--'.$boundary;

            $partOffset = 0;
            $endOfBody = false;
            $eofLength = 0;

            while ($line = fgets($this->stream, $bufferSize)) {
                $trimmed = rtrim($line, "\r\n");

                // Search the separator
                if ($trimmed === $separator || $trimmed === $separator.'--') {
                    if ($partOffset > 0) {
                        $currentOffset = ftell($this->stream);
                        $partLength = $currentOffset - $partOffset - strlen($line) - $eofLength;

                        // Copy part in a new stream
                        $partStream = fopen('php://temp', 'rw');
                        stream_copy_to_stream($this->stream, $partStream, $partLength, $partOffset);
                        $this->parts[] = new self($partStream);
                        // Reset current stream offset
                        fseek($this->stream, $currentOffset);
                    }

                    if ($trimmed === $separator.'--') {
                        // We reach the end separator
                        $endOfBody = true;
                        break;
                    }

                    // Update the part offset
                    $partOffset = ftell($this->stream);
                }

                // Get end of line length (should be 2)
                $eofLength = strlen($line) - strlen($trimmed);
            }


            if (0 === count($this->parts)
                || false === $endOfBody
            ) {
                throw new \LogicException("Can't find multi-part content");
            }
        }
    }


    /**
     * @return bool
     */
    public function isMultiPart()
    {
        return ('multipart' === mb_strtolower(mb_strstr(
            self::getHeaderValue($this->getHeader('Content-Type')),
            '/',
            true
        )));
    }

    /**
     * @return string
     *
     * @throws \LogicException if is multipart
     */
    public function getBody()
    {
        if ($this->isMultiPart()) {
            throw new \LogicException("MultiPart content, there aren't body");
        }

        $body = stream_get_contents($this->stream, -1, $this->bodyOffset);

        // Decode
        $encoding = strtolower((string) $this->getHeader('Content-Transfer-Encoding', '7bit'));
        switch ($encoding) {
            case 'base64':
                $body = base64_decode($body);
                break;
            case 'quoted-printable':
                $body = quoted_printable_decode($body);
                break;
        }

        // Convert to UTF-8 ( Not if binary or 7bit ( aka Ascii ) )
        if (false === in_array($encoding, array('binary', '7bit'))) {
            // Charset
            $contentType = $this->getHeader('Content-Type');
            $charset = self::getHeaderOption($contentType, 'charset');
            if (null === $charset && $this->isText()) {
                // Try to detect, only on text: binary data could be detected as ASCII and corrupted
                $charset = mb_detect_encoding($body) ?: 'utf-8';
            }

            // Only convert if not UTF-8, keep the body as is if the charset is not supported
            if (null !== $charset && 'utf-8' !== strtolower($charset)) {
                $converted = self::convertToUtf8($body, $charset);
                if (false !== $converted) {
                    $body = $converted;
                }
            }
        }

        return $body;
    }

    /**
     * Text content: "text/*", no Content-Type at all ( text/plain by default, RFC 2045 and RFC 7578 )
     * or a malformed one, to keep the previous behaviour
     *
     * @return bool
     */
    private function isText()
    {
        $mimeType = strtolower(trim(self::getHeaderValue($this->getHeader('Content-Type'))));

        return false === strpos($mimeType, '/') || 0 === strpos($mimeType, 'text/');
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
     *
     * @param mixed  $default
     *
     * @return mixed
     */
    public function getHeader($key, $default = null)
    {
        // Case-insensitive key
        $key = strtolower($key);

        if (false === isset($this->headers[$key])) {
            return $default;
        }

        return $this->headers[$key];
    }

    /**
     * @param string $header
     *
     * @return string
     */
    public static function getHeaderValue($header)
    {
        list($value) = self::parseHeaderContent($header);

        return $value;
    }

    /**
     * @param string $header
     *
     * @return array
     */
    public static function getHeaderOptions($header)
    {
        list(, $options) = self::parseHeaderContent($header);

        return $options;
    }

    /**
     * @param string $header
     * @param string $key
     *
     * @param mixed  $default
     *
     * @return mixed
     */
    public static function getHeaderOption($header, $key, $default = null)
    {
        $options = self::getHeaderOptions($header);

        if (false === isset($options[$key])) {
            return $default;
        }

        return $options[$key];
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
        return (false === is_null($this->getFileName()));
    }

    /**
     * @return StreamedPart[]
     *
     * @throws \LogicException if is not multipart
     */
    public function getParts()
    {
        if (false === $this->isMultiPart()) {
            throw new \LogicException("Not MultiPart content, there aren't any parts");
        }

        return $this->parts;
    }

    /**
     * @param string $name
     *
     * @return Part[]
     *
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

    /**
     * Decode RFC 2047 encoded-words and return a valid UTF-8 string
     *
     * Text outside encoded-words is kept as is: browsers send non-ASCII names as raw UTF-8 ( RFC 7578 )
     * and mb_decode_mimeheader() would replace each of their bytes with "?".
     *
     * @param string $value
     *
     * @return string
     */
    private static function decodeHeaderValue($value)
    {
        // Bare CR or LF left in an unfolded header line
        $value = str_replace(array("\r", "\n"), ' ', $value);

        if (false !== strpos($value, '=?')) {
            // As tolerant as mb_decode_mimeheader(): spaces or "?" in the encoded text, missing "?=" at the end
            // Whitespace between adjacent encoded-words is kept, like mb_decode_mimeheader() did
            $value = preg_replace_callback(
                // Charset may be followed by an RFC 2231 language ( =?UTF-8*fr?Q?...?= )
                '/=\?([^?\s*]+)(?:\*[^?\s]*)?\?([BbQq])\?(.*?)(?:\?=|$)/',
                function ($matches) {
                    if ('b' === strtolower($matches[2])) {
                        $text = base64_decode(preg_replace('/\s+/', '', $matches[3]));
                    } else {
                        $text = quoted_printable_decode(str_replace('_', ' ', $matches[3]));
                    }

                    if (false !== $text) {
                        $text = self::convertToUtf8($text, $matches[1]);
                    }

                    // Keep the encoded-word as is if it can't be decoded
                    return (false === $text) ? $matches[0] : $text;
                },
                $value
            );
        }

        // Invalid UTF-8 bytes are replaced with "?"
        if (false === mb_check_encoding($value, 'utf-8')) {
            $value = self::convertToUtf8($value, 'utf-8');
        }

        return $value;
    }

    /**
     * @param string $text
     * @param string $charset
     *
     * @return string|false false if the charset is not supported
     */
    private static function convertToUtf8($text, $charset)
    {
        // PHP < 8 doesn't fail on an unknown charset, it converts from another one
        // PHP >= 8 throws a ValueError
        if (false === self::isSupportedCharset($charset)) {
            return false;
        }

        return mb_convert_encoding($text, 'utf-8', $charset);
    }

    /**
     * @param string $charset
     *
     * @return bool
     */
    private static function isSupportedCharset($charset)
    {
        static $charsets = null;

        if (null === $charsets) {
            // Not charsets: transfer encodings and mbstring internals ( some are deprecated since PHP 8.2 )
            $excluded = array(
                'pass', 'auto', 'wchar', 'byte2be', 'byte2le', 'byte4be', 'byte4le',
                'base64', 'qprint', 'quoted-printable', 'html-entities', 'uuencode', '7bit', '8bit',
            );

            // Some encodings have no MIME name and emit a warning, don't let it reach the application error handler
            set_error_handler(function () {
                return true;
            });

            $list = array();
            try {
                foreach (mb_list_encodings() as $encoding) {
                    if (in_array(strtolower($encoding), $excluded, true)) {
                        continue;
                    }

                    $names = mb_encoding_aliases($encoding);
                    $names = is_array($names) ? $names : array();
                    $names[] = $encoding;
                    // Shift_JIS, BIG5, ISO-2022-JP...
                    $names[] = mb_preferred_mime_name($encoding);

                    foreach ($names as $name) {
                        if (is_string($name) && '' !== $name) {
                            $list[strtolower($name)] = true;
                        }
                    }
                }
            } finally {
                restore_error_handler();
            }

            $charsets = $list;
        }

        return isset($charsets[strtolower($charset)]);
    }

    /**
     * @param string $content
     *
     * @return array
     */
    private static function parseHeaderContent($content)
    {
        $parts = explode(';', (string) $content);
        $headerValue = array_shift($parts);
        $options = array();
        // Parse options
        foreach ($parts as $part) {
            if (false === empty($part)) {
                $partSplit = explode('=', $part, 2);
                if (2 === count($partSplit)) {
                    list ($key, $value) = $partSplit;
                    if ('*' === substr($key, -1)) {
                        // RFC 5987
                        $key = substr($key, 0, -1);
                        if (preg_match(
                            "/(?P<charset>[\w!#$%&+^_`{}~-]+)'(?P<language>[\w-]*)'(?P<value>.*)$/",
                            $value,
                            $matches
                        )) {
                            $value = rawurldecode($matches['value']);
                            $converted = self::convertToUtf8($value, $matches['charset']);
                            // Unsupported charset, keep the bytes but as valid UTF-8
                            $value = (false !== $converted) ? $converted : self::convertToUtf8($value, 'utf-8');
                        }
                    }
                    $options[trim($key)] = trim($value, ' "');
                } else {
                    // Bogus option
                    $options[$partSplit[0]] = '';
                }
            }
        }

        return array($headerValue, $options);
    }
}

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
     * Maximum number of options parsed in a header, the others are ignored
     */
    const MAX_HEADER_OPTIONS = 100;

    /**
     * Default maximum number of parts in a whole content, nested parts included
     */
    const DEFAULT_MAX_PARTS = 1000;

    /**
     * Default maximum number of nested multipart levels
     */
    const DEFAULT_MAX_DEPTH = 16;

    /**
     * @var int|null null for no limit
     */
    private static $maxParts = self::DEFAULT_MAX_PARTS;

    /**
     * @var int|null null for no limit
     */
    private static $maxDepth = self::DEFAULT_MAX_DEPTH;

    /**
     * Nesting level of the part being parsed
     *
     * @var int
     */
    private static $depth = 0;

    /**
     * Number of parts found in the content being parsed
     *
     * @var int
     */
    private static $partsCount = 0;

    /**
     * @var resource
     */
    private $stream;

    /**
     * Raw header values, decoded on read
     *
     * @var array
     */
    private $headers;

    /**
     * Decoded header values, by key
     *
     * @var array
     */
    private $decodedHeaders = array();

    /**
     * Last parsed header contents
     *
     * @var array
     */
    private static $parsedHeaderContents = array();

    /**
     * @var int
     */
    private $bodyOffset;

    /**
     * @var StreamedPart[]
     */
    private $parts = array();

    /**
     * Maximum number of parts in a whole content, nested parts included
     *
     * @param int|null $maxParts null for no limit
     */
    public static function setMaxParts($maxParts)
    {
        self::$maxParts = (null === $maxParts) ? null : (int) $maxParts;
    }

    /**
     * Maximum number of nested multipart levels
     *
     * @param int|null $maxDepth null for no limit
     */
    public static function setMaxDepth($maxDepth)
    {
        self::$maxDepth = (null === $maxDepth) ? null : (int) $maxDepth;
    }

    /**
     * StreamParser constructor.
     *
     * @param resource $stream
     *
     * @throws \InvalidArgumentException if the content is not valid
     * @throws \LogicException if the multipart content is not valid
     * @throws LimitExceededException if the content has too many parts or nesting levels
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
                // Decoded on read, only the headers used cost anything
                $value = trim($value);
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
            if (0 === self::$depth) {
                // New content
                self::$partsCount = 0;
            }

            if (null !== self::$maxDepth && self::$depth >= self::$maxDepth) {
                throw new LimitExceededException('Too many nested multipart levels, the limit is '.self::$maxDepth);
            }

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

                        // Negative length: two adjacent separators, there is no part
                        // ( stream_copy_to_stream() would copy until the end of the stream )
                        if ($partLength >= 0) {
                            if (null !== self::$maxParts && ++self::$partsCount > self::$maxParts) {
                                throw new LimitExceededException('Too many parts, the limit is '.self::$maxParts);
                            }

                            // Copy part in a new stream
                            $partStream = fopen('php://temp', 'rw');
                            stream_copy_to_stream($this->stream, $partStream, $partLength, $partOffset);

                            self::$depth++;
                            try {
                                $this->parts[] = new self($partStream);
                            } finally {
                                self::$depth--;
                            }
                        }

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
        foreach (array_keys($this->headers) as $key) {
            $this->getHeader($key);
        }

        return $this->decodedHeaders;
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

        if (false === array_key_exists($key, $this->decodedHeaders)) {
            $value = $this->headers[$key];
            $this->decodedHeaders[$key] = is_array($value)
                ? array_map(array(__CLASS__, 'decodeHeaderValue'), $value)
                : self::decodeHeaderValue($value);
        }

        return $this->decodedHeaders[$key];
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

        if (isset($options[$key])) {
            return $options[$key];
        }

        // Case-insensitive key
        foreach ($options as $optionKey => $value) {
            if (0 === strcasecmp($optionKey, $key)) {
                return $value;
            }
        }

        return $default;
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
            // Decoded in PHP, mb_decode_mimeheader() results vary with PHP versions ( "_" in Q encoding before PHP 8.3 )
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
     * Parse a header value and its parameters ( RFC 2045, RFC 2231, RFC 5987 )
     *
     * @param string $content
     *
     * @return array
     */
    private static function parseHeaderContent($content)
    {
        $content = (string) $content;

        // The same header is often parsed several times in a row ( isFile(), getName(), getFileName() )
        if (isset(self::$parsedHeaderContents[$content])) {
            return self::$parsedHeaderContents[$content];
        }
        if (count(self::$parsedHeaderContents) >= 8) {
            array_shift(self::$parsedHeaderContents);
        }

        return self::$parsedHeaderContents[$content] = self::doParseHeaderContent($content);
    }

    /**
     * @param string $content
     *
     * @return array
     */
    private static function doParseHeaderContent($content)
    {
        $segments = self::splitHeaderContent($content);
        $headerValue = trim(array_shift($segments));
        $options = array();
        $extendedOptions = array();

        // Legitimate headers have a few options, even with RFC 2231 continuations, ignore the rest
        if (count($segments) > self::MAX_HEADER_OPTIONS) {
            $segments = array_slice($segments, 0, self::MAX_HEADER_OPTIONS);
        }

        // Parse options
        foreach ($segments as $segment) {
            if ('' === trim($segment)) {
                continue;
            }

            $partSplit = explode('=', $segment, 2);
            $key = trim($partSplit[0]);

            if (1 === count($partSplit)) {
                // Bogus option
                $options[$key] = '';
                continue;
            }

            $value = trim($partSplit[1]);

            if (false !== strpos($key, '*')
                && preg_match('/^(?P<key>[^*]+)\*(?:(?P<section>\d+)(?P<encoded>\*)?)?$/', $key, $matches)
            ) {
                // Extended option ( key*=, key*0=, key*0*= )
                if (isset($matches['section']) && '' !== $matches['section']) {
                    $section = (int) $matches['section'];
                    $encoded = isset($matches['encoded']) && '' !== $matches['encoded'];
                } else {
                    $section = 0;
                    $encoded = true;
                }
                $extendedKey = strtolower($matches['key']);
                if (false === isset($extendedOptions[$extendedKey])) {
                    $extendedOptions[$extendedKey] = array('key' => $matches['key'], 'sections' => array());
                }
                // Not trimmed, continuations may be split on a space
                $extendedOptions[$extendedKey]['sections'][$section] = array(self::unquote($value, false), $encoded);
            } else {
                $options[$key] = self::unquote($value);
            }
        }

        // Extended options take precedence over regular ones ( RFC 6266 )
        if (count($extendedOptions) > 0) {
            $keysByLowercase = array();
            foreach (array_keys($options) as $key) {
                $keysByLowercase[strtolower($key)][] = $key;
            }

            foreach ($extendedOptions as $extendedKey => $extendedOption) {
                if (isset($keysByLowercase[$extendedKey])) {
                    foreach ($keysByLowercase[$extendedKey] as $key) {
                        unset($options[$key]);
                    }
                }
                $options[$extendedOption['key']] = self::decodeExtendedOption($extendedOption['sections']);
            }
        }

        return array($headerValue, $options);
    }

    /**
     * Split a header content on semicolons, except inside quoted strings
     *
     * @param string $content
     *
     * @return string[]
     */
    private static function splitHeaderContent($content)
    {
        if (false === strpos($content, '"')) {
            return explode(';', $content);
        }

        // Only quoted strings ( with \x quoted pairs ) and other characters, possessive to avoid backtracking
        if (1 !== preg_match('/^(?:[^"]++|"(?:[^"\\\\]++|\\\\.)*+")*+$/s', $content)) {
            // Unclosed quote ( or a path ending with a backslash ), split as before
            return explode(';', $content);
        }

        // Split on semicolons outside of quoted strings
        $segments = preg_split('/"(?:[^"\\\\]++|\\\\.)*+"(*SKIP)(*FAIL)|;/s', $content);

        // PCRE limit reached, split as before
        return is_array($segments) ? $segments : explode(';', $content);
    }

    /**
     * @param string $value
     * @param bool   $trim
     *
     * @return string
     */
    private static function unquote($value, $trim = true)
    {
        if (strlen($value) < 2 || '"' !== $value[0] || '"' !== substr($value, -1)) {
            // Not a quoted string, trim as before
            return trim($value, ' "');
        }

        // Only unescape \" so Windows and UNC paths sent by old clients are kept
        $value = str_replace('\\"', '"', substr($value, 1, -1));

        // Spaces are trimmed as before
        return $trim ? trim($value, ' ') : $value;
    }

    /**
     * Join RFC 2231 continuations and decode the charset of an extended option
     *
     * @param array $sections [section => [value, encoded]]
     *
     * @return string
     */
    private static function decodeExtendedOption(array $sections)
    {
        ksort($sections);

        $charset = null;
        $value = '';
        $first = true;
        foreach ($sections as $section) {
            list($text, $encoded) = $section;

            // The charset is only in the first section
            if ($first && $encoded) {
                if (preg_match("/^(?P<charset>[\w!#$%&+^_`{}~-]*)'(?P<language>[\w-]*)'(?P<value>.*)$/s", $text, $matches)) {
                    $charset = $matches['charset'];
                    $text = $matches['value'];
                } else {
                    // Malformed, keep it as is as before
                    $encoded = false;
                }
            }

            if ($encoded && null !== $charset) {
                $text = rawurldecode($text);
            }

            $value .= $text;
            $first = false;
        }

        $converted = ('' !== (string) $charset) ? self::convertToUtf8($value, $charset) : false;

        // Unsupported or missing charset, keep the bytes but as valid UTF-8
        return (false !== $converted) ? $converted : self::convertToUtf8($value, 'utf-8');
    }
}

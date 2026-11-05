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
 * Thrown when a multipart content has too many parts or too many nesting levels
 *
 * Extends \LengthException, a \LogicException like the other parsing errors
 */
class LimitExceededException extends \LengthException
{
}

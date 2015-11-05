# README

## What is Riverline\MultiPartParser

``Riverline\MultiPartParse`` is a simple lib to parse multipart document ( multipart email, multipart form, etc ...) and manage each part encoding and charset to extract their content.

## Requirements

* PHP 5.1

## Installation

``Riverline\MultiPartParse`` is compatible with composer and any prs-0 autoloader

## Usage

```php
<?php

use Riverline\MultiPartParser\Part;

$content = <<<EOL
User-Agent: curl/7.21.2 (x86_64-apple-darwin)
Host: localhost:8080
Accept: */*
Content-Type: multipart/form-data; boundary=----------------------------83ff53821b7c

------------------------------83ff53821b7c
Content-Disposition: form-data; name="foo"

bar
------------------------------83ff53821b7c--
EOL;

$document = new Part($content);

if ($document->isMultiPart()) {
    $parts = $document->getParts();
    echo $parts[0]->getBody(); // Output bar
}
```

## TODO

* Process Content-Disposition to find part by name
* Add file part helper

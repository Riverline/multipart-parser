<?php

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = @include(__DIR__.'/../vendor/autoload.php');

if (!$loader) {
    die("You must set up the project dependencies, run the following commands:
wget http://getcomposer.org/composer.phar
php composer.phar install
");
}

$loader->add('Riverline\MultiPartParser', __DIR__);

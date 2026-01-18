#!/usr/bin/env php
<?php

declare(strict_types=1);

putenv('PHP_CS_FIXER_IGNORE_ENV=1');
include __DIR__ . '/../vendor/bin/php-cs-fixer';

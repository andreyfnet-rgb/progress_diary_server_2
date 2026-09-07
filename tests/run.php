<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/TestRunner.php';

$runner = new TestRunner();

$testFiles = glob(__DIR__ . '/*Test.php');
sort($testFiles);
foreach ($testFiles as $file) {
    $register = require $file;
    $register($runner);
}

exit($runner->summary());

<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// To make sure that short names are turned off in Swoole, so that we could run the tests both in parallel (using Swoole)
// or in blocking mode (default behavior).
if (function_exists('go')) {
    fwrite(STDERR, "Error: Please have PHP option \"swoole.use_shortname\" turned off (swoole.use_shortname=Off).\n");
    exit(1);
}

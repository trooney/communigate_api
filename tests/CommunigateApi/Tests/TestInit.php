<?php
/*
* This file bootstraps the test environment.
*/
namespace Communigate\Tests;

error_reporting(E_ALL | E_STRICT);

// Add your class dir to include path
set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__ . '/../../../lib');

// Add autoloader
spl_autoload_register('spl_autoload');
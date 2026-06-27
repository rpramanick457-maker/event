<?php
// api/index.php

// Set the include path to the root directory to allow relative includes to resolve properly
set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__DIR__));

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// Determine the script to run
if ($path === '/' || $path === '') {
    $script = 'index.php';
} else {
    $script = ltrim($path, '/');
}

// Security check to prevent directory traversal
$script = basename($script);

if (empty($script)) {
    $script = 'index.php';
}

// If the requested path does not end with .php, check if the .php file exists
if (!str_ends_with($script, '.php')) {
    if (file_exists(dirname(__DIR__) . '/' . $script . '.php')) {
        $script .= '.php';
    } else {
        $script = 'index.php';
    }
}

$file = dirname(__DIR__) . '/' . $script;

if (file_exists($file)) {
    require $file;
} else {
    http_response_code(404);
    echo "404 Not Found";
}

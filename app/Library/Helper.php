<?php

if (!function_exists('includeRouteFiles')) {
    /**
     * Include all PHP files in a directory.
     *
     * @param string $folder
     */
    function includeRouteFiles(string $folder)
    {
        $rdi = new \RecursiveDirectoryIterator($folder);
        $it = new \RecursiveIteratorIterator($rdi);

        while ($it->valid()) {
            if (!$it->isDot() && $it->isFile() && $it->isReadable() && $it->current()->getExtension() === 'php') {
                include $it->key();
            }

            $it->next();
        }
    }
}

if (!defined('HTTP_OK')) define('HTTP_OK', 200);
if (!defined('HTTP_CREATED')) define('HTTP_CREATED', 201);
if (!defined('HTTP_NO_CONTENT')) define('HTTP_NO_CONTENT', 204);
if (!defined('HTTP_BAD_REQUEST')) define('HTTP_BAD_REQUEST', 400);
if (!defined('HTTP_UNAUTHORIZED')) define('HTTP_UNAUTHORIZED', 401);
if (!defined('HTTP_FORBIDDEN')) define('HTTP_FORBIDDEN', 403);
if (!defined('HTTP_NOT_FOUND')) define('HTTP_NOT_FOUND', 404);
if (!defined('HTTP_UNPROCESSABLE_ENTITY')) define('HTTP_UNPROCESSABLE_ENTITY', 422);
if (!defined('HTTP_INTERNAL_SERVER_ERROR')) define('HTTP_INTERNAL_SERVER_ERROR', 500);

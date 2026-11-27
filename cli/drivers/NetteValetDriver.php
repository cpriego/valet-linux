<?php

class NetteValetDriver extends ValetDriver
{
    /**
     * Determine if the driver serves the request.
     */
    public function serves($sitePath, $siteName, $uri)
    {
        return file_exists($sitePath . '/www/index.php')
            && file_exists($sitePath . '/www/.htaccess')
            && file_exists($sitePath . '/config/common.neon')
            && file_exists($sitePath . '/config/services.neon');
    }

    /**
     * Determine if the incoming request is for a static file.
     */
    public function isStaticFile($sitePath, $siteName, $uri)
    {
        if ($this->isActualFile($staticFilePath = $sitePath . '/www/' . $uri)) {
            return $staticFilePath;
        }

        return false;
    }

    /**
     * Get the fully resolved path to the application's front controller.
     */
    public function frontControllerPath($sitePath, $siteName, $uri)
    {
        $_SERVER['DOCUMENT_ROOT'] = $sitePath . '/www';
        $_SERVER['SCRIPT_FILENAME'] = $sitePath . '/www/index.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';

        return $sitePath . '/www/index.php';
    }
}

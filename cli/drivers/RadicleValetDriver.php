<?php

class RadicleValetDriver extends BasicValetDriver
{
    /**
     * Determine if the driver serves the request.
     */
    public function serves($sitePath, $siteName, $uri)
    {
        return file_exists($sitePath . '/public/content/mu-plugins/bedrock-autoloader.php') &&
               file_exists($sitePath . '/public/wp-config.php') &&
               file_exists($sitePath . '/bedrock/application.php');
    }

    /**
     * Determine if the incoming request is for a static file.
     *
     * @return string|false
     */
    public function isStaticFile($sitePath, $siteName, $uri)
    {
        $staticFilePath = $sitePath . '/public' . $uri;
        if ($this->isActualFile($staticFilePath)) {
            return $staticFilePath;
        }

        return false;
    }

    /**
     * Get the fully resolved path to the application's front controller.
     */
    public function frontControllerPath($sitePath, $siteName, $uri)
    {
        $_SERVER['PHP_SELF'] = $uri;
        if (strpos($uri, '/wp/') === 0) {
            return is_dir($sitePath . '/public' . $uri)
                            ? $sitePath . '/public' . $this->forceTrailingSlash($uri) . '/index.php'
                            : $sitePath . '/public' . $uri;
        }

        return $sitePath . '/public/index.php';
    }

    /**
     * Redirect to uri with trailing slash.
     *
     * @return string
     */
    private function forceTrailingSlash($uri)
    {
        if (substr($uri, -1 * strlen('/wp/wp-admin')) == '/wp/wp-admin') {
            header('Location: ' . $uri . '/');
            exit;
        }

        return $uri;
    }
}

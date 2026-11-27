<?php

class KirbyValetDriver extends ValetDriver
{
    /**
     * Determine if the driver serves the request.
     */
    public function serves($sitePath, $siteName, $uri)
    {
        return is_dir($sitePath . '/kirby');
    }

    /**
     * Determine if the incoming request is for a static file.
     */
    public function isStaticFile($sitePath, $siteName, $uri)
    {
        if ($this->isActualFile($staticFilePath = $sitePath . $uri)) {
            return $staticFilePath;
        } elseif ($this->isActualFile($staticFilePath = $sitePath . '/public' . $uri)) {
            return $staticFilePath;
        }

        return false;
    }

    /**
     * Get the fully resolved path to the application's front controller.
     */
    public function frontControllerPath($sitePath, $siteName, $uri)
    {
        $scriptName = '/index.php';

        if ($this->isActualFile($sitePath . '/index.php')) {
            $indexPath = $sitePath . '/index.php';
        }

        if ($isAboveWebroot = $this->isActualFile($sitePath . '/public/index.php')) {
            $indexPath = $sitePath . '/public/index.php';
        }

        if (preg_match('/^\/panel/', $uri) && $this->isActualFile($sitePath . '/panel/index.php')) {
            $scriptName = '/panel/index.php';
            $indexPath = $sitePath . '/panel/index.php';
        }

        // add this block
        if (preg_match('/^\/(?!(kirby|site|content)\/).+\.php$/', $uri)) {
            if (
                $this->isActualFile($sitePath . $uri) ||
                $isAboveWebroot && $this->isActualFile($sitePath . '/public' . $uri)
            ) {
                $scriptName = $uri;
                $indexPath = $sitePath . $scriptName;
            }
        }

        $sitePathPrefix = ($isAboveWebroot) ? $sitePath . '/public' : $sitePath;

        $_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'];
        $_SERVER['SCRIPT_NAME'] = $scriptName;
        $_SERVER['SCRIPT_FILENAME'] = $sitePathPrefix . $scriptName;

        return $indexPath;
    }
}

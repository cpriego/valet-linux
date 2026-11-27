<?php

/**
 * Define the user's "~/.valet" path.
 */

defined('VALET_HOME_PATH') or define('VALET_HOME_PATH', posix_getpwuid(fileowner(__FILE__))['dir'] . '/.valet');
defined('VALET_STATIC_PREFIX') or define('VALET_STATIC_PREFIX', '41c270e4-5535-4daa-b23e-c269744c2f45');

if (!function_exists('show_valet_404')) {
/**
 * Show the Valet 404 "Not Found" page.
 */
    function show_valet_404()
    {
        http_response_code(404);
        require __DIR__ . '/cli/templates/404.html';
        exit;
    }
}

if (!function_exists('valet_support_xip_io')) {
/**
 * @param $domain string Domain to filter
 *
 * @return string Filtered domain (without xip.io feature)
 */
    function valet_support_xip_io($domain)
    {
        if (substr($domain, -7) === '.xip.io') {
            // support only ip v4 for now
            $domainPart = explode('.', $domain);
            if (count($domainPart) > 6) {
                $domain = implode('.', array_reverse(array_slice(array_reverse($domainPart), 6)));
            }
        }

        if (strpos($domain, ':') !== false) {
            $domain = explode(':', $domain)[0];
        }

        return $domain;
    }
}

if (!function_exists('valet_path_to_slug')) {
/**
 * Convert absolute path to slug.
 *
 * @param string $path
 * @return string Slug version of last folder name
 */
    function valet_path_to_slug($path)
    {
        $replace = [
            '&lt;' => '', '&gt;' => '', '&#039;' => '', '&amp;' => '',
            '&quot;' => '', 'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'Ae',
            '&Auml;' => 'A', 'Å' => 'A', 'Ā' => 'A', 'Ą' => 'A', 'Ă' => 'A', 'Æ' => 'Ae',
            'Ç' => 'C', 'Ć' => 'C', 'Č' => 'C', 'Ĉ' => 'C', 'Ċ' => 'C', 'Ď' => 'D', 'Đ' => 'D',
            'Ð' => 'D', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ē' => 'E',
            'Ę' => 'E', 'Ě' => 'E', 'Ĕ' => 'E', 'Ė' => 'E', 'Ĝ' => 'G', 'Ğ' => 'G',
            'Ġ' => 'G', 'Ģ' => 'G', 'Ĥ' => 'H', 'Ħ' => 'H', 'Ì' => 'I', 'Í' => 'I',
            'Î' => 'I', 'Ï' => 'I', 'Ī' => 'I', 'Ĩ' => 'I', 'Ĭ' => 'I', 'Į' => 'I',
            'İ' => 'I', 'Ĳ' => 'IJ', 'Ĵ' => 'J', 'Ķ' => 'K', 'Ł' => 'K', 'Ľ' => 'K',
            'Ĺ' => 'K', 'Ļ' => 'K', 'Ŀ' => 'K', 'Ñ' => 'N', 'Ń' => 'N', 'Ň' => 'N',
            'Ņ' => 'N', 'Ŋ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O',
            'Ö' => 'Oe', '&Ouml;' => 'Oe', 'Ø' => 'O', 'Ō' => 'O', 'Ő' => 'O', 'Ŏ' => 'O',
            'Œ' => 'OE', 'Ŕ' => 'R', 'Ř' => 'R', 'Ŗ' => 'R', 'Ś' => 'S', 'Š' => 'S',
            'Ş' => 'S', 'Ŝ' => 'S', 'Ș' => 'S', 'Ť' => 'T', 'Ţ' => 'T', 'Ŧ' => 'T',
            'Ț' => 'T', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'Ue', 'Ū' => 'U',
            '&Uuml;' => 'Ue', 'Ů' => 'U', 'Ű' => 'U', 'Ŭ' => 'U', 'Ũ' => 'U', 'Ų' => 'U',
            'Ŵ' => 'W', 'Ý' => 'Y', 'Ŷ' => 'Y', 'Ÿ' => 'Y', 'Ź' => 'Z', 'Ž' => 'Z',
            'Ż' => 'Z', 'Þ' => 'T', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a',
            'ä' => 'ae', '&auml;' => 'ae', 'å' => 'a', 'ā' => 'a', 'ą' => 'a', 'ă' => 'a',
            'æ' => 'ae', 'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ĉ' => 'c', 'ċ' => 'c',
            'ď' => 'd', 'đ' => 'd', 'ð' => 'd', 'è' => 'e', 'é' => 'e', 'ê' => 'e',
            'ë' => 'e', 'ē' => 'e', 'ę' => 'e', 'ě' => 'e', 'ĕ' => 'e', 'ė' => 'e',
            'ƒ' => 'f', 'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g', 'ĥ' => 'h',
            'ħ' => 'h', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
            'ĩ' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i', 'ĳ' => 'ij', 'ĵ' => 'j',
            'ķ' => 'k', 'ĸ' => 'k', 'ł' => 'l', 'ľ' => 'l', 'ĺ' => 'l', 'ļ' => 'l',
            'ŀ' => 'l', 'ñ' => 'n', 'ń' => 'n', 'ň' => 'n', 'ņ' => 'n', 'ŉ' => 'n',
            'ŋ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'oe',
            '&ouml;' => 'oe', 'ø' => 'o', 'ō' => 'o', 'ő' => 'o', 'ŏ' => 'o', 'œ' => 'oe',
            'ŕ' => 'r', 'ř' => 'r', 'ŗ' => 'r', 'š' => 's', 'ù' => 'u', 'ú' => 'u',
            'û' => 'u', 'ü' => 'ue', 'ū' => 'u', '&uuml;' => 'ue', 'ů' => 'u', 'ű' => 'u',
            'ŭ' => 'u', 'ũ' => 'u', 'ų' => 'u', 'ŵ' => 'w', 'ý' => 'y', 'ÿ' => 'y',
            'ŷ' => 'y', 'ž' => 'z', 'ż' => 'z', 'ź' => 'z', 'þ' => 't', 'ß' => 'ss',
            'ſ' => 'ss', 'ый' => 'iy', 'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G',
            'Д' => 'D', 'Е' => 'E', 'Ё' => 'YO', 'Ж' => 'ZH', 'З' => 'Z', 'И' => 'I',
            'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N', 'О' => 'O',
            'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U', 'Ф' => 'F',
            'Х' => 'H', 'Ц' => 'C', 'Ч' => 'CH', 'Ш' => 'SH', 'Щ' => 'SCH', 'Ъ' => '',
            'Ы' => 'Y', 'Ь' => '', 'Э' => 'E', 'Ю' => 'YU', 'Я' => 'YA', 'а' => 'a',
            'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l',
            'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's',
            'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e',
            'ю' => 'yu', 'я' => 'ya',
        ];

        // make a human readable string
        $slug = strtr(basename($path), $replace);

        // replace non letter or digits by -
        $slug = preg_replace('~[^\\pL\d.]+~u', '-', $slug);

        // trim
        $slug = trim($slug, '-');

        // remove unwanted characters
        $slug = preg_replace('~[^-\w.]+~', '', $slug);

        return strtolower($slug);
    }
}
if (!function_exists('valet_default_site_path')) {
/*
 * @param array $config Valet configuration array
 *
 * @return string|null If set, default site path for uncaught urls
 * */
    function valet_default_site_path($config)
    {
        if (isset($config['default']) && is_string($config['default']) && is_dir($config['default'])) {
            return $config['default'];
        }

        return null;
    }
}

if (!function_exists('valet_host_is_ip_address')) {
/**
 * Determine whether the given host (from HTTP_HOST) is an IPv4 address.
 *
 * @param string $host
 * @return bool
 */
    function valet_host_is_ip_address($host)
    {
        return (bool) preg_match('/^([0-9]+\.){3}[0-9]+$/', explode(':', $host)[0]);
    }
}

if (!function_exists('valet_site_from_ip_address_uri')) {
/**
 * Return the Valet site when given the request URI of an address that uses IP address
 * local access.
 *
 * E.g. URL is 192.168.1.100/onramp.test/auth/login, $uri is onramp.test/auth/login and
 * $domain is "test", this returns onramp.test
 *
 * For use when accessing Valet sites across a local network.
 *
 * @param string $uri
 * @param string $domain
 * @return string|null
 */
    function valet_site_from_ip_address_uri($uri, $domain)
    {
        if (preg_match('/^[-.0-9a-zA-Z]+\.' . preg_quote($domain, '/') . '(?=\/|$)/', $uri, $matches)) {
            return $matches[0];
        }

        return null;
    }
}

if (!function_exists('valet_show_directory_listing')) {
/**
 * Show a listing of the files in the given site directory, and stop.
 *
 * @param string $valetSitePath
 * @param string $uri
 * @return void
 */
    function valet_show_directory_listing($valetSitePath, $uri)
    {
        $isRoot = ($uri == '/');
        $directory = $isRoot ? $valetSitePath : $valetSitePath . $uri;

        if (!file_exists($directory)) {
            show_valet_404();
        }

        // Sort directories at the top
        $paths = glob($directory . '/*');
        usort($paths, function ($a, $b) {
            return (is_dir($a) == is_dir($b)) ? strnatcasecmp($a, $b) : (is_dir($a) ? -1 : 1);
        });

        $e = function ($value) {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        };

        echo '<h1>Index of ' . $e($uri) . '</h1>';
        echo '<hr>';
        echo implode('<br>' . PHP_EOL, array_map(function ($path) use ($uri, $isRoot, $e) {
            $file = basename($path);

            return $isRoot
                ? "<a href='/" . $e(rawurlencode($file)) . "'>/" . $e($file) . '</a>'
                : "<a href='" . $e(rtrim($uri, '/') . '/' . rawurlencode($file)) . "'>" . $e(rtrim($uri, '/') . '/' . $file) . '</a>';
        }, $paths));

        exit;
    }
}

/**
 * Load the Valet configuration.
 */
$valetConfig = json_decode(
    file_get_contents(VALET_HOME_PATH . '/config.json'), true
);

/**
 * If the HTTP_HOST is an IP address, check the start of the REQUEST_URI for a valid
 * hostname, extract and use it as the effective HTTP_HOST in place of the IP. It
 * enables the use of Valet in a local network.
 */
if (valet_host_is_ip_address($_SERVER['HTTP_HOST'])) {
    $uriForIpAddressExtraction = ltrim($_SERVER['REQUEST_URI'], '/');

    if ($host = valet_site_from_ip_address_uri($uriForIpAddressExtraction, $valetConfig['domain'])) {
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['REQUEST_URI'] = substr($uriForIpAddressExtraction, strlen($host));
    }
}

/**
 * Parse the URI and site / host for the incoming request.
 */
$uri = rawurldecode(
    explode("?", $_SERVER['REQUEST_URI'])[0]
);

// Reject URIs with ".." segments so drivers can't build paths outside the site directory.
if (in_array('..', explode('/', $uri), true)) {
    show_valet_404();
}

$siteName = basename(
// Filter host to support xip.io feature
    valet_support_xip_io(explode(':', strtolower($_SERVER['HTTP_HOST']))[0]),
    '.' . $valetConfig['domain']
);

if (strpos($siteName, 'www.') === 0) {
    $siteName = substr($siteName, 4);
}

/**
 * Determine the fully qualified path to the site.
 */
$valetSitePath = null;

foreach ($valetConfig['paths'] as $path) {
    $domain = ($pos = strrpos($siteName, '.')) !== false
    ? substr($siteName, $pos + 1)
    : null;

    foreach (glob($path . '/*', GLOB_ONLYDIR) as $dirPath) {
        $slug = valet_path_to_slug($dirPath);

        if ($slug == $siteName || $slug == $domain) {
            $valetSitePath = $dirPath;

            break 2;
        }
    }
}

if (is_null($valetSitePath) && is_null($valetSitePath = valet_default_site_path($valetConfig))) {
    show_valet_404();
}

/**
 * Find the appropriate Valet driver for the request.
 */
$valetDriver = null;

require __DIR__ . '/cli/drivers/require.php';

$valetDriver = ValetDriver::assign($valetSitePath, $siteName, $uri);

if (!$valetDriver) {
    show_valet_404();
}

/**
 * Overwrite the HTTP host for Ngrok.
 */
if (isset($_SERVER['HTTP_X_ORIGINAL_HOST'])) {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_X_ORIGINAL_HOST'];
}

/**
 * Attempt to load server environment variables.
 */
$valetDriver->loadServerEnvironmentVariables(
    $valetSitePath, $siteName
);

/**
 * Allow driver to mutate incoming URL.
 */
$uri = $valetDriver->mutateUri($uri);

/**
 * Determine if the incoming request is for a static file.
 */
$isPhpFile = pathinfo($uri, PATHINFO_EXTENSION) === 'php';

if ($uri !== '/' && !$isPhpFile && $staticFilePath = $valetDriver->isStaticFile($valetSitePath, $siteName, $uri)) {
    $valetDriver->serveStaticFile($staticFilePath, $valetSitePath, $siteName, $uri);

    return;
}

/**
 * Attempt to dispatch to a front controller.
 */
$frontControllerPath = $valetDriver->frontControllerPath(
    $valetSitePath, $siteName, $uri
);

if (!$frontControllerPath) {
    if (isset($valetConfig['directory-listing']) && $valetConfig['directory-listing'] == 'on') {
        valet_show_directory_listing($valetSitePath, $uri);
    }

    show_valet_404();
}

/**
 * Unset our variables to avoid leakage into the user's application.
 */
unset(
    $valetConfig,
    $uriForIpAddressExtraction,
    $host,
    $uri,
    $siteName,
    $valetSitePath,
    $valetDriver,
    $isPhpFile,
    $staticFilePath,
    $path,
    $domain,
    $dirPath,
    $slug,
    $pos
);

chdir(dirname($frontControllerPath));

require $frontControllerPath;

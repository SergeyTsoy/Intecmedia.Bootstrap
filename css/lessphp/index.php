<?php
/**
 * Online less compiler
 * 
 * Simple example:
 * <link rel="stylesheet/less" href="/css/lessphp/index.php?/css/style.less" />
 *
 * Apache mod_rewrite example:
 * RewriteEngine On
 * RewriteCond %{REQUEST_FILENAME} \.less$ [NC] 
 * RewriteCond %{REQUEST_FILENAME} -f
 * RewriteRule ^(.+)$ lessphp/index.php?%{REQUEST_URI}?%{QUERY_STRING} [L]
 *
 * Apache mod_action example:
 * Action compile-less /css/lessphp/index.php
 * AddHandler compile-less .less
 *
 *
 * @copyright 2014 IntecMedia (http://www.intecmedia.ru)
 * @author Dmitry Pyatkov(aka dkrnl) <dkrnl@yandex.ru>
 */

// handle errors
error_reporting(E_ALL);
ini_set("display_errors", true);
set_error_handler(function ($code, $message, $file, $line) {
    if (0 != error_reporting()) {
        throw new ErrorException($message, 0, $code, $file, $line);
    }
});
@ini_set("date.timezone", "UTC");
@ini_set("mbstring.internal_encoding", "ascii");
header("Content-Type: text/css; charset=UTF-8");
header("Cache-Control: must-revalidate");

// input file
$input = "";
$docroot = realpath($_SERVER["DOCUMENT_ROOT"]);
if (isset($_SERVER["PATH_TRANSLATED"]) && $_SERVER["PATH_TRANSLATED"]) {
    // from mod-action
    $input = realpath($_SERVER["PATH_TRANSLATED"]);
}  elseif (isset($_SERVER["QUERY_STRING"]) && $_SERVER["QUERY_STRING"]) {
    // from query string
    $input = parse_url("http://localhost/" . ltrim($_SERVER["QUERY_STRING"], "/"));
    $input = (is_array($input) && isset($input["path"]) ? realpath($docroot . $input["path"]) : "");
}
// win32 
if (DIRECTORY_SEPARATOR != "/") {
    $input = str_replace(DIRECTORY_SEPARATOR, "/", $input);
    $docroot = str_replace(DIRECTORY_SEPARATOR, "/", $docroot);
}
// gzip encoding
ob_start();
@ini_set("zlib.output_compression", true); 
@ini_set("zlib.output_compression_level", 9);

try {
    // security check
    if (!$input || strpos($input, $docroot) !== 0) {
        throw new Exception("Input less-file required", 403);
    }
    $ext = strtolower(pathinfo($input, PATHINFO_EXTENSION));
    if (!($ext == "css" || $ext == "less")) {
        throw new Exception("Input less-file required", 403);
    }
    // file not found
    if (!is_file($input)) {
        throw new Exception("File '$input' not exists", 404);
    }

    $options = array(
        "sourceMap" => true, 
        "compress" => false,
        "outputSourceFiles" => true,
        "sourceMapBasepath" => dirname($input),
        "sourceMapRootpath" => substr(dirname($input), strlen($docroot)),
        "cache_dir" => __DIR__ . DIRECTORY_SEPARATOR . "cache",
        "cache_method" => false,
    );

    $options["sourceMapWriteTo"] = $options["cache_dir"] . DIRECTORY_SEPARATOR .  "lessphp_" . md5($input). ".map";
    $options["sourceMapURL"] = substr($options["sourceMapWriteTo"], strlen($docroot));
    if (DIRECTORY_SEPARATOR != "/") {
        $options["sourceMapURL"] = str_replace(DIRECTORY_SEPARATOR, "/", $options["sourceMapURL"]);
    }

    include_once "lib" . DIRECTORY_SEPARATOR. "Less.php";
    include_once "lib" . DIRECTORY_SEPARATOR. "Cache.php";
    $output = Less_Cache::Get(array($input => $options["sourceMapRootpath"]), $options);
    $css = file_get_contents($options["cache_dir"] . DIRECTORY_SEPARATOR . $output);
    $mtime = filectime($options["cache_dir"] . DIRECTORY_SEPARATOR . $output);
    if (isset($_SERVER["HTTP_IF_MODIFIED_SINCE"]) && strtotime($_SERVER["HTTP_IF_MODIFIED_SINCE"]) >= $mtime) {
        header("HTTP/1.0 304 Not Modified");
    } else {
        header("Last-Modified: " . gmdate("D, d M Y H:i:s", $mtime) . " GMT");
        echo "/*! Generated by LESS css compiler: " . gmdate("r", $mtime) . " | $output */\n", $css;
    }
    return;
} catch (Exception $exception) {
    if (@ini_get("zlib.output_compression")) {
        @ini_set("zlib.output_compression", false); 
        @ini_set("zlib.output_compression_level", 0);
    }
    // handle exception
    $statusCode = $exception->getCode();
    if ($statusCode != 403 && $statusCode != 404 && $statusCode != 500) {
        $statusCode = 200;
    }
    $error = "LESS compile error:\n" . $exception->getMessage() . "\nat " . $exception->getFile() . ":" . $exception->getLine();
    if (DIRECTORY_SEPARATOR != "/") {
        $error = str_replace(DIRECTORY_SEPARATOR, "/", $error);
    }
    $error = strtr($error, array($docroot => "%DOCUMENT_ROOT%"));
    header("Content-Type: text/css; charset=UTF-8", true, $statusCode);
    // wildfire-error
    header("X-Wf-Protocol-1: http://meta.wildfirehq.org/Protocol/JsonStream/0.2");
    header("X-Wf-1-Plugin-1: http://meta.firephp.org/Wildfire/Plugin/FirePHP/Library-FirePHPCore/0.3");
    header("X-Wf-1-Structure-1: http://meta.firephp.org/Wildfire/Structure/FirePHP/FirebugConsole/0.1");
    $wildfire = json_encode(array(array("Type" => "EXCEPTION", "File" => "*", "Line" => "*"), $error));
    header("X-Wf-1-1-1-1: " . strlen($wildfire) . "|{$wildfire}|");
    // css-error
    $content = preg_replace_callback("/[^a-zA-Z0-9]/Su", function ($matches) {
        $char = $matches[0];
        if (!isset($char[1])) {
            $hex = ltrim(strtoupper(bin2hex($char)), "0");
            return "\\" . (strlen($hex) ? $hex : "0") . " ";
        }
        $char = mb_convert_encoding($char, "UTF-16BE", "UTF-8");
        return "\\" . ltrim(strtoupper(bin2hex($char)), "0") . " ";
    }, $error);
    echo "/*\n$error\n*/\n";
    echo "body:before {\n";
    echo "    content:'{$content}';\n";
    echo "    position:absolute;\n";
    echo "    top:5px;\n";
    echo "    left:5px;\n";
    echo "    right:5px;\n";
    echo "    z-index:9999;\n";
    echo "    border:1px solid;\n";
    echo "    background:snow;\n";
    echo "    border-radius:5px;\n";
    echo "    color:red;\n";
    echo "    padding:15px;\n";
    echo "};\n";
}
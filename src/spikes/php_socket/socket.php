<?php

namespace zenmodedaemon;

require __DIR__ . '/helpers/request.php';
require __DIR__ . '/helpers/config.php';
require "vendor/autoload.php";

use PHPHtmlParser\Dom;

define('ROOT_DIR', __DIR__);

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();

$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

if (socket_bind($sock, '127.0.0.1', 777) === false) {
    die('cannot bind' . PHP_EOL);
}

if (socket_listen($sock, 5) === false) {
    die('cannot listen' . PHP_EOL);
}

do {
    if (($msgsock = socket_accept($sock)) === false) {
        echo "socket_accept() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
    }

    $flattenGet = [];

    $buf = '';
    while (true) {
        $tmp = socket_read($msgsock, 4012, PHP_NORMAL_READ);
        if (strlen(trim($tmp)) > 0) {
            $buf .= $tmp;
        } else {
            break;
        }
    }
    if (empty(trim($buf))) {
        socket_close($msgsock);
        continue;
    }
    if ($buf === 'GET /favicon.ico HTTP/1.1') {
        // ignore this request
        socket_close($msgsock);
        continue;
    } else {
        try {
            $flattenGet = helpers\request\getParams($buf);
        } catch (\Exception $ex) {
            echo $ex->getMessage() . PHP_EOL;
            socket_close($msgsock);
            continue;
        }
    }
    echo $buf . PHP_EOL;

    if (isset($flattenGet['__site'])) {
        /** @var string */
        $configFilename = helpers\config\getConfigFilename($flattenGet['__site']);
        /** @var object */
        $json = helpers\config\getConfigJson($configFilename);
        /** @var string */
        $url = 'https://' . $flattenGet['__site'];
        /** @var string */
        $site = file_get_contents($url);
        if ($json->type === 'search_engine') {
            if (isset($json->form_name)) {
                $formName = $json->form_name;
                $dom = new Dom();
                $dom->loadFromUrl($url);
                $form = $dom->find("form[name=$formName]");
            } elseif (isset($json->form_id)) {
                $formId = $json->form_id;
                $dom = new Dom();
                $dom->loadFromUrl($url);
                $form = $dom->find("#$formId");
            }
            $content = "<!DOCTYPE html><html><head></head><body>";
            $content .= "<h1>{$json->name}</h1>";
            $content .= (string) $form;
            $content .= "</body></html>";
        } else {
            $content = $site;
        }
        $length = strlen($content);
        $header = "HTTP/1.1 200\r\nContent-Type: text/html\r\nContent-Length:$length\r\n\r\n";
        $msg = $header . $content;
    } else {
        $content = "<!DOCTYPE html><html><head></head><body><h1>No __site defined in URL</h1></body></html>";
        $length = strlen($content);
        $header = "HTTP/1.1 200\r\nContent-Type: text/html\r\nContent-Length:$length\r\n\r\n";
        $msg = $header . $content;
    }

    socket_write($msgsock, $msg, strlen($msg));
    socket_close($msgsock);
    continue;
} while (true);

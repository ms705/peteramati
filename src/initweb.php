<?php
// initweb.php -- HotCRP initialization for web scripts
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

require_once("init.php");
global $Conf, $Me, $Opt, $Qreq;

// Check method: GET/HEAD/POST only, except OPTIONS is allowed for API calls
if ($_SERVER["REQUEST_METHOD"] !== "GET"
    && $_SERVER["REQUEST_METHOD"] !== "HEAD"
    && $_SERVER["REQUEST_METHOD"] !== "POST"
    && (Navigation::page() !== "api"
        || $_SERVER["REQUEST_METHOD"] !== "OPTIONS")) {
    header("HTTP/1.0 405 Method Not Allowed");
    exit;
}

// Collect $Qreq
$Qreq = Qrequest::make_global();

// Check for redirect to https
if ($Opt["redirectToHttps"] ?? false) {
    Navigation::redirect_http_to_https($Opt["allowLocalHttp"] ?? false);
}

// Check and fix zlib output compression
global $zlib_output_compression;
$zlib_output_compression = false;
if (function_exists("zlib_get_coding_type"))
    $zlib_output_compression = zlib_get_coding_type();
if ($zlib_output_compression) {
    header("Content-Encoding: $zlib_output_compression");
    header("Vary: Accept-Encoding", false);
}

// Mark as already expired to discourage caching, but allow the browser
// to cache for history buttons
header("Cache-Control: max-age=0,must-revalidate,private");

// Don't set up a session if $Me is false
if ($Me === false)
    return;


// Initialize user
function initialize_user() {
    global $Conf, $Me, $Qreq;
    $conf = Conf::$main;

    // set up session
    if (($sh = $conf->opt["sessionHandler"] ?? null)) {
        /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName, PhanNonClassMethodCall */
        $conf->_session_handler = new $sh($conf);
        session_set_save_handler($conf->_session_handler, true);
    }
    set_session_name($conf);
    $sn = session_name();

    // check CSRF token, using old value of session ID
    if ($Qreq->post && $sn) {
        if (isset($_COOKIE[$sn])) {
            $sid = $_COOKIE[$sn];
            $l = strlen($Qreq->post);
            if ($l >= 8 && $Qreq->post === substr($sid, strlen($sid) > 16 ? 8 : 0, $l))
                $Qreq->approve_token();
        } else if ($Qreq->post === "<empty-session>"
                   || $Qreq->post === ".empty") {
            $Qreq->approve_token();
        }
    }
    ensure_session(ENSURE_SESSION_ALLOW_EMPTY);

    // upgrade session format
    if (!isset($_SESSION["u"]) && isset($_SESSION["trueuser"])) {
        $_SESSION["u"] = $_SESSION["trueuser"]->email;
    }

    // determine user
    $nav = Navigation::get();
    $trueemail = isset($_SESSION["u"]) ? $_SESSION["u"] : null;

    // look up and activate user
    $Me = null;
    if ($trueemail) {
        $Me = $conf->user_by_email($trueemail);
    }
    if (!$Me) {
        $Me = new Contact($trueemail ? (object) ["email" => $trueemail] : null);
    }
    $Me = $Me->activate($Qreq, true);

    // redirect if disabled
    if ($Me->is_disabled()) {
        if ($nav->page === "api") {
            json_exit(["ok" => false, "error" => "Your account is disabled."]);
        } else if ($nav->page !== "index" && $nav->page !== "resetpassword") {
            Navigation::redirect_site($conf->hoturl_site_relative_raw("index"));
        }
    }

    // if bounced through login, add post data
    if (isset($_SESSION["login_bounce"][4])
        && $_SESSION["login_bounce"][4] <= Conf::$now)
        unset($_SESSION["login_bounce"]);

    if (!$Me->is_empty()
        && isset($_SESSION["login_bounce"])
        && !isset($_SESSION["testsession"])) {
        $lb = $_SESSION["login_bounce"];
        if ($lb[0] == $conf->dsn
            && $lb[2] !== "index"
            && $lb[2] == Navigation::page()) {
            foreach ($lb[3] as $k => $v)
                if (!isset($Qreq[$k]))
                    $Qreq[$k] = $v;
            $Qreq->set_annex("after_login", true);
        }
        unset($_SESSION["login_bounce"]);
    }

    // set $_SESSION["addrs"]
    if ($_SERVER["REMOTE_ADDR"]
        && (!$Me->is_empty()
            || isset($_SESSION["addrs"]))
        && (!isset($_SESSION["addrs"])
            || !is_array($_SESSION["addrs"])
            || $_SESSION["addrs"][0] !== $_SERVER["REMOTE_ADDR"])) {
        $as = [$_SERVER["REMOTE_ADDR"]];
        if (isset($_SESSION["addrs"]) && is_array($_SESSION["addrs"])) {
            foreach ($_SESSION["addrs"] as $a)
                if ($a !== $_SERVER["REMOTE_ADDR"] && count($as) < 5)
                    $as[] = $a;
        }
        $_SESSION["addrs"] = $as;
    }
}

initialize_user();


// Extract an error that we redirected through
if (isset($_SESSION["redirect_error"])) {
    global $Error;
    $Error = $_SESSION["redirect_error"];
    unset($_SESSION["redirect_error"]);
}

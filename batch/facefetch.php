<?php
// facefetch.php -- Peteramati script for fetching & storing faces
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

$ConfSitePATH = preg_replace('/\/batch\/[^\/]+/', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "hn:l:aV", array("help", "name:", "limit:", "all",
     "college", "extension", "tf", "verbose"));
$verbose = isset($arg["V"]) || isset($arg["verbose"]);
if (isset($arg["h"]) || isset($arg["help"]) || count($arg["_"]) > 1) {
    fwrite(STDOUT, "Usage: php batch/facefetch.php [-l LIMIT] [FETCHSCRIPT]\n");
    exit(0);
}

$facefetch_urlpattern = get($Opt, "facefetch_urlpattern");
if ($Conf->config->_facefetch_urlpattern ?? null) {
    $facefetch_urlpattern = $Conf->config->_facefetch_urlpattern;
}
if (!$facefetch_urlpattern) {
    fwrite(STDERR, 'Need `_facefetch_urlpattern` configuration option.' . "\n");
    exit(1);
}
if (is_string($facefetch_urlpattern)) {
    $facefetch_urlpattern = [$facefetch_urlpattern];
}

$fetchscript = $Conf->config->_facefetch_script ?? null;
if (count($arg["_"])) {
    $fetchscript = $arg["_"][0];
}
if (!$fetchscript) {
    fwrite(STDERR, "Need `_facefetch_script` configuration option or argument.\n");
    exit(1);
}

if (!isset($arg["limit"]))
    $arg["limit"] = get($arg, "l");
$limit = (int) $arg["limit"];

$where = [];
if (!isset($arg["all"]) && !isset($arg["a"])) {
    $where[] = "contactImageId is null";
}
if (isset($arg["college"])) {
    $where[] = "college=1";
}
if (isset($arg["extension"])) {
    $where[] = "extension=1";
}
if (isset($arg["tf"])) {
    $where[] = "roles!=0";
}
if (empty($where)) {
    $where[] = "true";
}
$result = Dbl::qe("select contactId, email, firstName, lastName, huid from ContactInfo where " . join(" and ", $where));
$rows = array();
while (($row = $result->fetch_object())) {
    $rows[] = $row;
}
if ($limit) {
    shuffle($rows);
}
Dbl::free($result);


function one_facefetch($row, $url) {
    global $fetchscript, $verbose;

    if (strpos($url, '${EMAIL}') !== false) {
        if ($row->email === null) {
            return false;
        }
        $url = str_replace('${EMAIL}', urlencode($row->email), $url);
    }
    if (strpos($url, '${ID}') !== false) {
        if ($row->huid === null) {
            return false;
        }
        $url = str_replace('${ID}', urlencode($row->huid), $url);
    }
    if (strpos($url, '${NAMESEARCH}') !== false) {
        if ((string) $row->lastName === "")
            return false;
        $name = strtolower($row->lastName);
        if ((string) $row->firstName !== "") {
            $name .= " " . strtolower(preg_replace('/(^\S+)\s+.*/', '$1', $row->firstName));
        }
        $url = str_replace('${NAMESEARCH}', urlencode($name), $url);
    }
    if ($verbose)
        error_log("    $url\n");

    $handle = popen($fetchscript . " " . escapeshellarg($url), "r");
    $data = stream_get_contents($handle);
    $status = pclose($handle);

    $content_type = "";
    if ($data && ($nl = strpos($data, "\n")) !== false) {
        $content_type = substr($data, 0, $nl);
        $data = substr($data, $nl + 1);
    }

    $result = false;
    if (pcntl_wifexitedwith($status)
        && preg_match(',\Aimage/,', $content_type)) {
        $sresult = Dbl::fetch_first_object(Dbl::qe("select ContactImage.* from ContactImage join ContactInfo using (contactImageId) where ContactInfo.contactId=?", $row->contactId));
        if ($sresult
            && $sresult->mimetype === $content_type
            && $sresult->data === $data) {
            $result = ["unchanged", $content_type, strlen($data)];
        } else {
            $iresult = Dbl::qe("insert into ContactImage set contactId=?, mimetype=?, data=?", $row->contactId, $content_type, $data);
            if ($iresult) {
                Dbl::qe("update ContactInfo set contactImageId=? where contactId=?", $iresult->insert_id, $row->contactId);
                $result = [true, $content_type, strlen($data)];
            }
        }
    }
    return $result;
}

$n = $nworked = 0;
foreach ($rows as $row) {
    $ok = false;
    fwrite(STDOUT, "$row->email ");

    foreach ($facefetch_urlpattern as $url) {
        if (($ok = one_facefetch($row, $url)))
            break;
    }

    if ($ok && $ok[0] === "unchanged") {
        fwrite(STDERR, "{$ok[2]}B {$ok[1]} (unchanged)\n");
    } else if ($ok) {
        fwrite(STDERR, "{$ok[2]}B {$ok[1]}\n");
    } else {
        fwrite(STDERR, "failed\n");
    }

    $nworked += $ok ? 1 : 0;
    ++$n;
    if ($n == $limit) {
        break;
    }
}

exit($nworked ? 0 : 1);

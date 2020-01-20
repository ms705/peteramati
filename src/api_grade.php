<?php
// api/api_grade.php -- Peteramati API for grading
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class API_Grade {
    static private function parse_full_grades($pset, $x, &$errf) {
        if (is_string($x)) {
            $x = json_decode($x, true);
            if (!is_array($x)) {
                $errf["!invalid"] = true;
                return [];
            }
        } else if (is_object($x)) {
            $x = (array) $x;
        }
        if (is_array($x)) {
            foreach ($x as $k => &$v) {
                if (($ge = $pset->gradelike_by_key($k))) {
                    $v = $ge->parse_value($v);
                    if ($v === false && !isset($errf[$k]))
                        $errf[$k] = $ge->parse_value_error();
                }
            }
            return $x;
        } else {
            return [];
        }
    }

    static private function apply_grades($info, $g, $ag, $og, &$errf) {
        $v = [];
        foreach ($info->pset->grades() as $ge) {
            $k = $ge->key;
            if (array_key_exists($k, $og)) {
                $curgv = $info->current_grade_entry($k);
                if ($ge->value_differs($curgv, $og[$k])
                    && (!array_key_exists($k, $g)
                        || $ge->value_differs($curgv, $g[$k]))) {
                    $errf[$k] = true;
                }
            }
            if (array_key_exists($k, $g)) {
                $gv = $g[$k];
                if ($gv === null
                    && ($notes = $info->current_info())
                    && isset($notes->autogrades->$k)) {
                    $gv = false;
                }
                $v["grades"][$k] = $gv;
            }
            if (array_key_exists($k, $ag)) {
                $v["autogrades"][$k] = $ag[$k];
            }
        }
        if (array_key_exists("late_hours", $g)) { // XXX separate permission check?
            $curlhd = $info->late_hours_data() ? : (object) [];
            $lh = get($curlhd, "hours", 0);
            $alh = get($curlhd, "autohours", $lh);
            if ($og["late_hours"] !== null
                && abs($og["late_hours"] - $lh) >= 0.0001) {
                $errf["late_hours"] = true;
            } else if ($g["late_hours"] === null
                       || abs($g["late_hours"] - $alh) < 0.0001) {
                $v["late_hours"] = null;
            } else {
                $v["late_hours"] = $g["late_hours"];
            }
        }
        return $v;
    }

    static function grade(Contact $user, Qrequest $qreq, APIData $api) {
        $info = PsetView::make($api->pset, $api->user, $user);
        if (($err = $api->prepare_grading_commit($info))) {
            return $err;
        }
        // XXX match commit with grading commit
        if ($qreq->method() === "POST") {
            if (!check_post($qreq)) {
                return ["ok" => false, "error" => "Missing credentials."];
            } else if ($info->is_handout_commit()) {
                return ["ok" => false, "error" => "Cannot set grades on handout commit."];
            } else if (!$info->can_edit_grades()) {
                return ["ok" => false, "error" => "Permission error."];
            } else if (!$info->pset->gitless_grades
                       && $qreq->commit_is_grade
                       && $info->grading_hash() !== $info->commit_hash()) {
                return ["ok" => false, "error" => "The grading commit has changed."];
            }

            // parse grade elements
            $qreq->allow_a("grades", "autogrades", "oldgrades");
            $errf = [];
            $g = self::parse_full_grades($info->pset, $qreq->grades, $errf);
            $ag = self::parse_full_grades($info->pset, $qreq->autogrades, $errf);
            $og = self::parse_full_grades($info->pset, $qreq->oldgrades, $errf);
            if (!empty($errf)) {
                if (isset($errf["!invalid"])) {
                    return ["ok" => false, "error" => "Invalid request."];
                } else {
                    reset($errf);
                    return ["ok" => false, "error" => (count($errf) === 1 ? current($errf) : "Invalid grades."), "errf" => $errf];
                }
            }

            // assign grades
            $v = self::apply_grades($info, $g, $ag, $og, $errf);
            if (!empty($errf)) {
                $j = (array) $info->grade_json();
                $j["ok"] = false;
                $j["error"] = "Grade edit conflict, your update was ignored.";
                return $j;
            } else if (!empty($v)) {
                $info->update_grade_info($v);
            }
        } else if (!$info->can_view_grades()) {
            return ["ok" => false, "error" => "Permission error."];
        }
        $j = (array) $info->grade_json();
        $j["ok"] = true;
        return $j;
    }

    static function multigrade(Contact $viewer, Qrequest $qreq, APIData $api) {
        if (!isset($qreq->us)
            || !($ugs = json_decode($qreq->us))
            || !is_object($ugs)) {
            return ["ok" => false, "error" => "Missing parameter."];
        }
        $ugs = (array) $ugs;
        $ispost = $qreq->method() === "POST";
        if ($ispost && !$qreq->post_ok()) {
            return ["ok" => false, "error" => "Missing credentials."];
        }

        $infos = [];
        $errno = 0;
        foreach (array_keys($ugs) as $uid) {
            if (!$uid
                || (!is_int($uid) && !ctype_digit($uid))
                || !($u = $viewer->conf->user_by_id($uid))
                || ($u->contactId != $viewer->contactId
                    && !$viewer->isPC)) {
                return ["ok" => false, "error" => "Permission error."];
            }
            $u->set_anonymous($api->pset->anonymous);

            $info = PsetView::make($api->pset, $u, $viewer);
            // XXX extract the following into a function
            // XXX branch nonsense
            if (!$api->pset->gitless) {
                if (!$info->repo) {
                    $errno = max($errno, 5);
                    continue;
                }
                $commit = null;
                if (($hash = get($ugs[$uid], "commit"))) {
                    $commit = $info->pset->handout_commits($hash)
                        ? : $info->repo->connected_commit($hash, $info->pset, $info->branch);
                }
                if (!$commit) {
                    $errno = max($errno, $hash ? 3 : 4);
                    continue;
                }
                $info->force_set_hash($commit->hash);
                if (get($ugs[$uid], "commit_is_grade")
                    && $commit->hash !== $info->grading_hash()) {
                    $errno = max($errno, 2);
                }
            }
            if (!$info->can_view_grades()
                || ($ispost && !$info->can_edit_grades()))
                return ["ok" => false, "error" => "Permission error."];
            else if ($ispost && $info->is_handout_commit())
                $errno = max($errno, 1);
            $infos[$u->contactId] = $info;
        }

        if ($errno === 5) {
            return ["ok" => false, "error" => "Missing repository."];
        } else if ($errno === 4) {
            return ["ok" => false, "error" => "Disconnected commit."];
        } else if ($errno === 3) {
            return ["ok" => false, "error" => "Missing commit."];
        } else if ($errno === 2) {
            return ["ok" => false, "error" => "The grading commit has changed."];
        } else if ($errno === 1) {
            return ["ok" => false, "error" => "Cannot set grades on handout commit."];
        }

        // XXX match commit with grading commit
        if ($ispost) {
            // parse grade elements
            $g = $ag = $og = $errf = [];
            foreach ($ugs as $uid => $gx) {
                $g[$uid] = self::parse_full_grades($api->pset, get($gx, "grades"), $errf);
                $ag[$uid] = self::parse_full_grades($api->pset, get($gx, "autogrades"), $errf);
                $og[$uid] = self::parse_full_grades($api->pset, get($gx, "oldgrades"), $errf);
            }
            if (!empty($errf)) {
                reset($errf);
                if (isset($errf["!invalid"]))
                    return ["ok" => false, "error" => "Invalid request."];
                else
                    return ["ok" => false, "error" => (count($errf) === 1 ? current($errf) : "Invalid grades."), "errf" => $errf];
            }

            // assign grades
            $v = [];
            foreach ($ugs as $uid => $gx) {
                $v[$uid] = self::apply_grades($infos[$uid], $g[$uid], $ag[$uid], $og[$uid], $errf);
            }
            if (!empty($errf)) {
                $j = (array) $info->grade_json();
                $j["ok"] = false;
                $j["error"] = "Grade edit conflict, your update was ignored.";
                return $j;
            } else {
                foreach ($ugs as $uid => $gx) {
                    if (!empty($v[$uid]))
                        $infos[$uid]->update_grade_info($v[$uid]);
                }
            }
        }
        $j = (array) $api->pset->gradeentry_json(true);
        $j["ok"] = true;
        $j["us"] = [];
        foreach ($infos as $uid => $info) {
            $j["us"][$uid] = $infos[$uid]->grade_json(true);
        }
        return $j;
    }

    static private function apply_linenote($info, $user, $apply, &$lnotes) {
        if (!isset($apply->file)
            || !isset($apply->line)
            || !$apply->file
            || !$apply->line
            || !preg_match('/\A[ab]\d+\z/', $apply->line)) {
            return ["ok" => false, "error" => "Invalid request."];
        } else if (!$info->can_edit_line_note($apply->file, $apply->line)) {
            return ["ok" => false, "error" => "Permission error."];
        } else if ($info->is_handout_commit()) {
            return ["ok" => false, "error" => "This is a handout commit."];
        }

        $note = $info->current_line_note($apply->file, $apply->line);
        if (isset($apply->oldversion)
            && $apply->oldversion != +$note->version) {
            return ["ok" => false, "error" => "Edit conflict, you need to reload."];
        }

        if (array_search($user->contactId, $note->users) === false) {
            $note->users[] = $user->contactId;
        }
        $note->iscomment = isset($apply->iscomment) && $apply->iscomment;
        $note->note = rtrim(cleannl(isset($apply->note) ? $apply->note : ""));
        $note->version = intval($note->version) + 1;
        if (isset($apply->format) && ctype_digit($apply->format)) {
            $note->format = intval($apply->format);
        }

        if (!isset($lnotes[$apply->file])) {
            $lnotes[$apply->file] = [];
        }
        $lnotes[$apply->file][$apply->line] = $note;
        return false;
    }

    static function linenote(Contact $user, Qrequest $qreq, APIData $api) {
        $info = PsetView::make($api->pset, $api->user, $user);
        $info->set_commit($api->commit);
        if ($qreq->line && ctype_digit($qreq->line)) {
            $qreq->line = "b" . $qreq->line;
        }
        $lnotes = [];

        if ($qreq->method() === "POST") {
            if (($ans = self::apply_linenote($info, $user, $qreq, $lnotes))) {
                return $ans;
            }
            $info->update_current_info(["linenotes" => $lnotes]);
        }

        if (!$user->can_view_comments($api->pset, $info)) {
            return ["ok" => false, "error" => "Permission error."];
        }
        $can_view_grades = $info->can_view_grades();
        $can_view_note_authors = $info->can_view_note_authors();
        $notes = [];
        foreach ((array) $info->current_info("linenotes") as $file => $linemap) {
            if (($qreq->file && $file !== $qreq->file)
                || ($lnotes && !isset($lnotes[$file]))) {
                continue;
            }
            $filenotes = [];
            foreach ((array) $linemap as $lineid => $note) {
                $note = LineNote::make_json($file, $lineid, $note);
                if (($can_view_grades || $note->iscomment)
                    && (!$qreq->line || $qreq->line === $lineid)
                    && (!$lnotes || isset($lnotes[$file][$lineid]))) {
                    $filenotes[$lineid] = $note->render_json($can_view_note_authors);
                }
            }
            if (!empty($filenotes)) {
                $notes[$file] = $filenotes;
            }
        }
        return ["ok" => true, "linenotes" => $notes];
    }
}

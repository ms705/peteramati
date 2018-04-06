<?php

class API_Repo {
    static function latestcommit(Contact $user, Qrequest $qreq, APIData $api) {
        if (!$api->repo
            || !($c = $api->repo->latest_commit($api->pset, $api->branch)))
            return ["hash" => false];
        else if (!$user->can_view_repo_contents($api->repo, $api->branch))
            return ["hash" => false, "error" => "Unconfirmed repository."];
        else {
            $j = clone $c;
            unset($j->fromhead);
            $j->snaphash = $api->repo->snaphash;
            $j->snapcheckat = $api->repo->snapcheckat;
        }
        return $j;
    }

    static function blob(Contact $user, Qrequest $qreq, APIData $api) {
        if (!$user->can_view_repo_contents($api->repo, $api->branch))
            return ["ok" => false, "error" => "Permission error."];
        if (!$qreq->file
            || (isset($qreq->fromline) && !ctype_digit($qreq->fromline))
            || (isset($qreq->linecount) && !ctype_digit($qreq->linecount)))
            return ["ok" => false, "error" => "Invalid request."];
        $repo = $api->repo;
        if ($api->commit->from_handout())
            $repo = $api->pset->handout_repo($api->repo);
        $command = "git show {$api->hash}:" . escapeshellarg($qreq->file);
        if ($qreq->fromline && intval($qreq->fromline) > 1)
            $command .= " | tail -n +" . intval($qreq->fromline);
        if ($qreq->linecount)
            $command .= " | head -n " . intval($qreq->linecount);
        $x = $api->repo->gitrun($command, true);
        if (!$x->status && ($x->stdout !== "" || $x->stderr === "")) {
            $data = $x->stdout;
            if (is_valid_utf8($data))
                return ["ok" => true, "data" => $data];
            else
                return ["ok" => true, "data" => UnicodeHelper::utf8_replace_invalid($data), "invalid_utf8" => true];
        } else if (strpos($x->stderr, "does not exist") !== false)
            return ["ok" => false, "error" => "No such file."];
        else {
            error_log("$command: $x->stderr");
            return ["ok" => false, "error" => "Problem."];
        }
    }

    static function filediff(Contact $user, Qrequest $qreq, APIData $api) {
        if (!$user->can_view_repo_contents($api->repo, $api->branch))
            return ["ok" => false, "error" => "Permission error."];
        if (!$qreq->file)
            return ["ok" => false, "error" => "Invalid request."];



                $lnorder = $info->viewable_line_notes();
        $onlyfiles = $qreq->files;
        $diff = $info->repo->diff($pset, null, $info->grading_hash(), array("needfiles" => $lnorder->note_files(), "onlyfiles" => $onlyfiles));
        $info->expand_diff_for_grades($diff);
        if (count($onlyfiles) == 1
            && isset($diff[$onlyfiles[0]])
            && $qreq->lines
            && preg_match('/\A\s*(\d+)-(\d+)\s*\z/', $qreq->lines, $m))
            $diff[$onlyfiles[0]] = $diff[$onlyfiles[0]]->restrict_linea(intval($m[1]), intval($m[2]) + 1);

        foreach ($diff as $file => $dinfo) {
            $info->echo_file_diff($file, $dinfo, $lnorder, true,
                ["no_heading" => count($qreq->files) == 1]);
        }

        $want_grades = $pset->has_grade_landmark;




        $repo = $api->repo;
        if ($api->commit->from_handout())
            $repo = $api->pset->handout_repo($api->repo);
        $command = "git show {$api->hash}:" . escapeshellarg($qreq->file);
        if ($qreq->fromline && intval($qreq->fromline) > 1)
            $command .= " | tail -n +" . intval($qreq->fromline);
        if ($qreq->linecount)
            $command .= " | head -n " . intval($qreq->linecount);
        $x = $api->repo->gitrun($command, true);
        if (!$x->status && ($x->stdout !== "" || $x->stderr === "")) {
            $data = $x->stdout;
            if (is_valid_utf8($data))
                return ["ok" => true, "data" => $data];
            else
                return ["ok" => true, "data" => UnicodeHelper::utf8_replace_invalid($data), "invalid_utf8" => true];
        } else if (strpos($x->stderr, "does not exist") !== false)
            return ["ok" => false, "error" => "No such file."];
        else {
            error_log("$command: $x->stderr");
            return ["ok" => false, "error" => "Problem."];
        }
    }
}

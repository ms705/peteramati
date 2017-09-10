<?php

class API_Repo {
    static function latestcommit(Contact $user, Qrequest $qreq, APIData $api) {
        if (!$api->repo || !($c = $api->repo->latest_commit($api->pset, $api->branch)))
            return ["hash" => false];
        else if (!$user->can_view_repo_contents($api->repo))
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
        if (!$user->can_view_repo_contents($api->repo))
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
}

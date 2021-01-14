<?php
// run.php -- Peteramati runner page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
if ($Me->is_empty()) {
    $Me->escape();
}

function quit($err = null, $js = null) {
    json_exit(["ok" => false, "error" => htmlspecialchars($err), "error_text" => $err] + ($js ?? []));
}

class RunRequest {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var Contact */
    public $viewer;
    /** @var Qrequest */
    public $qreq;
    /** @var Pset */
    public $pset;
    /** @var RunnerConfig */
    public $runner;
    /** @var bool */
    public $is_ensure;

    function __construct(Contact $viewer, Qrequest $qreq) {
        $this->conf = $viewer->conf;
        $this->user = $this->viewer = $viewer;
        $this->qreq = $qreq;
        if ($qreq->u !== null
            && !($this->user = ContactView::prepare_user($qreq->u))) {
            json_exit(["ok" => false]);
        }
        assert($this->user === $this->viewer || $this->viewer->isPC);
        $this->pset = ContactView::find_pset_redirect($qreq->pset);

        foreach ($this->pset->runners as $r) {
            if ($qreq->run === $r->name) {
                $this->runner = $r;
                $this->is_ensure = false;
                break;
            } else if ($qreq->run === "{$r->name}.ensure") {
                $this->runner = $r;
                $this->is_ensure = true;
            }
        }
        if (!$this->runner
            || (!$this->viewer->isPC && !$this->runner->visible)) {
            quit("No such command.");
        }
    }

    static function go(Contact $user, Qrequest $qreq) {
        $rreq = new RunRequest($user, $qreq);
        if ($qreq->runmany) {
            $rreq->runmany();
        } else {
            $rreq->run();
        }
    }

    /** @param bool $many
     * @return ?string */
    private function check_view($many) {
        if (!$this->viewer->can_view_run($this->pset, $this->runner, $many ? null : $this->user)) {
            if (!$this->viewer->isPC && !$this->runner->visible) {
                return "No such command.";
            } else if ($this->runner->disabled) {
                return "Command disabled.";
            } else {
                return "Can’t run command now.";
            }
        } else {
            return null;
        }
    }

    function run() {
        $qreq = $this->qreq;
        if ($qreq->run === null || !$qreq->valid_post()) {
            quit("Permission error.");
        } else if (($err = $this->check_view(false))) {
            quit($err);
        }

        $info = PsetView::make($this->pset, $this->user, $this->viewer, $qreq->newcommit ?? $qreq->commit);
        if (!$this->pset->gitless && !$info->hash()) {
            if (!$info->repo) {
                quit("No repository.");
            } else if ($qreq->newcommit ?? $qreq->commit) {
                quit("Commit " . ($qreq->newcommit ?? $qreq->commit) . " isn’t connected to this repository.");
            } else {
                quit("No commits in repository.");
            }
        }

        // can we run this?
        if ($this->runner->command) {
            if (!$info->repo) {
                quit("No repository.");
            } else if (!$info->commit()) {
                quit("No commit to run.");
            } else if (!$info->can_view_repo_contents()) {
                quit("Unconfirmed repository.");
            }
        }
        if (!$this->viewer->can_run($this->pset, $this->runner, $this->user)) {
            quit("You can’t run that command.");
        }

        // extract request info
        $Rstate = new RunnerState($info, $this->runner);
        $Rstate->set_queueid($qreq->get("queueid"));

        // recent or checkup
        if ($qreq->check) {
            json_exit($Rstate->check($qreq));
        }

        // ensure
        if ($qreq->ensure) {
            $answer = $Rstate->check(new Qrequest("GET", ["check" => "recent"]));
            if ($answer->ok || !($answer->run_empty ?? false)) {
                json_exit($answer);
            }
        }

        // check runnability
        if ($this->runner->command) {
            if (!$this->pset->run_dirpattern) {
                quit("Configuration error (run_dirpattern).");
            } else if (!$this->pset->run_jailfiles) {
                quit("Configuration error (run_jailfiles).");
            }
        }

        // queue
        $Queue = $Rstate->make_queue();
        if ($Queue && !$Queue->runnable) {
            json_exit(["onqueue" => true, "queueid" => $Queue->queueid, "nahead" => $Queue->nahead, "headage" => ($Queue->head_runat ? Conf::$now - $Queue->head_runat : null)]);
        }


        // maybe eval
        if (!$this->runner->command && $this->runner->eval) {
            $Rstate->set_checkt(time());
            $json = $Rstate->generic_json();
            $json->done = true;
            $json->status = "done";
            $Rstate->evaluate($json);
            json_exit($json);
        }


        // otherwise run
        try {
            if (($checkt = $Rstate->running_checkt())) {
                quit("Recent job still running.", ["errorcode" => APIData::ERRORCODE_RUNCONFLICT, "checkt" => $checkt, "status" => "workingconflict"]);
            } else if ($info->pset->gitless) {
                quit("Nothing to do");
            }
            session_write_close();

            // run
            $Rstate->start($Queue);

            // save information about execution
            $info->update_commit_notes(["run" => [$this->runner->category => $Rstate->checkt]]);

            json_exit(["ok" => true,
                       "done" => false,
                       "status" => "working",
                       "repoid" => $info->repo->repoid,
                       "pset" => $info->pset->id,
                       "timestamp" => $Rstate->checkt]);
        } catch (Exception $e) {
            quit($e->getMessage());
        }
    }

    function runmany() {
        if (!$this->viewer->isPC) {
            quit("Command reserved for TFs.");
        } else if (!$this->qreq->valid_post()) {
            quit("Session out of date.");
        } else if (($err = $this->check_view(true))) {
            quit($err);
        }

        $t = $this->pset->title;
        if ($this->is_ensure) {
            $t .= " Ensure";
        }
        $t .= " {$this->runner->title}";
        $this->conf->header(htmlspecialchars($t), "home");

        echo '<h2 id="runmany61_who"></h2>',
            Ht::form($this->conf->hoturl_post("run")),
            '<div class="f-contain">',
            Ht::hidden("u", ""),
            Ht::hidden("pset", $this->pset->urlkey);
        if ($this->is_ensure) {
            echo Ht::hidden("ensure", 1);
        }
        echo Ht::hidden("run", $this->runner->name, ["id" => "runmany61", "data-pa-run-category" => $this->runner->category_argument()]),
            '</div></form>';

        echo '<div id="run-' . $this->runner->category . '">',
            '<div class="pa-run pa-run-short" id="pa-run-' . $this->runner->category . '">',
            '<pre class="pa-runpre"></pre></div>',
            '</div>';

        echo '<div id="runmany61_users">';
        $users = [];
        foreach ($this->qreq as $k => $v) {
            if (substr($k, 0, 2) === "s:"
                && $v
                && ($uname = urldecode(substr($k, 2)))) {
                $users[] = $uname;
            }
        }
        if (empty($users) && ($this->qreq->slist ?? $this->qreq->users)) {
            $users = preg_split('/\s+/', $this->qreq->slist ?? $this->qreq->users, -1, PREG_SPLIT_NO_EMPTY);
        }
        $ulinks = $uerrors = [];
        foreach ($users as $uname) {
            if (($u = $this->conf->user_by_whatever($uname))) {
                $ulinks[] = $this->viewer->user_linkpart($u);
            } else {
                $uerrors[] = "Unknown user “" . htmlspecialchars($uname) . "”.";
            }
        }
        echo htmlspecialchars(join(" ", $ulinks)), '</div>';
        if (empty($ulinks)) {
            $uerrors[] = "No users selected.";
        }
        if (!empty($userrors)) {
            echo '<p class="is-error">', join("<br>", $uerrors), '</p>';
        }

        Ht::stash_script('$pa.runmany()');
        echo '<div class="clear"></div>', "\n";
        $this->conf->footer();
    }
}


ContactView::set_path_request(array("/@", "/@/p", "/@/p/h", "/p", "/p/h", "/p/u/h"));
RunRequest::go($Me, $Qreq);

<?php
// contactview.php -- HotCRP helper class for user related printouts
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

class ContactView {
    static function set_path_request($paths) {
        global $Conf, $Qreq;
        $path = Navigation::path();
        if ($path === "") {
            return;
        }
        $x = explode("/", $path);
        if (count($x) && $x[count($x) - 1] === "") {
            array_pop($x);
        }
        foreach ($x as &$xp) {
            $xp = urldecode($xp);
        }
        unset($xp);
        foreach ($paths as $p) {
            $ppos = $xpos = 0;
            $commitsuf = "";
            $settings = array();
            while ($ppos < strlen($p) && $xpos < count($x)) {
                if ($p[$ppos] === "/") {
                    ++$xpos;
                } else if ($p[$ppos] === "p"
                           && $Conf->pset_by_key(get($x, $xpos))) {
                    $settings["pset"] = $x[$xpos];
                } else if ($p[$ppos] === "H"
                           && (strlen($x[$xpos]) === 40 || strlen($x[$xpos]) === 64)
                           && ctype_xdigit($x[$xpos])) {
                    $settings["commit" . $commitsuf] = $x[$xpos];
                    $commitsuf = (int) $commitsuf + 1;
                } else if ($p[$ppos] === "h"
                           && strlen($x[$xpos]) >= 6
                           && ctype_xdigit($x[$xpos])) {
                    $settings["commit" . $commitsuf] = $x[$xpos];
                    $commitsuf = (int) $commitsuf + 1;
                } else if (($p[$ppos] === "H" || $p[$ppos] === "h")
                           && in_array($x[$xpos], ["handout", "latest", "grade", "grading", "head"])) {
                    $settings["commit" . $commitsuf] = $x[$xpos];
                    $commitsuf = (int) $commitsuf + 1;
                } else if ($p[$ppos] === "u"
                           && strlen($x[$xpos])) {
                    if ($x[$xpos][0] !== "@" && $x[$xpos][0] !== "~") {
                        $settings["u"] = $x[$xpos];
                    } else if (strlen($x[$xpos]) > 1) {
                        $settings["u"] = substr($x[$xpos], 1);
                    }
                } else if ($p[$ppos] === "@"
                           && strlen($x[$xpos])
                           && ($x[$xpos][0] === "@" || $x[$xpos][0] === "~")) {
                    if (strlen($x[$xpos]) > 1) {
                        $settings["u"] = substr($x[$xpos], 1);
                    }
                } else if ($p[$ppos] === "f") {
                    $settings["file"] = join("/", array_slice($x, $xpos));
                    $xpos = count($x) - 1;
                } else if ($p[$ppos] === "*") {
                    $xpos = count($x) - 1;
                } else {
                    $settings = null;
                    break;
                }
                ++$ppos;
            }
            if ($settings && $xpos == count($x) - 1) {
                foreach ($settings as $k => $v) {
                    if (!isset($_GET[$k]) && !isset($_POST[$k]))
                        $_GET[$k] = $_REQUEST[$k] = $Qreq[$k] = $v;
                }
                break;
            }
        }
    }

    static function prepare_user(&$usertext, $pset = null) {
        global $Conf, $Me;
        $user = $Me;
        if (isset($usertext) && $usertext) {
            $user = $Conf->user_by_whatever($usertext);
            if (!$user) {
                $Conf->errorMsg("No such user “" . htmlspecialchars($usertext) . "”.");
            } else if ($user->contactId == $Me->contactId) {
                $user = $Me;
            } else if (!$Me->isPC) {
                $Conf->errorMsg("You can’t see that user’s information.");
                $user = null;
            } else {
                $user->set_anonymous(substr($usertext, 0, 5) === "[anon"
                                     || ($pset && $pset->anonymous));
            }
        }
        if ($user && ($Me->isPC || $Me->chairContact)) {
            if ($pset && $pset->anonymous)
                $usertext = $user->anon_username;
            else
                $usertext = $user->username ? : $user->email;
        }
        return $user;
    }

    static function find_pset_redirect($psetkey) {
        global $Conf;
        $pset = $Conf->pset_by_key($psetkey);
        if ((!$pset || $pset->disabled)
            && ($psetkey !== null && $psetkey !== "" && $psetkey !== false)) {
            $Conf->errorMsg("No such problem set “" . htmlspecialchars($psetkey) . "”.");
        }
        if (!$pset || $pset->disabled) {
            foreach ($Conf->psets() as $p) {
                if (!$p->disabled)
                    redirectSelf(array("pset" => $p->urlkey));
            }
            Navigation::redirect("index");
        }
        if ($pset) {
            /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
            $pset->urlkey = $psetkey;
        }
        return $pset;
    }

    static function echo_heading($user) {
        global $Me;
        $u = $Me->user_linkpart($user);
        if ($user !== $Me && !$user->is_anonymous && $user->contactImageId)
            echo '<img class="pa-smallface float-left" src="' . hoturl("face", array("u" => $u, "imageid" => $user->contactImageId)) . '" />';

        echo '<h2 class="homeemail"><a href="',
            hoturl("index", array("u" => $u)), '">', htmlspecialchars($u), '</a>';
        if ($user->extension)
            echo " (X)";
        /*if ($Me->privChair && $user->is_anonymous)
            echo " ",*/
        if ($Me->privChair)
            echo "&nbsp;", become_user_link($user);
        if ($Me->isPC
            && !$user->is_anonymous
            && $user->github_username
            && $Me->conf->opt("githubOrganization")) {
            echo ' <a class="q small ui js-repo-list need-tooltip" href="" data-tooltip="List repositories" data-pa-user="' . htmlspecialchars($user->github_username) . '">®</a>';
        }
        echo '</h2>';

        if ($user !== $Me && !$user->is_anonymous) {
            echo '<h3>', Text::user_html($user);
            if ($user->studentYear) {
                if (strlen($user->studentYear) <= 2
                    && preg_match('/\A(?:[1-9]|1[0-9]|20)\z/', $user->studentYear))
                    echo ' &#', (9311 + $user->studentYear), ';';
                else if (strlen($user->studentYear) === 1
                         && $user->studentYear >= "A"
                         && $user->studentYear <= "Z")
                    echo ' &#', (9398 + ord($user->studentYear) - 65), ';';
                else
                    echo ' ', htmlspecialchars($user->studentYear);
            }
            echo '</h3>';
        }

        if ($user->dropped)
            ContactView::echo_group("", '<strong class="err">You have dropped the course.</strong> If this is incorrect, contact us.');

        echo '<hr class="c" />';
    }

    static function echo_group($key, $value, $notes = null) {
        echo "<div class=\"pa-p\"><div class=\"pa-pt\">", $key, "</div><div class=\"pa-pd\">";
        if ($notes && $value && !str_starts_with($value, '<div'))
            $value = "<div>" . $value . "</div>";
        echo $value;
        foreach ($notes ? : [] as $v) {
            $v = is_array($v) ? $v : array(false, $v);
            echo "<div class=\"", ($v[0] ? "pa-inf-error" : "pa-inf"),
                "\">", $v[1], "</div>";
        }
        echo "</div></div>\n";
    }

    static function echo_deadline_group(PsetView $info) {
        if (($dl = $info->deadline()) && $dl > Conf::$now) {
            self::echo_group("deadline", $info->conf->unparse_time($dl));
        }
    }

    static function echo_partner_group(PsetView $info) {
        global $Conf, $Me;
        list($user, $pset, $partner) =
            array($info->user, $info->pset, $info->partner);
        if (!$pset->partner) {
            return;
        }

        // collect values
        $partner_email = "";
        if ($user->is_anonymous && $partner) {
            $partner_email = $partner->anon_username;
        } else if ($partner) {
            $partner_email = $partner->email;
        }
        $editable = $Me->can_set_repo($pset, $user) && !$user->is_anonymous;

        $title = "partner";
        if ($Me->isPC && $partner) {
            $title = '<a href="' . hoturl("pset", ["u" => $Me->user_linkpart($partner), "pset" => $pset->id, "commit" => $info->hash()]) . '">' . $title . '</a>';
        }

        if ($editable) {
            $value = Ht::entry("partner", $partner_email, array("style" => "width:32em"))
                . " " . Ht::submit("Save");
        } else {
            $value = htmlspecialchars($partner_email ? : "(none)");
        }

        // check back-partner links
        $notes = array();
        $backpartners = $info->backpartners();
        if (count($backpartners) == 0 && $partner) {
            $notes[] = array(true, "ERROR: " . htmlspecialchars($partner_email) . " has not listed you as a partner yet.");
        } else if (count($backpartners) == 1 && $partner && $backpartners[0] == $partner->contactId) {
            if ($partner->dropped) {
                $notes[] = array(true, "ERROR: We believe your partner has dropped the course.");
            }
        } else if (count($backpartners) == 0 && !$partner) {
            if ($editable) {
                $notes[] = array(false, "Enter your partner’s email, username, or HUID here.");
            }
        } else {
            $backpartners[] = -1;
            $result = $Conf->qe("select " . ($user->is_anonymous ? "anon_username" : "email") . " from ContactInfo where contactId ?a", $backpartners);
            $p = array();
            while (($row = $result->fetch_row())) {
                if ($Me->isPC) {
                    $p[] = '<a href="' . hoturl("pset", array("pset" => $pset->urlkey, "u" => $row[0])) . '">' . htmlspecialchars($row[0]) . '</a>';
                } else {
                    $p[] = htmlspecialchars($row[0]);
                }
            }
            $notes[] = array(true, "ERROR: These users have listed you as a partner for this pset: " . commajoin($p));
        }

        if ($editable) {
            echo Ht::form($Conf->selfurl(null, ["set_partner" => 1, "pset" => $pset->urlkey], Conf::HOTURL_POST)),
                "<div class='f-contain'>";
        }
        self::echo_group($title, $value, $notes);
        if ($editable) {
            echo "</div></form>";
        }
        echo "\n";
    }

    static function set_partner_action($user, $qreq) {
        global $Conf, $Me;
        if (!($Me->has_account_here()
              && $qreq->valid_post()
              && ($pset = $Conf->pset_by_key($qreq->pset)))) {
            return;
        }
        if (!$Me->can_set_repo($pset, $user)) {
            return $Conf->errorMsg("You can’t edit repository information for that problem set now.");
        }
        if ($user->set_partner($pset, $_REQUEST["partner"])) {
            redirectSelf();
        }
    }

    static function echo_repo_group(PsetView $info, $full = false) {
        global $Conf, $Me, $Qreq;
        if ($info->pset->gitless)
            return;
        list($user, $pset, $partner, $repo) =
            array($info->user, $info->pset, $info->partner, $info->repo);
        $editable = $Me->can_set_repo($pset, $user) && !$user->is_anonymous;

        $repo_url = $repo ? $repo->friendly_url() : "";
        $title = "repository";
        if (!RepositorySite::is_primary($repo)) {
            $title = $repo->reposite->friendly_siteclass() . " " . $title;
        }
        if ($repo && $repo->url) {
            $title = $user->link_repo($title, $repo->https_url());
        }

        if ($editable) {
            $xvalue = $repo_url;
            $js = ["style" => "width:32em"];
            if ($repo_url === ""
                && isset($Qreq->set_repo)
                && $Qreq->pset === $pset->urlkey
                && isset($Qreq->repo)) {
                $xvalue = htmlspecialchars($Qreq->repo);
                $js["class"] = "error";
            }
            $value = Ht::entry("repo", $xvalue, $js) . " " . Ht::submit("Save");
        } else if ($user->is_anonymous) {
            $value = $repo_url ? "[anonymous]" : "(none)";
        } else {
            $value = htmlspecialchars($repo_url ? $repo_url : "(none)");
        }
        if ($repo_url) {
            $value .= ' <button class="btn ui js-repo-copy need-tooltip" data-pa-repo="' . htmlspecialchars($repo->ssh_url()) . '"';
            if ($user->is_anonymous) {
                $value .= ' data-tooltip="[anonymous]"';
            } else {
                $value .= ' data-tooltip="' . htmlspecialchars($repo->ssh_url()) . '"';
            }
            $value .= ' type="button">Copy URL to clipboard</button>';
        }

        // check repo
        $ms = new MessageSet;
        $ms->user = $user;
        if ($repo) {
            $repo->check_working($ms);
            $repo->check_open($ms);
        }
        if ($partner && $info->partner_same() && !$pset->partner_repo) {
            $prepo = $partner->repo($pset->id);
            if ((!$repo && $prepo) || ($repo && !$prepo)
                || ($repo && $prepo && $repo->repoid != $prepo->repoid)) {
                if ($prepo && $repo) {
                    $prepo_url = ", " . htmlspecialchars($prepo->friendly_url_like($repo));
                } else if ($prepo) {
                    $prepo_url = ", " . htmlspecialchars($prepo->friendly_url());
                } else {
                    $prepo_url = "";
                }
                $your_partner = "your partner’s";
                if ($Me->isPC) {
                    $your_partner = '<a href="' . hoturl("pset", array("pset" => $pset->urlkey, "u" => $Me->user_linkpart($partner))) . '">' . $your_partner . '</a>';
                }
                $ms->error_at("partner", "This repository differs from $your_partner$prepo_url.");
            }
        }
        if ($repo) {
            $repo->check_ownership($user, $partner, $ms);
        }
        $prefixes = ["", "WARNING: ", "ERROR: "];
        $notes = array_map(function ($m) use ($prefixes) {
            return [$m->status > 0, $prefixes[max($m->status, 0)] . $m->message];
        }, $ms->message_list());
        if ($repo
            && $pset->handout_warn_merge !== false
            && $repo->truncated_psetdir($pset)) {
            $notes[] = [true, "Please create your repository by cloning our repository. Creating your repository from scratch makes it harder for us to grade and harder for you to get pset updates."];
        }
        if (!$repo) {
            $repoclasses = RepositorySite::site_classes($Conf);
            $x = commajoin(array_map(function ($k) { return Ht::link($k::global_friendly_siteclass(), $k::global_friendly_siteurl()); }, $repoclasses), "or");
            if ($editable) {
                $notes[] = array(false, "Enter your $x repository URL here.");
            }
        }

        // edit
        if ($editable) {
            echo Ht::form($Conf->selfurl(null, ["set_repo" => 1, "pset" => $pset->urlkey], Conf::HOTURL_POST)),
                '<div class="f-contain">';
        }
        self::echo_group($title, $value, $notes);
        if ($editable) {
            echo "</div></form>\n";
        }

        if (!$pset->no_branch) {
            self::echo_branch_group($info);
        }

        return $repo;
    }

    static function echo_branch_group(PsetView $info) {
        global $Conf, $Me, $Qreq;
        list($user, $pset, $partner, $repo) =
            array($info->user, $info->pset, $info->partner, $info->repo);
        $editable = $Me->can_set_repo($pset, $user) && !$user->is_anonymous;
        $branch = $user->branch($pset);

        if ($editable) {
            $xvalue = $branch === $pset->main_branch ? null : $branch;
            $js = ["style" => "width:32em", "placeholder" => $pset->main_branch];
            if (isset($Qreq->set_branch)
                && $Qreq->pset === $pset->urlkey
                && isset($Qreq->branch)) {
                $xvalue = htmlspecialchars($Qreq->branch);
                $js["class"] = "error";
            }
            $value = Ht::entry("branch", $xvalue, $js) . " " . Ht::submit("Save");
        } else if ($user->is_anonymous) {
            $value = $branch && $branch !== $pset->main_branch ? "[anonymous]" : $pset->main_branch;
        } else {
            $value = htmlspecialchars($branch);
        }

        // edit
        if ($editable) {
            echo Ht::form($Conf->selfurl(null, ["set_branch" => 1, "pset" => $pset->urlkey], Conf::HOTURL_POST)),
                '<div class="f-contain">';
        }
        self::echo_group("branch", $value, []);
        if ($editable) {
            echo "</div></form>\n";
        }
    }

    static function echo_downloads_group(PsetView $info) {
        global $Conf, $Me, $Qreq;
        $n = 0;
        foreach ($info->pset->downloads as $dl) {
            if ($info->user == $info->viewer
                && (!$dl->visible
                    || (is_int($dl->visible) && $dl->visible > Conf::$now))) {
                continue;
            }
            if (!$n)
                echo '<hr><h2>downloads</h2>';
            ++$n;

            $timer_start = 0;
            if ($dl->timed) {
                $all_dltimes = $info->user_jnote("downloaded_at");
                if ($all_dltimes && isset($all_dltimes->{$dl->key})) {
                    foreach ($all_dltimes->{$dl->key} as $dltime) {
                        if (!isset($dltime[1]) || $dltime[1] == $info->viewer->contactId) {
                            $timer_start = $timer_start ? min($timer_start, $dltime[0]) : $dltime[0];
                        }
                    }
                }
            }

            echo '<div class="pa-p', ($dl->visible ? "" : " pa-p-hidden");
            if ($timer_start) {
                echo ' pa-download-timed" data-pa-download-at="', htmlspecialchars($timer_start);
                if ($dl->timeout !== null) {
                    echo '" data-pa-download-expiry="', ($timer_start + $dl->timeout);
                }
                if ($info->repo
                    && !$info->pset->gitless
                    && ($h = $info->hash())
                    && ($ls = $info->connected_commit($h))) {
                    echo '" data-pa-commit-at="', $ls->commitat;
                }
            }
            echo '"><div class="pa-pt">', htmlspecialchars($dl->title), '</div><div class="pa-pd">';
            echo '<a href="', hoturl("pset", ["pset" => $info->pset->urlkey, "u" => $info->viewer->user_linkpart($info->user), "post" => post_value(), "download" => $dl->key]), '">', htmlspecialchars($dl->filename), '</a>';
            if ($timer_start)
                echo '<span class="pa-download-timer" style="padding-left:1em"></span>';
            echo '</span></div></div>';
        }
    }

    static function set_repo_action($user, $qreq) {
        global $Conf, $Me;
        if (!($Me->has_account_here()
              && $qreq->valid_post()
              && ($pset = $Conf->pset_by_key($qreq->pset)))) {
            return;
        }
        if (!$Me->can_set_repo($pset, $user)) {
            return Conf::msg_error("You can’t edit repository information for that problem set now.");
        }

        // clean up repo url
        $repo_url = trim($qreq->repo);
        if ($repo_url === "") {
            $user->set_repo($pset->id, null);
            redirectSelf();
        }

        // extend it to full url
        if (($rgp = $pset->repo_guess_patterns) !== null) {
            for ($i = 0; $i + 1 < count($rgp); $i += 2) {
                $x = preg_replace('`' . str_replace("`", "\\`", $rgp[$i]) . '`s',
                                  $rgp[$i + 1], $repo_url, -1, $nreplace);
                if ($x !== null && $nreplace) {
                    $repo_url = $x;
                    break;
                }
            }
        }

        // does it contain odd characters?
        if (preg_match('_[,;\[\](){}\\<>&#=\\000-\\027]_', $repo_url)) {
            return Conf::msg_error("That repository contains funny characters. Remove them.");
        }

        // record interested repositories
        $try_classes = [];
        foreach (RepositorySite::site_classes($user->conf) as $sitek) {
            $sniff = $sitek::sniff_url($repo_url);
            if ($sniff == 2) {
                $try_classes = [$sitek];
                break;
            } else if ($sniff) {
                $try_classes[] = $sitek;
            }
        }
        if (empty($try_classes)) {
            return Conf::msg_error("Invalid repository URL “" . htmlspecialchars($repo_url) . "”.");
        }

        // check repositories
        $ms = new MessageSet;
        $ms->user = $user;
        foreach ($try_classes as $sitek) {
            $reposite = $sitek::make_url($repo_url, $user->conf);
            if ($reposite && $reposite->validate_working($ms) > 0) {
                $repo = Repository::find_or_create_url($reposite->url, $user->conf);
                if ($repo) {
                    $repo->check_open();
                }
                if ($user->set_repo($pset->id, $repo)) {
                    redirectSelf();
                }
                return;
            }
        }

        // if !working, complain
        if (!$ms->has_problem()) {
            Conf::msg_error("Can’t access the repository “" . htmlspecialchars($repo_url) . "” (tried " . join(", ", array_map(function ($m) { return $m::global_friendly_siteclass(); }, $try_classes)) . ").");
        } else {
            $msgs = join("<br />", $ms->message_texts()) ? : "Repository unreachable at the moment.";
            Conf::msg_error($msgs);
        }
    }

    /** @param Contact $user
     * @param Qrequest $qreq */
    static function set_branch_action($user, $qreq) {
        global $Me;
        if (!($Me->has_account_here()
              && $qreq->valid_post()
              && ($pset = $user->conf->pset_by_key($qreq->pset))
              && !$pset->no_branch)) {
            return;
        }
        if (!$Me->can_set_repo($pset, $user)) {
            return Conf::msg_error("You can’t edit repository information for that problem set now.");
        }

        $branch = trim($qreq->branch);
        if ($branch === "") {
            $branch = $pset->main_branch;
        }
        if (!Repository::validate_branch($branch)) {
            return Conf::msg_error("That branch contains funny characters. Remove them.");
        }

        $branchid = $user->conf->ensure_branch($branch);
        if ($branchid === null || $branchid === 0) {
            $user->clear_links(LINK_BRANCH, $pset->id);
        } else {
            $user->set_link(LINK_BRANCH, $pset->id, $branchid);
        }
        redirectSelf();
    }

    static function late_hour_note(PsetView $info) {
        if (($lh = $info->late_hours())
            && $lh > 0
            && (!$info->pset->obscure_late_hours || $info->can_view_grades())) {
            $t = plural($lh, "late hour") . " used";
            if (!$info->pset->obscure_late_hours) {
                $t = '<strong class="overdue">' . $t . '</strong>';
            }
            return $t;
        } else {
            return null;
        }
    }

    static function echo_repo_last_commit_group(PsetView $info, $commitgroup) {
        global $Me;
        list($user, $repo, $pset) = [$info->user, $info->repo, $info->pset];
        if ($pset->gitless) {
            return;
        }
        $branch = $user->branch($pset);

        $snapc = $value = null;
        if ($repo) {
            $snapc = $info->commit();
        } else if ($repo) {
            if ($repo->snaphash && $branch === $info->conf->default_main_branch) {
                $snapc = new CommitRecord($repo->snapcommitat, $repo->snaphash, $repo->snapcommitline);
            } else if ($repo->snapat) {
                $snapc = $repo->latest_commit($pset, $branch);
            }
        }
        if ($repo && !$info->user_can_view_repo_contents()) {
            $value = "(unconfirmed repository)";
        } else if ($snapc) {
            $value = substr($snapc->hash, 0, 7) . " " . htmlspecialchars($snapc->subject);
        } else {
            if (!$repo) {
                $value = "(no repo yet)";
            } else if (!$repo->snapat) {
                $value = "(checking)";
            } else if ($pset->directory_noslash !== "" && $repo->latest_commit(null, $branch)) {
                $value = "(missing pset subdirectory)";
            } else {
                $value = "(no such branch)";
            }
        }

        $notes = array();
        if ($repo && $info->can_view_repo_contents() && $repo->snapat) {
            $n = "";
            if ($snapc) {
                $n = "committed " . ago($snapc->commitat) . ", ";
                if ($commitgroup && ($lh = self::late_hour_note($info))) {
                    $n .= "{$lh}, ";
                }
            }
            $n .= "fetched " . ago($repo->snapat) . ", last checked " . ago($repo->snapcheckat);
            if ($Me->privChair) {
                $n .= " <small style=\"padding-left:1em;font-size:70%\">group " . $repo->cacheid . ", repo" . $repo->repoid . "</small>";
            }
            $notes[] = $n;
        }
        if ($repo && !$info->user_can_view_repo_contents()) {
            if ($user->is_anonymous) {
                $notes[] = array(true, "ERROR: The user hasn’t confirmed that they can view this repository.");
            } else {
                $uname = Text::analyze_name($user);
                if ($uname->name && $uname->email) {
                    $uname = "$uname->name <$uname->email>";
                } else if ($uname->email) {
                    $uname = "Your Name <$uname->email>";
                } else if ($uname->name) {
                    $uname = "$uname->name <youremail@example.com>";
                } else {
                    $uname = "Your Name <youremail@example.com>";
                }
                $uname = addcslashes($uname, "\\\"\`\$!");
                $notes[] = array(true, "ERROR: We haven’t confirmed that you can view this repository.<br>
    We only let you view repositories that you’ve committed to.<br>
    Fix this error by making a commit from your course-related email address, " . htmlspecialchars($user->email) . ", and pushing that commit to the repository.<br>
    For example, try these commands: <pre>git commit --allow-empty --author=\"" . htmlspecialchars($uname) . "\" -m \"Confirm repository\"\ngit push</pre>");
            }
            $commitgroup = true;
        }

        if ($commitgroup) {
            echo "<div class=\"pa-commitcontainer\" data-pa-pset=\"", htmlspecialchars($info->pset->urlkey);
            if ($snapc) {
                echo "\" data-pa-commit=\"", $snapc->hash;
            }
            echo "\">";
            Ht::stash_script("\$pa.checklatest()", "pa_checklatest");
        }
        self::echo_group("last commit", $value, $notes);
        if ($commitgroup) {
            echo "</div>";
        }
    }

    static function echo_repo_grade_commit_group(PsetView $info) {
        list($user, $repo) = array($info->user, $info->repo);
        if ($info->pset->gitless_grades) {
            return;
        } else if (!$info->user_can_view_repo_contents()
                   || !$info->grading_hash()) {
            return self::echo_repo_last_commit_group($info, false);
        }
        // XXX should check can_view_grades here

        $value = "";
        if (($ginfo = $info->grading_commit())) {
            $value = substr($ginfo->hash, 0, 7) . " " . htmlspecialchars($ginfo->subject);
        }

        $notes = array();
        if ($ginfo && $ginfo->commitat) {
            $notes[] = "committed " . ago($ginfo->commitat);
        }
        if (($t = self::late_hour_note($info))) {
            $notes[] = $t;
        }

        self::echo_group("grading commit", $value, array(join(", ", $notes)));
    }

    static function pset_grade($notesj, $pset) {
        if (!$pset->grades()) {
            return null;
        }

        $total = $nonextra = 0;
        $r = [];
        $g = $notesj->grades ?? null;
        $ag = $notesj->autogrades ?? null;
        $rag = array();
        foreach ($pset->grades() as $ge) {
            $key = $ge->key;
            $gv = null;
            if ($ag && isset($ag->$key)) {
                $gv = $rag[$key] = $ag->$key;
            }
            if ($g && isset($g->$key)) {
                $gv = $g->$key;
            }
            if ($gv !== null) {
                $r[$key] = $gv;
                if (!$ge->no_total) {
                    $total += $gv;
                    if (!$ge->is_extra) {
                        $nonextra += $gv;
                    }
                }
            }
        }
        if (!empty($r)) {
            $r["total"] = $total;
            $r["total_noextra"] = $nonextra;
            if (!empty($rag)) {
                $r["autogrades"] = (object) $rag;
            }
            return (object) $r;
        } else {
            return null;
        }
    }

    static function error_exit($status, $title) {
        header("HTTP/1.0 $status");
        Conf::$main->header($title, "home");
        echo "<hr class=\"c\">\n";
        Conf::$main->footer();
        exit;
    }
}

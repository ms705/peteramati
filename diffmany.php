<?php
// diffmany.php -- Peteramati multidiff page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
ContactView::set_path_request(["/p"]);
if ($Me->is_empty() || !$Me->isPC) {
    $Me->escape();
}

class DiffMany {
    /** @var Conf */
    public $conf;
    /** @var Pset */
    public $pset;
    /** @var Qrequest */
    public $qreq;
    /** @var Contact */
    public $viewer;
    /** @var list<string> */
    private $files;
    /** @var list<string> */
    private $suppress_grades = [];
    /** @var int */
    public $psetinfo_idx = 0;
    /** @var array<string,GradeEntryConfig> */
    public $all_viewed = [];

    function __construct(Pset $pset, Qrequest $qreq, Contact $viewer) {
        $this->conf = $viewer->conf;
        $this->pset = $pset;
        $this->qreq = $qreq;
        $this->viewer = $viewer;

        if ($qreq->files) {
            $f = simplify_whitespace($qreq->files);
            $fs = $f === "" ? [] : explode(" ", $f);
        } else if ($qreq->file) {
            $fs = [$qreq->file];
        } else {
            $fs = null;
        }
        if ($fs !== null) {
            $this->files = $pset->maybe_prefix_directory($fs);
        }

        if ($qreq->grade) {
            $grades = [];
            foreach ($pset->grades_by_key_list($qreq->grade, true) as $ge) {
                $grades[$ge->key] = true;
            }
            foreach ($pset->grades as $ge) {
                if (!isset($grades[$ge->key])) {
                    $this->suppress_grades[] = $ge->key;
                }
            }
        }
    }

    function echo_one(Contact $user) {
        ++$this->psetinfo_idx;
        $pset = $this->pset;
        $info = PsetView::make($pset, $user, $this->viewer);
        foreach ($this->suppress_grades as $key) {
            $info->suppress_grade($key);
        }

        // should we skip?
        if ($pset->gitless ? !$info->visible_grades() : !$info->repo) {
            return;
        }

        $linkpart_html = htmlspecialchars($this->viewer->user_linkpart($user));
        echo '<div id="pa-psetinfo', $this->psetinfo_idx,
            '" class="pa-psetinfo pa-psetinfo-partial pa-diffcontext',
            '" data-pa-pset="', htmlspecialchars($pset->urlkey),
            '" data-pa-user="', $linkpart_html;
        if (!$pset->gitless && $info->commit_hash()) {
            echo '" data-pa-hash="', htmlspecialchars($info->commit_hash());
        }
        if (!$pset->gitless && $pset->directory) {
            echo '" data-pa-directory="', htmlspecialchars($pset->directory_slash);
        }
        if ($info->user_can_view_grades()) {
            echo '" data-pa-user-can-view-grades="yes';
        }
        if ($info->can_edit_grades_staff()
            || ($info->can_view_grades() && $info->is_grading_commit())) {
            echo '" data-pa-gradeinfo="', htmlspecialchars(json_encode_browser($info->grade_json(PsetView::GRADEJSON_SLICE)));
        }
        echo '">';

        $u = $this->viewer->user_linkpart($user);
        if ($user !== $this->viewer && !$user->is_anonymous && $user->contactImageId) {
            echo '<img class="pa-smallface" src="' . hoturl("face", ["u" => $u, "imageid" => $user->contactImageId]) . '" />';
        }

        echo '<h2 class="homeemail"><a href="',
            hoturl("pset", array("u" => $u, "pset" => $pset->urlkey)), '">', htmlspecialchars($u), '</a>';
        if ($user->extension) {
            echo " (X)";
        }
        /*if ($Me->privChair && $user->is_anonymous)
            echo " ",*/
        if ($this->viewer->privChair) {
            echo "&nbsp;", become_user_link($user);
        }
        echo '</h2>';

        if ($user !== $this->viewer && !$user->is_anonymous) {
            echo '<h3>', Text::user_html($user), '</h3>';
        }
        echo '<hr class="c" />';

        if (!$pset->gitless && $info->commit()) {
            $lnorder = $info->viewable_line_notes();
            $onlyfiles = $this->files;
            $diff = $info->diff($info->base_handout_commit(), $info->commit(), $lnorder, ["onlyfiles" => $onlyfiles, "no_full" => true]);
            if ($onlyfiles !== null
                && count($onlyfiles) === 1
                && isset($diff[$onlyfiles[0]])
                && $this->qreq->lines
                && preg_match('/\A\s*(\d+)-(\d+)\s*\z/', $this->qreq->lines, $m)) {
                $diff[$onlyfiles[0]] = $diff[$onlyfiles[0]]->restrict_linea(intval($m[1]), intval($m[2]) + 1);
            }
            $want_grades = $pset->has_grade_landmark;

            if (!empty($diff)) {
                if ($info->can_edit_grades_staff() && !$pset->has_grade_landmark_range) {
                    PsetView::echo_pa_sidebar_gradelist();
                    $want_grades = true;
                }
                foreach ($diff as $file => $dinfo) {
                    $info->echo_file_diff($file, $dinfo, $lnorder, [
                        "expand" => true, "id_by_user" => true, "hide_left" => true,
                        "no_heading" => count($this->files) == 1,
                        "diffcontext" => $linkpart_html . "/"
                    ]);
                }
                if ($info->can_edit_grades_staff() && !$pset->has_grade_landmark_range) {
                    PsetView::echo_close_pa_sidebar_gradelist();
                }
            }

            $this->all_viewed += $info->viewed_gradeentries; // XXX off if want all grades
        } else {
            echo '<div class="pa-gradelist is-main',
                ($info->user_can_view_grades() ? "" : " pa-pset-hidden"), '"></div>';
            $want_grades = true;
        }

        echo "</div>\n";
        if ($want_grades) {
            echo Ht::unstash_script('$pa.loadgrades.call(document.getElementById("pa-psetinfo' . $this->psetinfo_idx . '"))');
        }
        echo "<hr />\n";
    }

    function run() {
        $title = $this->pset->title;
        if ($this->files) {
            $title .= " > " . join(" ", $this->files);
        }
        $this->conf->header(htmlspecialchars($title), "home");

        foreach ($this->pset->grade_script ?? [] as $gs) {
            Ht::stash_html($this->conf->make_script_file($gs));
        }
        echo "<div class=\"pa-psetinfo pa-diffset\" data-pa-gradeinfo=\"",
             htmlspecialchars(json_encode(new GradeExport($this->pset, $this->viewer->isPC))),
             "\">";

        if (trim((string) $this->qreq->users) === "") {
            $want = [];
            $sset = new StudentSet($this->viewer, StudentSet::ALL);
            $sset->set_pset($this->pset);
            foreach ($sset as $info) {
                if (!$info->user->visited
                    && $info->grading_hash()
                    && !$info->user->dropped) {
                    $want[] = $info->user->email;
                    foreach ($info->user->links(LINK_PARTNER, $this->pset->id) as $pcid) {
                        if (($u = $sset->user($pcid)))
                            $u->visited = true;
                    }
                }
            }
            $this->qreq->users = join(" ", $want);
        }

        foreach (explode(" ", $this->qreq->users) as $user) {
            if ($user !== "" && ($u = $this->conf->user_by_whatever($user))) {
                $this->echo_one($u);
            } else if ($user !== "") {
                echo "<p>no such user ", htmlspecialchars($user), "</p>\n";
            }
        }

        foreach ($this->all_viewed as $gkey => $x) {
            $gradeentry = $this->pset->all_grades[$gkey];
            if ($gradeentry->landmark_buttons) {
                foreach ($gradeentry->landmark_buttons as $lb) {
                    if (is_object($lb) && isset($lb->summary_className)) {
                        echo '<button type="button" class="ui btn ', $lb->summary_className, '" data-pa-class="', $lb->className, '">Summarize ', $lb->title, '</button>';
                    }
                }
            }
        }

        Ht::stash_script('$(window).on("beforeunload",$pa.beforeunload)');
        echo "</div><div class='clear'></div>\n";
        $this->conf->footer();
    }
}

(new DiffMany(ContactView::find_pset_redirect($Qreq->pset), $Qreq, $Me))->run();

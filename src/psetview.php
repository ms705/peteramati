<?php
// psetview.php -- CS61-monster helper class for pset view
// Peteramati is Copyright (c) 2006-2019 Eddie Kohler
// See LICENSE for open-source distribution terms

class PsetView {
    /** @var Conf */
    public $conf;
    /** @var Pset */
    public $pset;
    /** @var Contact */
    public $user;
    /** @var Contact */
    public $viewer;
    /** @var bool */
    public $pc_view;
    /** @var ?Repository */
    public $repo;
    /** @var ?Contact */
    public $partner;
    /** @var ?string */
    public $branch;
    /** @var ?int */
    public $branchid;
    /** @var ?bool */
    private $partner_same;

    /** @var int */
    private $_havepi = 0;
    /** @var ?UserPsetInfo */
    private $_upi;
    /** @var ?RepositoryPsetInfo */
    private $_rpi;
    /** @var ?CommitPsetInfo */
    private $_cpi;

    /** @var ?bool */
    private $can_view_grades;
    /** @var ?bool */
    private $user_can_view_grades;

    /** @var ?string */
    private $hash;
    /** @var bool */
    private $hash_set = false;
    private $derived_handout_commit;
    /** @var ?int */
    private $n_visible_grades;
    /** @var ?int */
    private $n_visible_in_total;
    /** @var ?int */
    private $n_student_grades;
    /** @var ?int */
    private $n_nonempty_grades;
    /** @var ?int */
    private $n_nonempty_assigned_grades;
    /** @var bool */
    private $need_format = false;
    /** @var bool */
    private $added_diffinfo = false;

    const ERROR_NOTRUN = 1;
    const ERROR_LOGMISSING = 2;
    public $last_runner_error;
    /** @var array<string,array<int,list<string>>> */
    private $transferred_warnings;
    /** @var array<string,float> */
    private $transferred_warnings_priority;
    public $viewed_gradeentries = [];

    /** @var int */
    private $_diff_tabwidth;
    private $_diff_lnorder;

    static private $forced_commitat = 0;

    function __construct(Pset $pset, Contact $user, Contact $viewer) {
        $this->conf = $pset->conf;
        $this->pset = $pset;
        $this->user = $user;
        $this->viewer = $viewer;
        $this->pc_view = $viewer->isPC && $viewer !== $user;
        assert($viewer === $user || $this->pc_view);
    }

    static function make(Pset $pset, Contact $user, Contact $viewer) {
        $info = new PsetView($pset, $user, $viewer);
        $info->partner = $user->partner($pset->id);
        if (!$pset->gitless) {
            $info->repo = $user->repo($pset->id);
            $info->branchid = $user->branchid($pset);
            $info->branch = $info->conf->branch($info->branchid);
        }
        $info->set_hash(null);
        return $info;
    }

    static function make_from_set_at(StudentSet $sset, Contact $user, Pset $pset) {
        $info = new PsetView($pset, $user, $sset->viewer);
        if (($pcid = $user->link(LINK_PARTNER, $pset->id))) {
            $info->partner = $user->partner($pset->id, $sset->user($pcid));
        }
        if (!$pset->gitless) {
            $info->repo = $user->repo($pset->id, $sset->repo_at($user, $pset));
            $info->branchid = $user->branchid($pset);
            $info->branch = $info->conf->branch($info->branchid);
        }
        $info->_havepi = 1;
        $info->_upi = $sset->upi_for($user, $pset);
        if (!$pset->gitless_grades) {
            $info->_havepi |= 2;
            if (($info->_rpi = $sset->rpi_for($user, $pset))) {
                $info->hash = $info->_rpi->gradehash;
            }
        }
        return $info;
    }

    /** @return string */
    function branch() {
        return $this->branch;
    }

    /** @return ?UserPsetInfo */
    private function upi() {
        if (($this->_havepi & 1) === 0) {
            $this->_havepi |= 1;
            $this->_upi = $this->pset->upi_for($this->user);
        }
        return $this->_upi;
    }

    /** @return ?RepositoryPsetInfo */
    private function rpi() {
        if (($this->_havepi & 2) === 0) {
            $this->_havepi |= 2;
            if ($this->repo) {
                $this->_rpi = $this->pset->rpi_for($this->repo, $this->branchid);
            }
        }
        return $this->_rpi;
    }

    /** @return ?CommitPsetInfo */
    private function cpi() {
        if (($this->_havepi & 4) === 0) {
            $this->_havepi |= 4;
            if ($this->repo && $this->hash) {
                $this->_cpi = $this->pset->cpi_at($this->hash);
            }
        }
        return $this->_cpi;
    }

    /** @return null|RepositoryPsetInfo|UserPsetInfo */
    private function gpi() {
        return $this->pset->gitless_grades ? $this->upi() : $this->rpi();
    }

    /** @param string $hashpart
     * @return ?CommitRecord */
    function find_commit($hashpart) {
        if ($hashpart === "handout") {
            return $this->base_handout_commit();
        } else if ($hashpart === "head" || $hashpart === "latest") {
            return $this->latest_commit();
        } else if ($hashpart === "grade" || $hashpart === "grading") {
            return $this->grading_commit();
        } else if ($hashpart) {
            list($cx, $definitive) = Repository::find_listed_commit($hashpart, $this->pset->handout_commits());
            if ($cx) {
                return $cx;
            } else if ($this->repo) {
                return $this->repo->connected_commit($hashpart, $this->pset, $this->branch);
            } else {
                return null;
            }
        }
    }

    /** @param ?int $n */
    private function set_grade_counts($n) {
        $this->n_visible_grades = $n;
        $this->n_visible_in_total = $n;
        $this->n_student_grades = $n;
        $this->n_nonempty_grades = $n;
        $this->n_nonempty_assigned_grades = $n;
    }

    /** @return ?non-empty-string */
    function set_hash($reqhash) {
        $this->hash = null;
        $this->hash_set = true;
        $this->_havepi &= ~4;
        $this->_cpi = null;
        $this->derived_handout_commit = false;
        $this->set_grade_counts(null);
        if ($this->repo) {
            if ($reqhash) {
                if (($c = $this->repo->connected_commit($reqhash, $this->pset, $this->branch))) {
                    $this->hash = $c->hash;
                }
            } else if (($gh = $this->grading_hash())) {
                $this->hash = $gh;
            } else if (($c = $this->latest_commit())) {
                $this->hash = $c->hash;
            }
        }
        return $this->hash;
    }

    /** @param ?string $reqhash */
    function force_set_hash($reqhash) {
        assert($reqhash === null || strlen($reqhash) === 40);
        if ($this->hash !== $reqhash
            || ($reqhash === null && !$this->hash_set)) {
            $this->hash = $reqhash;
            $this->hash_set = true;
            $this->_havepi &= ~4;
            $this->_cpi = null;
            $this->derived_handout_commit = false;
        }
    }

    function set_commit(CommitRecord $commit) {
        $this->force_set_hash($commit->hash);
    }

    /** @return bool */
    function has_commit_set() {
        return $this->hash !== null;
    }

    /** @return non-empty-string */
    function commit_hash() {
        assert($this->hash !== null);
        return $this->hash;
    }

    /** @return ?non-empty-string */
    function maybe_commit_hash() {
        return $this->hash;
    }

    /** @return ?CommitRecord */
    function commit() {
        if ($this->hash === null) {
            error_log(json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)) . " " . $this->viewer->email);
        }
        assert($this->hash !== null);
        return $this->hash ? $this->connected_commit($this->hash) : null;
    }

    /** @return bool */
    function can_have_grades() {
        return $this->pset->gitless_grades || $this->commit();
    }

    /** @return array<string,CommitRecord> */
    function recent_commits() {
        if ($this->repo) {
            return $this->repo->commits($this->pset, $this->branch);
        } else {
            return [];
        }
    }

    /** @return ?CommitRecord */
    function connected_commit($hash) {
        if ($this->repo) {
            return $this->repo->connected_commit($hash, $this->pset, $this->branch);
        } else {
            return null;
        }
    }

    /** @return ?CommitRecord */
    function latest_commit() {
        $cs = $this->repo ? $this->repo->commits($this->pset, $this->branch) : [];
        reset($cs);
        return current($cs);
    }

    /** @return ?non-empty-string */
    function latest_hash() {
        $lc = $this->latest_commit();
        return $lc ? $lc->hash : null;
    }

    /** @return bool */
    function is_latest_commit() {
        return $this->hash && $this->hash === $this->latest_hash();
    }

    /** @return ?CommitRecord */
    function derived_handout_commit() {
        if ($this->derived_handout_commit === false) {
            $this->derived_handout_commit = null;
            $hbases = $this->pset->handout_commits();
            $seen_hash = !$this->hash;
            foreach ($this->recent_commits() as $c) {
                if ($c->hash === $this->hash) {
                    $seen_hash = true;
                }
                if (isset($hbases[$c->hash])) {
                    $this->derived_handout_commit = $c;
                    if ($seen_hash) {
                        break;
                    }
                }
            }
        }
        return $this->derived_handout_commit;
    }

    /** @return ?non-empty-string */
    function derived_handout_hash() {
        $c = $this->derived_handout_commit();
        return $c ? $c->hash : null;
    }

    /** @return CommitRecord */
    function base_handout_commit() {
        if ($this->pset->handout_hash
            && ($c = $this->pset->handout_commit($this->pset->handout_hash))) {
            return $c;
        } else if (($c = $this->derived_handout_commit())) {
            return $c;
        } else if (($c = $this->pset->latest_handout_commit())) {
            return $c;
        } else {
            return new CommitRecord(0, "4b825dc642cb6eb9a060e54bf8d69288fbee4904", "", CommitRecord::HANDOUTHEAD);
        }
    }

    /** @return bool */
    function is_handout_commit() {
        return $this->hash && $this->hash === $this->derived_handout_hash();
    }

    /** @return bool */
    function is_grading_commit() {
        return $this->pset->gitless_grades
            || ($this->hash !== null
                && ($rpi = $this->rpi())
                && !$rpi->placeholder
                && $rpi->gradehash === $this->hash);
    }


    /** @return ?object */
    function user_jnotes() {
        $upi = $this->upi();
        return $upi ? $upi->jnotes() : null;
    }

    /** @param non-empty-string $key */
    function user_jnote($key) {
        $un = $this->user_jnotes();
        return $un ? $un->$key ?? null : null;
    }

    /** @return ?object */
    function grade_jnotes() {
        $gpi = $this->gpi();
        return $gpi ? $gpi->jnotes() : null;
    }

    /** @param non-empty-string $key */
    function grade_jnote($key) {
        $gn = $this->grade_jnotes();
        return $gn ? $gn->$key ?? null : null;
    }

    /** @return ?object */
    function commit_jnotes() {
        assert(!$this->pset->gitless);
        $cpi = $this->cpi();
        return $cpi ? $cpi->jnotes() : null;
    }

    /** @param non-empty-string $key */
    function commit_jnote($key) {
        $cn = $this->commit_jnotes();
        return $cn ? $cn->$key ?? null : null;
    }

    /** @return ?object */
    function repository_jnotes() {
        assert(!$this->pset->gitless);
        $rpi = $this->rpi();
        return $rpi ? $rpi->jrpnotes() : null;
    }

    /** @param non-empty-string $key */
    function repository_jnote($key) {
        $rn = $this->repository_jnotes();
        return $rn ? $rn->$key ?? null : null;
    }

    /** @return ?object */
    function current_jnotes() {
        if ($this->pset->gitless_grades
            || $this->hash === null
            || ($this->_rpi && $this->_rpi->gradehash === $this->hash)) {
            return $this->grade_jnotes();
        } else {
            return $this->commit_jnotes();
        }
    }

    /** @param non-empty-string $key */
    function current_jnote($key) {
        $xn = $this->current_jnotes();
        return $xn ? $xn->$key ?? null : null;
    }

    /** @param string $file
     * @param string $lineid
     * @return LineNote */
    function current_line_note($file, $lineid) {
        $n1 = $this->current_jnotes();
        $n2 = $n1 ? $n1->linenotes ?? null : null;
        $n3 = $n2 ? $n2->$file ?? null : null;
        $ln = $n3 ? $n3->$lineid ?? null : null;
        if ($ln) {
            return LineNote::make_json($file, $lineid, $ln);
        } else {
            return new LineNote($file, $lineid);
        }
    }

    function current_grade_entry($k, $type = null) {
        $gn = $this->current_jnotes();
        $grade = null;
        if ((!$type || $type == "autograde")
            && isset($gn->autogrades)
            && property_exists($gn->autogrades, $k)) {
            $grade = $gn->autogrades->$k;
        }
        if ((!$type || $type == "grade")
            && isset($gn->grades)
            && property_exists($gn->grades, $k)) {
            $grade = $gn->grades->$k;
        }
        return $grade;
    }


    /** @param ?object $j */
    static private function clean_notes($j) {
        if (is_object($j)
            && isset($j->grades)
            && is_object($j->grades)
            && isset($j->autogrades)
            && is_object($j->autogrades)) {
            foreach ($j->autogrades as $k => $v) {
                if (($j->grades->$k ?? null) === $v)
                    unset($j->grades->$k);
            }
            if (!count(get_object_vars($j->grades))) {
                unset($j->grades);
            }
        }
    }

    /** @param ?object $j
     * @return int */
    static function notes_haslinenotes($j) {
        $x = 0;
        if ($j && isset($j->linenotes)) {
            foreach ($j->linenotes as $fn => $fnn) {
                foreach ($fnn as $ln => $n) {
                    $x |= (is_array($n) && $n[0] ? HASNOTES_COMMENT : HASNOTES_GRADE);
                }
            }
        }
        return $x;
    }

    /** @param ?object $j
     * @return int */
    static function notes_hasflags($j) {
        return $j && isset($j->flags) && count((array) $j->flags) ? 1 : 0;
    }

    /** @param ?object $j
     * @return int */
    static function notes_hasactiveflags($j) {
        if ($j && isset($j->flags)) {
            foreach ($j->flags as $f) {
                if (!($f->resolved ?? false))
                    return 1;
            }
        }
        return 0;
    }

    /** @param array $updates */
    function update_user_notes($updates) {
        // find original
        $upi = $this->upi();

        // compare-and-swap loop
        while (true) {
            // change notes
            $new_notes = json_update($upi ? $upi->jnotes() : null, $updates);
            self::clean_notes($new_notes);

            // update database
            $notes = json_encode_db($new_notes);
            $hasactiveflags = self::notes_hasactiveflags($new_notes);
            if (!$upi) {
                $result = Dbl::qx($this->conf->dblink, "insert into ContactGrade
                    set cid=?, pset=?, notes=?, hasactiveflags=?",
                    $this->user->contactId, $this->pset->id,
                    $notes, $hasactiveflags);
            } else if ($upi->notes === $notes) {
                return;
            } else {
                $result = $this->conf->qe("update ContactGrade
                    set notes=?, hasactiveflags=?, notesversion=?
                    where cid=? and pset=? and notesversion=?",
                    $notes, $hasactiveflags, $upi->notesversion + 1,
                    $this->user->contactId, $this->pset->id, $upi->notesversion);
            }
            if ($result && $result->affected_rows) {
                break;
            }

            // reload record
            $this->_havepi &= ~1;
            $upi = $this->upi();
        }

        if (!$this->_upi) {
            $this->_havepi |= 1;
            $this->_upi = UserPsetInfo::make_new($this->pset, $this->user);
        }
        $this->_upi->assign_notes($notes, $new_notes, ($upi ? $upi->notesversion : 0) + 1);
        $this->_upi->hasactiveflags = $hasactiveflags;
        $this->can_view_grades = $this->user_can_view_grades = null;
        if (isset($updates["grades"]) || isset($updates["autogrades"])) {
            $this->user->invalidate_grades($this->pset->id);
        }
    }

    /** @param array $updates */
    function update_repository_notes($updates) {
        // find original
        $rpi = $this->rpi();

        // compare-and-swap loop
        while (true) {
            // change notes
            $new_notes = json_update($rpi ? $rpi->jrpnotes() : null, $updates);
            self::clean_notes($new_notes);

            // update database
            $notes = json_encode_db($new_notes);
            if (!$rpi) {
                $result = Dbl::qe($this->conf->dblink, "insert into RepositoryGrade set
                    repoid=?, branchid=?, pset=?,
                    placeholder=1, placeholder_at=?, rpnotes=?",
                    $this->repo->repoid, $this->branchid, $this->pset->id,
                    Conf::$now, $notes);
            } else if ($rpi->rpnotes === $notes) {
                return;
            } else {
                $result = $this->conf->qe("update RepositoryGrade
                    set rpnotes=?, rpnotesversion=?
                    where repoid=? and branchid=? and pset=? and rpnotesversion=?",
                    $notes, $rpi->rpnotesversion + 1,
                    $this->repo->repoid, $this->branchid, $this->pset->id, $rpi->rpnotesversion);
            }
            if ($result && $result->affected_rows) {
                break;
            }

            // reload record
            $this->_havepi &= ~2;
            $rpi = $this->rpi();
        }

        if (!$this->_rpi) {
            $this->_havepi &= ~2;
            $this->rpi();
        }
        $this->_rpi->assign_rpnotes($notes, $new_notes, ($rpi ? $rpi->notesversion : 0) + 1);
    }

    /** @param non-empty-string $hash
     * @param array $updates */
    function update_commit_notes_at($hash, $updates) {
        assert(strlen($hash) === 40);

        // find original
        $this_commit = $this->hash === $hash
            || (!$this->hash && $this->_rpi && $this->_rpi->gradehash === $hash);
        if ($this_commit && $this->_cpi) {
            $old_notes = $this->_cpi->jnotes();
            $old_nversion = $this->_cpi->notesversion;
        } else if ($this_commit && $this->_rpi && $this->_rpi->notesversion !== null) {
            $old_notes = $this->_rpi->jnotes();
            $old_nversion = $this->_rpi->notesversion;
        } else if (($cpi = $this->pset->cpi_at($hash))) {
            $old_notes = $cpi->jnotes();
            $old_nversion = $cpi->notesversion;
        } else {
            $old_notes = $old_nversion = null;
        }
        $commit = null;

        // compare-and-swap loop
        while (true) {
            // change notes
            $new_notes = json_update($old_notes, $updates);
            self::clean_notes($new_notes);

            // update database
            $notes = json_encode_db($new_notes);
            $haslinenotes = self::notes_haslinenotes($new_notes);
            $hasflags = self::notes_hasflags($new_notes);
            $hasactiveflags = self::notes_hasactiveflags($new_notes);
            if ($old_nversion === null) {
                $commit = $commit ?? $this->connected_commit($hash);
                $result = $this->conf->qe("insert into CommitNotes set
                    pset=?, bhash=?, repoid=?,
                    notes=?, haslinenotes=?, hasflags=?, hasactiveflags=?",
                    $this->pset->id, hex2bin($hash), $this->repo->repoid,
                    $notes, $haslinenotes, $hasflags, $hasactiveflags);
            } else if ($old_notes === $notes) {
                return;
            } else {
                $result = $this->conf->qe("update CommitNotes set
                    notes=?, haslinenotes=?, hasflags=?, hasactiveflags=?,
                    notesversion=?
                    where pset=? and bhash=? and notesversion=?",
                    $notes, $haslinenotes, $hasflags, $hasactiveflags,
                    $old_nversion + 1,
                    $this->pset->id, hex2bin($hash), $old_nversion);
            }
            if ($result && $result->affected_rows) {
                break;
            }

            // reload record
            if (($cpi = $this->pset->cpi_at($hash))) {
                $old_notes = $cpi->jnotes();
                $old_nversion = $cpi->notesversion;
            } else {
                $old_notes = $old_nversion = null;
            }
        }

        if ($this_commit && !$this->_cpi) {
            $this->_havepi |= 4;
            $this->_cpi = CommitPsetInfo::make_new($this->pset, $this->repo, $this->hash);
        }
        if ($this_commit) {
            $this->_cpi->assign_notes($notes, $new_notes, ($old_nversion ?? 0) + 1);
            $this->_cpi->hasflags = $hasflags;
            $this->_cpi->hasactiveflags = $hasactiveflags;
            $this->_cpi->haslinenotes = $haslinenotes;
        }
        if ($this->_rpi && $this->_rpi->gradehash === $hash) {
            $this->_rpi->assign_notes($notes, $new_notes, ($old_nversion ?? 0) + 1);
        }
        if ((isset($updates["grades"]) || isset($updates["autogrades"]))
            && $this->grading_hash() === $hash) {
            $this->user->invalidate_grades($this->pset->id);
        }
    }

    /** @param array $updates */
    function update_commit_notes($updates) {
        assert(!!$this->hash);
        $this->update_commit_notes_at($this->hash, $updates);
    }

    /** @param array $updates */
    function update_current_notes($updates) {
        if ($this->pset->gitless) {
            $this->update_user_notes($updates);
        } else {
            $this->update_commit_notes($updates);
        }
    }

    /** @param array $updates */
    function update_grade_notes($updates) {
        if ($this->pset->gitless || $this->pset->gitless_grades) {
            $this->update_user_notes($updates);
        } else {
            $this->update_commit_notes($updates);
        }
    }

    /** @param array $updates
     * @deprecated */
    function update_commit_info($updates) {
        return $this->update_commit_notes($updates);
    }

    /** @param array $updates
     * @deprecated */
    function update_grade_info($updates) {
        return $this->update_grade_notes($updates);
    }


    function backpartners() {
        return array_unique($this->user->links(LINK_BACKPARTNER, $this->pset->id));
    }

    /** @return bool */
    function partner_same() {
        if ($this->partner_same === null) {
            $bp = $this->backpartners();
            if ($this->partner) {
                $this->partner_same = count($bp) === 1 && $this->partner->contactId === $bp[0];
            } else {
                $this->partner_same = empty($bp);
            }
        }
        return $this->partner_same;
    }

    /** @return ?non-empty-string */
    function grading_hash() {
        $rpi = $this->pset->gitless_grades ? null : $this->rpi();
        return $rpi && !$rpi->placeholder ? $rpi->gradehash : null;
    }

    /** @return ?non-empty-string */
    function update_grading_hash($update_chance = false) {
        if (!$this->pset->gitless_grades) {
            $rpi = $this->rpi();
            if ((!$rpi || $rpi->placeholder || $rpi->gradebhash === null)
                && $update_chance
                && ($update_chance === true
                    || (is_callable($update_chance) && call_user_func($update_chance, $this, $rpi ? $rpi->placeholder_at : 0))
                    || (is_float($update_chance) && rand(0, 999999999) < 1000000000 * $update_chance))) {
                $this->update_placeholder_grading_hash();
                $rpi = $this->rpi();
            }
            return $rpi ? $rpi->gradehash : null;
        } else {
            return null;
        }
    }

    /** @return void */
    private function update_placeholder_grading_hash() {
        assert(!$this->pset->gitless_grades
               && ($this->_havepi & 2) !== 0
               && (!$this->_rpi || $this->_rpi->placeholder || $this->_rpi->gradebhash === null));
        $c = $this->latest_commit();
        $bh = $c ? hex2bin($c->hash) : null;
        if (!$this->_rpi
            || ($this->_rpi->placeholder_at ?? 0) === 0
            || $this->_rpi->gradebhash !== $bh) {
            $this->conf->qe("insert into RepositoryGrade set
                repoid=?, branchid=?, pset=?,
                gradebhash=?, commitat=?, placeholder=1, placeholder_at=?
                on duplicate key update
                gradebhash=(if(placeholder=1,values(gradebhash),gradebhash)),
                commitat=(if(placeholder=1,values(commitat),commitat)),
                placeholder_at=values(placeholder_at)",
                $this->repo->repoid, $this->branchid, $this->pset->id,
                $bh, $c ? $c->commitat : null, Conf::$now);
            $this->_havepi &= ~2;
            $this->_rpi = null;
        }
    }

    /** @return ?CommitRecord */
    function grading_commit() {
        $h = $this->grading_hash();
        return $h ? $this->connected_commit($h) : null;
    }

    /** @return bool */
    private function contact_can_view_grades(Contact $user) {
        if ($user !== $this->user) {
            return $user->isPC && $user->can_view_pset($this->pset);
        } else if (!$this->pset->student_can_view()
                   || (!$this->pset->gitless_grades
                       && (!$this->repo || !$this->user_can_view_repo_contents()))) {
            return false;
        } else if ($this->pset->student_can_edit_grades()) {
            return true;
        } else if (($g = $this->gpi())) {
            return $g->hidegrade <= 0
                && ($g->hidegrade < 0
                    || $this->pset->student_can_view_grades());
        } else {
            return false;
        }
    }

    /** @return bool */
    function can_view_grades() {
        if ($this->can_view_grades === null) {
            $this->can_view_grades = $this->contact_can_view_grades($this->viewer);
        }
        return $this->can_view_grades;
    }

    /** @return bool */
    function user_can_view_grades() {
        if ($this->user_can_view_grades === null) {
            $this->user_can_view_grades = $this->contact_can_view_grades($this->user);
        }
        return $this->user_can_view_grades;
    }

    /** @return bool */
    function can_view_grade_statistics() {
        return ($this->viewer->isPC && $this->viewer !== $this->user)
            || $this->user_can_view_grade_statistics();
    }

    /** @return bool */
    function user_can_view_grade_statistics() {
        // also see API_GradeStatistics
        $gsv = $this->pset->grade_statistics_visible;
        return $gsv === 1
            || ($gsv === 2 && $this->user_can_view_grades())
            || ($gsv > 2 && $gsv <= Conf::$now);
    }

    /** @return bool */
    function can_view_grade_statistics_graph() {
        return ($this->viewer->isPC && $this->viewer !== $this->user)
            || ($this->pset->grade_cdf_cutoff < 1
                && $this->user_can_view_grade_statistics());
    }

    /** @return bool */
    function can_edit_grades_staff() {
        return $this->can_view_grades() && $this->viewer !== $this->user;
    }

    /** @return bool */
    function can_edit_grades_any() {
        return $this->can_view_grades()
            && ($this->viewer !== $this->user || $this->pset->student_can_edit_grades());
    }


    /** @return bool */
    function can_view_repo_contents($cached = false) {
        return $this->viewer->can_view_repo_contents($this->repo, $this->branch, $cached);
    }

    /** @return bool */
    function user_can_view_repo_contents($cached = false) {
        return $this->user->can_view_repo_contents($this->repo, $this->branch, $cached);
    }

    /** @return bool */
    function can_view_note_authors() {
        return $this->pc_view;
    }

    private function ensure_n_visible_grades() {
        if ($this->n_visible_grades === null) {
            $this->set_grade_counts(0);
            if ($this->can_view_grades()) {
                $notes = $this->current_jnotes();
                $ag = $notes->autogrades ?? null;
                $g = $notes->grades ?? null;
                foreach ($this->pset->visible_grades($this->pc_view) as $ge) {
                    ++$this->n_visible_grades;
                    if ($ge->student) {
                        ++$this->n_student_grades;
                    }
                    if (($ag && ($ag->{$ge->key} ?? null) !== null)
                        || ($g && ($g->{$ge->key} ?? null) !== null)) {
                        ++$this->n_nonempty_grades;
                        if (!$ge->student) {
                            ++$this->n_nonempty_assigned_grades;
                        }
                    }
                    if (!$ge->no_total) {
                        ++$this->n_visible_in_total;
                    }
                }
            }
        }
    }

    /** @return bool */
    function has_nonempty_grades() {
        $this->ensure_n_visible_grades();
        return $this->n_nonempty_grades > 0;
    }

    /** @return bool */
    function has_nonempty_assigned_grades() {
        $this->ensure_n_visible_grades();
        return $this->n_nonempty_assigned_grades !== 0;
    }

    /** @return bool */
    function needs_student_grades()  {
        $this->ensure_n_visible_grades();
        return $this->n_student_grades !== 0
            && ($this->n_nonempty_grades === 0
                || $this->n_nonempty_grades === $this->n_nonempty_assigned_grades);
    }

    /** @return bool */
    function needs_total() {
        $this->ensure_n_visible_grades();
        return $this->n_visible_in_total > 1;
    }

    /** @return array{int|float,int|float,int|float} */
    function grade_total() {
        $total = $total_noextra = $maxtotal = 0;
        $notes = $this->current_jnotes();
        $ag = $notes->autogrades ?? null;
        $g = $notes->grades ?? null;
        foreach ($this->pset->visible_grades_in_total($this->pc_view) as $ge) {
            $gv = $g ? $g->{$ge->key} ?? null : null;
            if ($gv === null && $ag) {
                $gv = $ag->{$ge->key} ?? null;
            }
            if ($gv) {
                $total += $gv;
            }
            if ($gv && !$ge->is_extra) {
                $total_noextra += $gv;
            }
            if (!$ge->is_extra && $ge->max && $ge->max_visible) {
                $maxtotal += $ge->max;
            }
        }
        return [$total, $this->pset->grades_total ?? $maxtotal, $total_noextra];
    }

    /** @return int */
    function gradercid() {
        if ($this->pset->gitless_grades) {
            $upi = $this->upi();
            return $upi ? $upi->gradercid : 0;
        } else if (($rpi = $this->rpi()) && $this->hash === $rpi->gradehash) {
            return $rpi->gradercid;
        } else {
            $cn = $this->commit_jnotes();
            return $cn ? $cn->gradercid ?? 0 : 0;
        }
    }

    /** @return bool */
    function can_edit_line_note($file, $lineid) {
        return $this->pc_view;
    }


    /** @return null|int|float */
    function deadline() {
        if (!$this->user->extension && $this->pset->deadline_college) {
            return $this->pset->deadline_college;
        } else if ($this->user->extension && $this->pset->deadline_extension) {
            return $this->pset->deadline_extension;
        } else {
            return $this->pset->deadline;
        }
    }

    /** @param bool $force
     * @return ?int */
    private function current_timestamp($force) {
        if ($this->pset->gitless) {
            return null;
        } else if ($this->hash
                   && ($rpi = $this->rpi())
                   && $rpi->gradehash === $this->hash) {
            if (!$rpi->commitat
                && ($force || self::$forced_commitat < 60)
                && $this->repo->update_info()) {
                ++self::$forced_commitat;
                $cr = $this->grading_commit();
                $rpi->commitat = $cr ? $cr->commitat : 1;
            }
            return $rpi->commitat;
        } else if ($this->hash && ($ls = $this->commit())) {
            return $ls->commitat;
        } else {
            return null;
        }
    }

    /** @param ?int $deadline
     * @param ?int $ts
     * @return ?int */
    static private function auto_late_hours($deadline, $ts) {
        if (!$deadline || ($ts ?? 0) <= 1) {
            return null;
        } else if ($deadline < $ts) {
            return (int) ceil(($ts - $deadline) / 3600);
        } else {
            return 0;
        }
    }

    function late_hours_data() {
        if (!($deadline = $this->deadline())) {
            return null;
        }

        $cn = $this->current_jnotes();
        $ts = $cn ? $cn->timestamp ?? null : null;
        $ts = $ts ?? $this->current_timestamp(true);
        $autohours = self::auto_late_hours($deadline, $ts);

        $ld = [];
        if (isset($cn->late_hours)) {
            $ld["hours"] = $cn->late_hours;
            if ($autohours !== null && $cn->late_hours !== $autohours) {
                $ld["autohours"] = $autohours;
            }
        } else if (isset($autohours)) {
            $ld["hours"] = $autohours;
        }
        if ($ts) {
            $ld["timestamp"] = $ts;
        }
        if ($deadline) {
            $ld["deadline"] = $deadline;
        }
        return !empty($ld) ? (object) $ld : null;
    }

    /** @return ?int */
    function late_hours() {
        $cn = $this->current_jnotes();
        if ($cn && isset($cn->late_hours)) {
            return $cn->late_hours;
        } else if (($lhd = $this->late_hours_data()) && isset($lhd->hours)) {
            return $lhd->hours;
        } else {
            return null;
        }
    }

    /** @return ?int */
    function fast_late_hours() {
        $cn = $this->current_jnotes();
        if ($cn && isset($cn->late_hours)) {
            return $cn->late_hours;
        } else if (($deadline = $this->deadline())) {
            $ts = $cn ? $cn->timestamp ?? null : null;
            $ts = $ts ?? $this->current_timestamp(false);
            return self::auto_late_hours($deadline, $ts);
        } else {
            return null;
        }
    }


    private function clear_grade() {
        $this->can_view_grades = $this->user_can_view_grades = null;
        if ($this->pset->gitless_grades) {
            $this->_havepi &= ~1;
            $this->_upi = null;
        } else {
            $this->_havepi &= ~2;
            $this->_rpi = null;
        }
    }

    function change_grader($grader) {
        if (is_object($grader)) {
            $grader = $grader->contactId;
        }
        if ($this->pset->gitless_grades) {
            $q = Dbl::format_query("insert into ContactGrade
                set cid=?, pset=?, gradercid=?
                on duplicate key update gradercid=values(gradercid)",
                $this->user->contactId, $this->pset->id, $grader);
        } else {
            assert(!!$this->hash);
            $rpi = $this->rpi();
            if (!$rpi || $rpi->gradebhash === null) {
                $commit = $this->hash ? $this->connected_commit($this->hash) : null;
                $q = Dbl::format_query("insert into RepositoryGrade
                    set repoid=?, branchid=?, pset=?,
                    gradebhash=?, commitat=?, gradercid=?, placeholder=0
                    on duplicate key update
                    gradebhash=values(gradebhash), commitat=values(commitat), gradercid=values(gradercid), placeholder=0",
                    $this->repo->repoid, $this->branchid, $this->pset->id,
                    $this->hash ? hex2bin($this->hash) : null,
                    $commit ? $commit->commitat : null, $grader);
            } else {
                $bhash = $this->hash ? hex2bin($this->hash) : $this->grading_hash();
                $commit = $bhash ? $this->connected_commit(bin2hex($bhash)) : null;
                $q = Dbl::format_query("update RepositoryGrade
                    set gradebhash=?, commitat=?, gradercid=?, placeholder=0
                    where repoid=? and branchid=? and pset=? and gradebhash=?",
                    $bhash, $commit ? $commit->commitat : null, $grader,
                    $this->repo->repoid, $this->branchid, $this->pset->id,
                    $rpi->gradebhash);
            }
            $this->update_commit_notes(["gradercid" => $grader]);
        }
        $this->conf->qe_raw($q);
        $this->clear_grade();
    }

    function mark_grading_commit() {
        if ($this->pset->gitless_grades) {
            $this->conf->qe("insert into ContactGrade
                set cid=?, pset=?, gradercid=?
                on duplicate key update gradercid=gradercid",
                $this->user->contactId, $this->pset->psetid, $this->viewer->contactId);
        } else {
            assert(!!$this->hash);
            $cn = $this->commit_jnotes();
            $grader = $cn ? $cn->gradercid ?? null : null;
            if (!$grader && ($rpi = $this->rpi())) {
                $grader = $rpi->gradercid;
            }
            $commit = $this->hash ? $this->connected_commit($this->hash) : null;
            $this->conf->qe("insert into RepositoryGrade set
                repoid=?, branchid=?, pset=?,
                gradebhash=?, commitat=?, gradercid=?, placeholder=0
                on duplicate key update
                gradebhash=values(gradebhash), commitat=values(commitat), gradercid=values(gradercid), placeholder=0",
                $this->repo->repoid, $this->branchid, $this->pset->psetid,
                $this->hash ? hex2bin($this->hash) : null,
                $commit ? $commit->commitat : null, $grader ? : null);
            $this->user->invalidate_grades($this->pset->id);
        }
        $this->clear_grade();
    }

    function set_hidden_grades($hidegrade) {
        if ($this->pset->gitless_grades) {
            $this->conf->qe("update ContactGrade set hidegrade=? where cid=? and pset=?", $hidegrade, $this->user->contactId, $this->pset->psetid);
        } else {
            $this->conf->qe("update RepositoryGrade set hidegrade=? where repoid=? and branchid=? and pset=?", $hidegrade, $this->repo->repoid, $this->branchid, $this->pset->psetid);
        }
        $this->clear_grade();
    }


    function runner_logfile($checkt) {
        return SiteLoader::$root . "/log/run" . $this->repo->cacheid
            . ".pset" . $this->pset->id . "/repo" . $this->repo->repoid
            . ".pset" . $this->pset->id . "." . $checkt . ".log";
    }

    function runner_output($checkt) {
        return @file_get_contents($this->runner_logfile($checkt));
    }

    function runner_output_for($runner) {
        if (is_string($runner)) {
            $runner = $this->pset->all_runners[$runner];
        }
        $cnotes = $this->commit_jnotes();
        if ($cnotes && isset($cnotes->run) && isset($cnotes->run->{$runner->name})) {
            $f = $this->runner_output($cnotes->run->{$runner->name});
            $this->last_runner_error = $f === false ? self::ERROR_LOGMISSING : 0;
            return $f;
        } else {
            $this->last_runner_error = self::ERROR_NOTRUN;
            return false;
        }
    }

    private function reset_transferred_warnings() {
        $this->transferred_warnings = [];
        $this->transferred_warnings_priority = [];
    }

    /** @param ?string $file
     * @param ?int $line
     * @param string $text
     * @param float $priority */
    private function transfer_one_warning($file, $line, $text, $priority) {
        if ($file !== null && $text !== "") {
            $loc = "$file:$line";
            if (!isset($this->transferred_warnings[$file])) {
                $this->transferred_warnings[$file] = [];
            }
            if (($this->transferred_warnings_priority[$loc] ?? $priority - 1) < $priority) {
                $this->transferred_warnings[$file][$line] = [];
                $this->transferred_warnings_priority[$loc] = $priority;
            }
            if ($this->transferred_warnings_priority[$loc] == $priority) {
                $this->transferred_warnings[$file][$line][] = $text;
            }
        }
    }

    private function transfer_warning_lines($lines, $prio) {
        $file = $line = null;
        $expect_context = false;
        $in_instantiation = 0;
        $text = "";
        $nlines = count($lines);
        for ($i = 0; $i !== $nlines; ++$i) {
            $s = $lines[$i];
            $sda = preg_replace('/\x1b\[[\d;]*m|\x1b\[\d*K/', '', $s);
            if (preg_match('/\A([^\s:]*):(\d+):(?:\d+:)?\s*(\S*)/', $sda, $m)) {
                $this_instantiation = strpos($sda, "required from") !== false;
                if ($file && $m[3] === "note:") {
                    if (strpos($sda, "in expansion of macro") !== false) {
                        $file = $m[1];
                        $line = (int) $m[2];
                    }
                } else {
                    if (!$in_instantiation) {
                        $this->transfer_one_warning($file, $line, $text, $prio);
                        $text = "";
                    }
                    if ($in_instantiation !== 2 || $this_instantiation) {
                        $file = $m[1];
                        $line = (int) $m[2];
                    }
                }
                $text .= $s . "\n";
                $expect_context = true;
                if ($in_instantiation !== 0 && $this_instantiation) {
                    $in_instantiation = 2;
                } else {
                    $in_instantiation = 0;
                }
            } else if (preg_match('/\A(?:\S|\s+[A-Z]+\s)/', $sda)) {
                if (str_starts_with($sda, "In file included")) {
                    $text .= $s . "\n";
                    while ($i + 1 < $nlines && str_starts_with($lines[$i + 1], " ")) {
                        ++$i;
                        $text .= $lines[$i] . "\n";
                    }
                    $in_instantiation = 1;
                } else if (strpos($sda, "In instantiation of")) {
                    if (!$in_instantiation) {
                        $this->transfer_one_warning($file, $line, $text, $prio);
                        $file = $line = null;
                        $text = "";
                    }
                    $text .= $s . "\n";
                    $in_instantiation = 1;
                } else if ($expect_context
                           && $i + 1 < $nlines
                           && strpos($lines[$i + 1], "^") !== false) {
                    $text .= $s . "\n" . $lines[$i + 1] . "\n";
                    ++$i;
                    $in_instantiation = 0;
                } else {
                    $this->transfer_one_warning($file, $line, $text, $prio);
                    $file = $line = null;
                    $text = "";
                    $in_instantiation = 0;
                }
                $expect_context = false;
            } else if ($file !== null) {
                $text .= $s . "\n";
                $expect_context = false;
            }
        }
        $this->transfer_one_warning($file, $line, $text, $prio);
    }

    private function transfer_warnings() {
        $this->reset_transferred_warnings();

        // collect warnings from runner output
        foreach ($this->pset->runners as $runner) {
            if ($runner->transfer_warnings
                && $this->viewer->can_view_transferred_warnings($this->pset, $runner, $this->user)
                && ($output = $this->runner_output_for($runner))) {
                $this->transfer_warning_lines(explode("\n", $output), $runner->transfer_warnings_priority ?? 0.0);
            }
        }

        // squeeze out redundant warnings
        foreach ($this->transferred_warnings as $file => &$linemap) {
            foreach ($linemap as $line => &$wlist) {
                $wmap = [];
                $wtext = "";
                $nw = count($wlist);
                for ($i = 0; $i !== $nw; ++$i) {
                    $w = $wlist[$i];
                    if ($w[0] !== " " && isset($wmap[$w])) {
                        $j = $wmap[$w];
                        while ($i + 1 !== $nw && $wlist[$i + 1] === $wlist[$j + 1]) {
                            ++$i;
                            ++$j;
                        }
                    } else {
                        $wmap[$w] = $i;
                        $wtext .= $w;
                    }
                }
                $wlist = $wtext;
            }
            unset($wlist);
        }
        unset($linemap);
    }

    /** @param string $file
     * @return array<int,list<string>> */
    function transferred_warnings_for($file) {
        if ($this->transferred_warnings === null) {
            $this->transfer_warnings();
        }
        if (isset($this->transferred_warnings[$file])) {
            return $this->transferred_warnings[$file];
        }
        $slash = strrpos($file, "/");
        if ($slash !== false
            && isset($this->transferred_warnings[substr($file, $slash + 1)])) {
            return $this->transferred_warnings[substr($file, $slash + 1)];
        } else {
            return [];
        }
    }


    /** @param ?array<string,null|int|string> $args
     * @return array<string,null|int|string> */
    function hoturl_args($args = null) {
        $xargs = ["pset" => $this->pset->urlkey,
                  "u" => $this->viewer->user_linkpart($this->user)];
        if ($this->hash) {
            $xargs["commit"] = $this->commit_hash();
        }
        foreach ($args ?? [] as $k => $v) {
            $xargs[$k] = $v;
        }
        return $xargs;
    }

    /** @param string $base
     * @param ?array<string,null|int|string> $args
     * @return string */
    function hoturl($base, $args = null) {
        return $this->conf->hoturl($base, $this->hoturl_args($args));
    }

    /** @param string $base
     * @param ?array<string,null|int|string> $args
     * @return string */
    function hoturl_post($base, $args = null) {
        return $this->conf->hoturl_post($base, $this->hoturl_args($args));
    }


    function ensure_formula() {
        if ($this->pset->has_formula) {
            $notes = $this->current_jnotes();
            $t = max($this->user->gradeUpdateTime, $this->pset->config_mtime);
            if (!isset($notes->formula_at)
                || $notes->formula_at !== $t) {
                $u = ["formula_at" => $t, "formula" => []];
                foreach ($this->pset->grades() as $ge) {
                    if (($f = $ge->formula($this->conf))) {
                        $u["formula"][$ge->key] = $f->evaluate($this->user);
                    }
                }
                $this->update_current_notes($u);
            }
        }
    }

    const GRADEJSON_NO_ENTRIES = 1;
    const GRADEJSON_OVERRIDE_VIEW = 2;
    const GRADEJSON_NO_LATE_HOURS = 4;

    /** @param int $flags
     * @return ?array */
    function grade_json($flags = 0) {
        $override_view = ($flags & self::GRADEJSON_OVERRIDE_VIEW) !== 0;
        if (!$override_view && !$this->can_view_grades()) {
            return null;
        }
        $this->ensure_formula();
        $pc_view = $override_view || $this->pc_view;

        $gexp = new GradeExport($this->pset, $pc_view);
        $gexp->uid = $this->user->contactId;
        $gexp->include_entries = ($flags & self::GRADEJSON_NO_ENTRIES) === 0;

        $notes = $this->current_jnotes();
        $agx = $notes->autogrades ?? null;
        $gx = $notes->grades ?? null;
        $fgx = $this->pset->has_formula ? $notes->formula ?? null : null;
        if ($agx || $gx || $this->is_grading_commit()) {
            $g = $ag = [];
            $total = $total_noextra = 0;
            foreach ($this->pset->visible_grades($pc_view) as $ge) {
                $key = $ge->key;
                $gv = null;
                if ($agx) {
                    $gv = property_exists($agx, $key) ? $agx->$key : null;
                    $ag[] = $gv;
                }
                if ($gx && property_exists($gx, $key)) {
                    $gv = $gx->$key;
                    if ($gx->$key === false)
                        $gv = null;
                }
                if ($ge->formula && property_exists($fgx, $key)) {
                    $gv = $fgx->$key;
                }
                $g[] = $gv;
                if (!$ge->no_total && $gv) {
                    $total += $gv;
                    if (!$ge->is_extra) {
                        $total_noextra += $gv;
                    }
                }
            }
            $gexp->grades = $g;
            if ($pc_view && !empty($ag)) {
                $gexp->autogrades = $ag;
            }
            $gexp->total = round_grade($total);
            if ($total != $total_noextra) {
                $gexp->total_noextra = round_grade($total_noextra);
            }
        }
        if (!$this->pset->gitless_grades && !$this->is_grading_commit()) {
            $gexp->grading_hash = $this->grading_hash();
        }
        if (($flags & self::GRADEJSON_NO_LATE_HOURS) === 0
            && ($lhd = $this->late_hours_data())) {
            if (isset($lhd->hours)) {
                $gexp->late_hours = $lhd->hours;
            }
            if (isset($lhd->autohours) && $lhd->autohours !== $lhd->hours) {
                $gexp->auto_late_hours = $lhd->autohours;
            }
        }
        if ($this->can_edit_grades_staff()) {
            $gexp->editable = true;
        }
        // maybe hide extra-credits that are missing
        if (!$pc_view) {
            $gexp->strip_absent_extra();
        }
        return $gexp->jsonSerialize();
    }


    /** @return LineNotesOrder */
    function viewable_line_notes() {
        if ($this->viewer->can_view_comments($this->pset)) {
            return new LineNotesOrder($this->commit_jnote("linenotes"), $this->can_view_grades(), $this->pc_view);
        } else {
            return $this->empty_line_notes();
        }
    }

    /** @return LineNotesOrder */
    function empty_line_notes() {
        return new LineNotesOrder(null, $this->can_view_grades(), $this->pc_view);
    }

    /** @param float $prio */
    private function _add_diffconfigs($diffs, $prio) {
        if (is_object($diffs)) {
            foreach (get_object_vars($diffs) as $k => $v) {
                $this->pset->add_diffconfig(new DiffConfig($v, $k, $prio));
            }
        } else if (is_array($diffs)) {
            foreach ($diffs as $v) {
                $this->pset->add_diffconfig(new DiffConfig($v, null, $prio));
            }
        }
    }

    /** @param ?CommitRecord $commita
     * @param ?CommitRecord $commitb
     * @return array<string,DiffInfo> */
    function diff($commita, $commitb, LineNotesOrder $lnorder = null, $args = []) {
        if (!$this->added_diffinfo) {
            if (($rs = $this->commit_jnote("runsettings"))
                && ($id = $rs->IGNOREDIFF ?? null)) {
                $this->pset->add_diffconfig(new DiffConfig((object) ["ignore" => true], $id, 11.0));
            }
            if (($tw = $this->commit_jnote("tabwidth"))) {
                $this->pset->add_diffconfig(new DiffConfig((object) ["tabwidth" => $tw], ".*", 11.0));
            }
            if (($diffs = $this->repository_jnote("diffs"))) {
                $this->_add_diffconfigs($diffs, 10.0);
            }
            if (($diffs = $this->commit_jnote("diffs"))) {
                $this->_add_diffconfigs($diffs, 11.0);
            }
            $this->added_diffinfo = true;
        }
        // both repos must be in the same directory; assume handout
        // is only potential problem
        if ($this->pset->is_handout($commita) !== $this->pset->is_handout($commitb)) {
            $this->conf->handout_repo($this->pset, $this->repo);
        }

        assert(!isset($args["needfiles"]));
        if ($lnorder) {
            $args["needfiles"] = $lnorder->fileorder();
        }
        $diff = $this->repo->diff($this->pset, $commita, $commitb, $args);

        // expand diff to include all grade landmarks
        if ($this->pset->has_grade_landmark
            && $this->pc_view) {
            foreach ($this->pset->grades() as $g) {
                if ($g->landmark_file
                    && ($di = $diff[$g->landmark_file] ?? null)
                    && !$di->contains_linea($g->landmark_line)
                    && $di->is_handout_commit_a()) {
                    $di->expand_linea($g->landmark_line - 2, $g->landmark_line + 3);
                }
                if ($g->landmark_range_file
                    && ($di = $diff[$g->landmark_range_file] ?? null)
                    && $di->is_handout_commit_a()) {
                    $di->expand_linea($g->landmark_range_first, $g->landmark_range_last);
                }
            }
        }

        if ($lnorder) {
            $onlyfiles = Repository::fix_diff_files($args["onlyfiles"] ?? null);
            foreach ($lnorder->fileorder() as $fn => $order) {
                if (isset($diff[$fn])) {
                    // expand diff to include notes
                    $di = $diff[$fn];
                    foreach ($lnorder->file($fn) as $lineid => $note) {
                        if (!$di->contains_lineid($lineid)) {
                            $l = (int) substr($lineid, 1);
                            $di->expand_line($lineid[0], $l - 2, $l + 3);
                        }
                    }
                } else {
                    // expand diff to include fake files
                    if (($diffc = $this->pset->find_diffconfig($fn))
                        && $diffc->fileless
                        && (!$onlyfiles || ($onlyfiles[$fn] ?? null))) {
                        $diff[$fn] = $diffi = new DiffInfo($fn, $diffc);
                        foreach ($lnorder->file($fn) as $note) {
                            $diffi->add("Z", null, (int) substr($note->lineid, 1), "");
                        }
                        uasort($diff, "DiffInfo::compare");
                    }
                }
            }

            // add diff to linenotes
            $lnorder->set_diff($diff);
        }

        return $diff;
    }

    private function diff_line_code($t) {
        while (($p = strpos($t, "\t")) !== false) {
            $t = substr($t, 0, $p)
                . str_repeat(" ", $this->_diff_tabwidth - ($p % $this->_diff_tabwidth))
                . substr($t, $p + 1);
        }
        return htmlspecialchars($t);
    }

    /** @param string $file
     * @return string */
    function rawfile($file) {
        if ($this->repo->truncated_psetdir($this->pset)
            && str_starts_with($file, $this->pset->directory_slash)) {
            return substr($file, strlen($this->pset->directory_slash));
        } else {
            return $file;
        }
    }

    /** @param string $file
     * @param array $args */
    function echo_file_diff($file, DiffInfo $dinfo, LineNotesOrder $lnorder, $args) {
        if (($dinfo->hide_if_anonymous && $this->user->is_anonymous)
            || ($dinfo->is_empty() && $dinfo->loaded)) {
            return;
        }

        $this->_diff_tabwidth = $dinfo->tabwidth;
        $this->_diff_lnorder = $lnorder;
        $open = !!($args["open"] ?? false);
        $only_content = !!($args["only_content"] ?? false);
        $no_heading = ($args["no_heading"] ?? false) || $only_content;
        $id_by_user = !!($args["id_by_user"] ?? false);
        $no_grades = ($args["only_diff"] ?? false) || $only_content;
        $hide_left = ($args["hide_left"] ?? false) && !$only_content && !$dinfo->removed;

        $fileid = html_id_encode($file);
        if ($id_by_user) {
            $fileid = html_id_encode($this->user->username) . "-" . $fileid;
        }
        $tabid = "F_" . $fileid;
        $linenotes = $lnorder->file($file);
        if ($this->can_view_note_authors()) {
            $this->conf->stash_hotcrp_pc($this->viewer);
        }
        $lineanno = [];
        $has_grade_range = false;
        if ($this->pset->has_grade_landmark
            && $this->pc_view
            && !$this->is_handout_commit()
            && $dinfo->is_handout_commit_a()
            && !$no_grades) {
            $rangeg = [];
            foreach ($this->pset->grades() as $g) {
                if ($g->landmark_range_file === $file) {
                    $rangeg[] = $g;
                }
                if ($g->landmark_file === $file) {
                    $la = PsetViewLineAnno::ensure($lineanno, "a" . $g->landmark_line);
                    $la->grade_entries[] = $g;
                }
            }
            if (!empty($rangeg)) {
                uasort($rangeg, function ($a, $b) {
                    if ($a->landmark_range_first < $b->landmark_range_last) {
                        return -1;
                    } else {
                        return $a->landmark_range_first == $b->landmark_range_last ? 0 : 1;
                    }
                });
                for ($i = 0; $i !== count($rangeg); ) {
                    $first = $rangeg[$i]->landmark_range_first;
                    $last = $rangeg[$i]->landmark_range_last;
                    for ($j = $i + 1;
                         $j !== count($rangeg) && $rangeg[$j]->landmark_range_first < $last;
                         ++$j) {
                        $last = max($last, $rangeg[$j]->landmark_range_last);
                    }
                    $la1 = PsetViewLineAnno::ensure($lineanno, "a" . $first);
                    $la2 = PsetViewLineAnno::ensure($lineanno, "a" . ($last + 1));
                    foreach ($this->pset->grades() as $g) {
                        if ($g->landmark_range_file === $file
                            && $g->landmark_range_first >= $first
                            && $g->landmark_range_last <= $last) {
                            $la1->grade_first[] = $g;
                            $la2->grade_last[] = $g;
                        }
                    }
                    $i = $j;
                }
                $has_grade_range = true;
            }
        }
        if ($this->pset->has_transfer_warnings
            && !$this->is_handout_commit()) {
            foreach ($this->transferred_warnings_for($file) as $lineno => $w) {
                $la = PsetViewLineAnno::ensure($lineanno, "b" . $lineno);
                $la->warnings = $w;
                if (!$only_content) {
                    $this->need_format = true;
                }
            }
        }

        if (!$no_heading) {
            echo '<div class="pa-dg pa-with-fixed">',
                '<h3 class="pa-fileref" data-pa-fileid="', $tabid, '"><a class="qq ui pa-diff-unfold" href=""><span class="foldarrow">',
                ($open ? "&#x25BC;" : "&#x25B6;"),
                "</span>";
            if ($args["diffcontext"] ?? false) {
                echo '<span class="pa-fileref-context">', $args["diffcontext"], '</span>';
            }
            echo htmlspecialchars($dinfo->title ? : $file), "</a>";
            $bts = [];
            $bts[] = '<a href="" class="ui pa-diff-toggle-hide-left btn'
                . ($hide_left ? "" : " btn-primary")
                . ' need-tooltip" aria-label="Toggle diff view">±</a>';
            if (!$dinfo->removed && $dinfo->markdown_allowed) {
                $bts[] = '<button class="btn ui pa-diff-toggle-markdown need-tooltip'
                    . ($dinfo->markdown ? " btn-primary" : "")
                    . '" aria-label="Toggle Markdown"><span class="icon-markdown"></span></button>';
            }
            if (!$dinfo->fileless && !$dinfo->removed) {
                $bts[] = '<a href="' . $this->hoturl("raw", ["file" => $this->rawfile($file)]) . '" class="btn need-tooltip" aria-label="Download"><span class="icon-download"></span></a>';
            }
            if (!empty($bts)) {
                echo '<div class="hdr-actions btnbox">', join("", $bts), '</div>';
            }
            echo '</h3>';
        }

        echo '<div id="', $tabid, '" class="pa-filediff pa-dg need-pa-observe-diff';
        if ($hide_left) {
            echo " pa-hide-left";
        }
        if ($this->pc_view) {
            echo " uim pa-editablenotes live";
        }
        if ($this->viewer->email === "gtanzer@college.harvard.edu") {
            echo " garrett";
        }
        if (!$this->user_can_view_grades()) {
            echo " hidegrades";
        }
        if (!$open) {
            echo " hidden";
        }
        if (!$dinfo->loaded) {
            echo " need-load";
        } else {
            $maxline = max(1000, $dinfo->max_lineno()) - 1;
            echo " pa-line-digits-", ceil(log10($maxline));
        }
        if ($dinfo->highlight) {
            echo " need-highlight";
        }
        echo '"';

        if ($id_by_user) {
            echo ' data-pa-file-user="', htmlspecialchars($this->user->username), '"';
        }
        echo ' data-pa-file="', htmlspecialchars($file), '"';
        if ($this->conf->default_format) {
            echo ' data-default-format="', $this->conf->default_format, '"';
        }
        if ($dinfo->language) {
            echo ' data-language="', htmlspecialchars($dinfo->language), '"';
        }
        echo ">"; // end div#F_...
        if ($has_grade_range) {
            echo '<div class="pa-dg pa-with-sidebar"><div class="pa-sidebar">',
                '</div><div class="pa-dg">';
        }
        $curanno = new PsetViewAnnoState($file, $fileid);
        foreach ($dinfo as $l) {
            $this->echo_line_diff($l, $linenotes, $lineanno, $curanno, $dinfo);
        }
        if ($has_grade_range) {
            echo '</div></div>'; // end div.pa-dg div.pa-dg.pa-with-sidebar
        }
        if (preg_match('/\.(?:png|jpg|jpeg|gif)\z/i', $file)) {
            echo '<img src="', $this->hoturl("raw", ["file" => $this->rawfile($file)]), '" alt="', htmlspecialchars("[{$file}]"), '" loading="lazy" class="pa-dr ui-error js-hide-error">';
        }
        echo '</div>'; // end div.pa-filediff#F_...
        if (!$no_heading) {
            echo '</div>'; // end div.pa-dg.pa-with-fixed
        }
        echo "\n";
        if (!$only_content && $this->need_format) {
            echo "<script>\$pa.render_text_page()</script>\n";
            $this->need_format = false;
        }
        if (!$only_content && $dinfo->markdown) {
            echo '<script>$pa.filediff_markdown.call(document.getElementById("', $tabid, '"))</script>';
        }
    }

    /** @param array{string,?int,?int,string,?int} $l
     * @param array<string,LineNote> $linenotes
     * @param DiffInfo $dinfo */
    private function echo_line_diff($l, $linenotes, $lineanno, $curanno, $dinfo) {
        if ($l[0] === "@") {
            $cl = " pa-gx ui";
            if (($r = $dinfo->current_expandmark())) {
                $cl .= "\" data-expandmark=\"$r";
            }
            $cx = strlen($l[3]) > 76 ? substr($l[3], 0, 76) . "..." : $l[3];
            $x = [$cl, "pa-dcx", "", "", $cx];
        } else if ($l[0] === " ") {
            $x = [" pa-gc", "pa-dd", $l[1], $l[2], $l[3]];
        } else if ($l[0] === "-") {
            $x = [" pa-gd", "pa-dd", $l[1], "", $l[3]];
        } else if ($l[0] === "+") {
            $x = [" pa-gi", "pa-dd", "", $l[2], $l[3]];
        } else {
            $x = [null, null, "", $l[2], $l[3]];
        }

        $aln = $x[2] ? "a" . $x[2] : "";
        $bln = $x[3] ? "b" . $x[3] : "";
        $ala = $aln && isset($lineanno[$aln]) ? $lineanno[$aln] : null;

        if ($ala && ($ala->grade_first || $ala->grade_last)) {
            $end_grade_range = $ala->grade_last && $curanno->grade_first;
            $start_grade_range = $ala->grade_first
                && (!$curanno->grade_first || $end_grade_range);
            if ($start_grade_range || $end_grade_range) {
                echo '</div></div>';
                $curanno->grade_first = null;
            }
            if ($start_grade_range) {
                $curanno->grade_first = $ala->grade_first;
                echo '<div class="pa-dg pa-with-sidebar pa-grade-range-block"><div class="pa-sidebar"><div class="pa-gradebox pa-ps">';
                foreach ($curanno->grade_first as $g) {
                    echo '<div class="need-pa-grade" data-pa-grade="', $g->key, '"';
                    if ($g->landmark_buttons) {
                        echo ' data-pa-landmark-buttons="', htmlspecialchars(json_encode_browser($g->landmark_buttons)), '"';
                    }
                    echo '></div>';
                    $this->viewed_gradeentries[$g->key] = true;
                }
                echo '</div></div><div class="pa-dg">';
            } else if ($end_grade_range) {
                echo '<div class="pa-dg pa-with-sidebar"><div class="pa-sidebar"></div><div class="pa-dg">';
            }
        }

        $ak = $bk = "";
        if ($linenotes && $aln && isset($linenotes[$aln])) {
            $ak = ' id="L' . $aln . '_' . $curanno->fileid . '"';
        }
        if ($linenotes && $bln && isset($linenotes[$bln])) {
            $bk = ' id="L' . $bln . '_' . $curanno->fileid . '"';
        }

        if (!$x[2] && !$x[3]) {
            $x[2] = $x[3] = "...";
        }
        if ($x[2]) {
            $ak .= ' data-landmark="' . $x[2] . '"';
        }
        if ($x[3]) {
            $bk .= ' data-landmark="' . $x[3] . '"';
        }

        $nx = null;
        if ($linenotes) {
            if ($bln && isset($linenotes[$bln])) {
                $nx = $linenotes[$bln];
            } else if ($aln && isset($linenotes[$aln])) {
                $nx = $linenotes[$aln];
            }
        }

        if ($x[0]) {
            echo '<div class="pa-dl', $x[0], '">',
                '<div class="pa-da"', $ak, '></div>',
                '<div class="pa-db"', $bk, '></div>',
                '<div class="', $x[1];
            if (isset($l[4]) && ($l[4] & DiffInfo::LINE_NONL)) {
                echo ' pa-dnonl';
            }
            echo '">', $this->diff_line_code($x[4]), "</div></div>\n";
        }

        if ($bln && isset($lineanno[$bln]) && $lineanno[$bln]->warnings !== null) {
            echo '<div class="pa-dl pa-gn" data-landmark="', $bln, '"><div class="pa-warnbox"><div class="pa-warncontent need-format" data-format="2">', htmlspecialchars(join("", $lineanno[$bln]->warnings)), '</div></div></div>';
        }

        if ($ala) {
            foreach ($ala->grade_entries ?? [] as $g) {
                echo '<div class="pa-dl pa-gn';
                if ($curanno->grade_first && in_array($g, $curanno->grade_first)) {
                    echo ' pa-no-sidebar';
                }
                echo '" data-landmark="', $aln, '"><div class="pa-graderow">',
                    '<div class="pa-gradebox need-pa-grade" data-pa-grade="', $g->key, '"';
                if ($g->landmark_file === $g->landmark_range_file
                    && $g->landmark_buttons) {
                    echo ' data-pa-landmark-buttons="', htmlspecialchars(json_encode_browser($g->landmark_buttons)), '"';
                }
                echo '></div></div></div>';
                $this->viewed_gradeentries[$g->key] = true;
            }
        }

        if ($nx) {
            $this->echo_linenote($nx);
        }
    }

    private function echo_linenote(LineNote $note) {
        echo '<div class="pa-dl pa-gw'; /* NB script depends on this class exactly */
        if ((string) $note->text === "") {
            echo ' hidden';
        }
        echo '" data-landmark="', $note->lineid,
            '" data-pa-note="', htmlspecialchars(json_encode_browser($note->render_json($this->can_view_note_authors()))),
            '"><div class="pa-notebox">';
        if ((string) $note->text === "") {
            echo '</div></div>';
            return;
        }
        echo '<div class="pa-notecontent">';
        $links = array();
        $nnote = $this->_diff_lnorder->get_next($note->file, $note->lineid);
        if ($nnote) {
            $links[] = '<a href="#L' . $nnote->lineid . '_'
                . html_id_encode($nnote->file) . '">Next &gt;</a>';
        } else {
            $links[] = '<a href="#">Top</a>';
        }
        if (!empty($links)) {
            echo '<div class="pa-note-links">',
                join("&nbsp;&nbsp;&nbsp;", $links) , '</div>';
        }
        if ($this->can_view_note_authors() && !empty($note->users)) {
            $pcmembers = $this->conf->pc_members_and_admins();
            $autext = [];
            foreach ($note->users as $au) {
                if (($p = $pcmembers[$au] ?? null)) {
                    if ($p->nicknameAmbiguous)
                        $autext[] = Text::name_html($p);
                    else
                        $autext[] = htmlspecialchars($p->nickname ? : $p->firstName);
                }
            }
            if (!empty($autext)) {
                echo '<div class="pa-note-author">[', join(", ", $autext), ']</div>';
            }
        }
        echo '<div class="pa-note', ($note->iscomment ? ' pa-commentnote' : ' pa-gradenote');
        if ($note->format) {
            echo ' need-format" data-format="', $note->format;
            $this->need_format = true;
        } else {
            echo ' format0';
        }
        echo '">', htmlspecialchars($note->text), '</div>';
        echo '</div></div></div>';
    }

    static function echo_pa_sidebar_gradelist() {
        echo '<div class="pa-dg pa-with-sidebar"><div class="pa-sidebar">',
            '<div class="pa-gradebox pa-ps need-pa-gradelist"></div>',
            '</div><div class="pa-dg">';
    }
    static function echo_close_pa_sidebar_gradelist() {
        echo '</div></div>';
    }
}

class PsetViewLineAnno {
    /** @var ?list<GradeEntryConfig> */
    public $grade_entries;
    /** @var ?list<GradeEntryConfig> */
    public $grade_first;
    /** @var ?list<GradeEntryConfig> */
    public $grade_last;
    /** @var ?list<string> */
    public $warnings;

    /** @param array<string,PsetViewLineAnno> &$lineanno
     * @param string $lineid
     * @return PsetViewLineAnno */
    static function ensure(&$lineanno, $lineid) {
        if (!isset($lineanno[$lineid])) {
            $lineanno[$lineid] = new PsetViewLineAnno;
        }
        return $lineanno[$lineid];
    }
}

class PsetViewAnnoState {
    /** @var string */
    public $file;
    /** @var string */
    public $fileid;
    /** @var ?list<GradeEntryConfig> */
    public $grade_first;

    /** @param string $file
     * @param string $fileid */
    function __construct($file, $fileid) {
        $this->file = $file;
        $this->fileid = $fileid;
    }
}

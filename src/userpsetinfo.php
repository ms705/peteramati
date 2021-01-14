<?php
// userpsetinfo.php -- Peteramati helper class representing user/pset
// Peteramati is Copyright (c) 2013-2019 Eddie Kohler
// See LICENSE for open-source distribution terms

class UserPsetInfo {
    /** @var int */
    public $cid;
    /** @var int */
    public $pset;
    /** @var ?int */
    public $gradercid;
    /** @var ?int */
    public $updateat;
    /** @var ?int */
    public $updateby;
    /** @var ?int */
    public $studentupdateat;
    /** @var int */
    public $hidegrade;
    /** @var ?string */
    public $notes;
    /** @var ?string */
    private $notesOverflow;
    /** @var ?object */
    private $jnotes;
    /** @var ?string */
    public $xnotes;
    /** @var ?string */
    private $xnotesOverflow;
    /** @var ?object */
    private $jxnotes;
    /** @var int */
    public $notesversion;
    /** @var int */
    public $hasactiveflags;
    /** @var ?list<UserPsetHistory> */
    private $_history;
    /** @var ?int */
    private $_history_v0;
    /** @var ?UserPsetInfo */
    public $sset_next;

    private function merge() {
        $this->cid = (int) $this->cid;
        $this->pset = (int) $this->pset;
        $this->notesversion = (int) $this->notesversion;
        if (isset($this->updateat)) {
            $this->updateat = (int) $this->updateat;
        }
        if (isset($this->updateby)) {
            $this->updateby = (int) $this->updateby;
        }
        if (isset($this->studentupdateat)) {
            $this->studentupdateat = (int) $this->studentupdateat;
        }
        if (isset($this->gradercid)) {
            $this->gradercid = (int) $this->gradercid;
        }
        $this->hidegrade = (int) $this->hidegrade;
        $this->hasactiveflags = (int) $this->hasactiveflags;
        $this->notes = $this->notesOverflow ?? $this->notes;
        $this->notesOverflow = null;
        $this->xnotes = $this->xnotesOverflow ?? $this->xnotes;
        $this->xnotesOverflow = null;
    }

    /** @return ?UserPsetInfo */
    static function fetch($result) {
        $upi = $result->fetch_object("UserPsetInfo");
        if ($upi) {
            $upi->merge();
        }
        return $upi;
    }

    /** @return UserPsetInfo */
    static function make_new(Pset $pset, Contact $user) {
        $upi = new UserPsetInfo;
        $upi->cid = $user->contactId;
        $upi->pset = $pset->id;
        $upi->notesversion = 0;
        $upi->hidegrade = 0;
        $upi->hasactiveflags = 0;
        return $upi;
    }


    /** @return ?object */
    function jnotes() {
        if ($this->jnotes === null && $this->notes !== null) {
            $this->jnotes = json_decode($this->notes);
        }
        return $this->jnotes;
    }

    /** @param string $key
     * @return mixed */
    function jnote($key) {
        $jn = $this->jnotes();
        return $jn ? $jn->$key ?? null : null;
    }

    /** @param ?string $notes
     * @param ?object $jnotes
     * @param int $notesversion */
    function assign_notes($notes, $jnotes, $notesversion) {
        $this->notes = $notes;
        $this->jnotes = $jnotes;
        $this->notesversion = $notesversion;
    }


    /** @param int $version */
    function jnotes_as_of($version, Conf $conf) {
        if ($version > $this->notesversion) {
            return null;
        }
        if ($this->_history === null || $this->_history_v0 < $version) {
            $v0 = $version - ($version & 7);
            $v1 = $this->_history_v0 ?? PHP_INT_MAX;
            $result = $conf->qe("select * from ContactGradeHistory where cid=? and pset=? and notesversion>=? and notesversion<? order by notesversion asc", $this->cid, $this->pset, $v0, $v1);
            $hs = array_fill(0, $this->notesversion - $v0 + 1, null);
            foreach ($this->_history ?? [] as $h) {
                if ($h)
                    $hs[$h->notesversion - $v0] = $h;
            }
            while (($h = UserPsetHistory::fetch($result))) {
                $hs[$h->notesversion - $v0] = $h;
            }
            $this->_history = $hs;
            $this->_history_v0 = $v0;
        }
        $h = $this->_history[$version - $this->_history_v0] ?? null;
        if ($h && !isset($h->computed_jnotes)) {
            $v1 = $version + 1;
            while ($v1 < $this->notesversion) {
                $hx = $this->_history[$v1 - $this->_history_v0] ?? null;
                if (!$hx) {
                    return null;
                } else if (isset($hx->computed_jnotes)) {
                    break;
                }
            }
            if ($v1 === $this->notesversion) {
                $jnotes = $this->jnotes();
            } else {
                $jnotes = $this->_history[$v1 - $this->_history_v0]->jnotes();
            }
            for (--$v1; $v1 >= $version; --$v1) {
                $jnotes = $this->_history[$v1 - $this->_history_v0]->apply_reverse($jnotes);
            }
            $h->computed_jnotes = $jnotes;
        }
        return $h ? $h->computed_jnotes : null;
    }


    /** @return ?object */
    function jxnotes() {
        if ($this->jxnotes === null && $this->xnotes !== null) {
            $this->jxnotes = json_decode($this->xnotes);
        }
        return $this->jxnotes;
    }

    /** @param string $key
     * @return mixed */
    function jxnote($key) {
        $jn = $this->jxnotes();
        return $jn ? $jn->$key ?? null : null;
    }

    /** @param ?string $xnotes
     * @param ?object $jxnotes */
    function assign_xnotes($xnotes, $jxnotes) {
        $this->xnotes = $xnotes;
        $this->jxnotes = $jxnotes;
    }
}

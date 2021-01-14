<?php
// linenotesorder.php -- Peteramati helper class for linenotes
// Peteramati is Copyright (c) 2006-2019 Eddie Kohler
// See LICENSE for open-source distribution terms

class LineNotesOrder {
    /** @var array<string,array<string,LineNote>> */
    private $ln = [];
    /** @var list<LineNote> */
    private $lnseq = [];
    /** @var array<string,int> */
    private $fileorder = [];
    /** @var ?array<string,DiffInfo> */
    private $diff = [];
    /** @var ?array<string,int> */
    private $lnorder;
    /** @var ?array<string,array<string,int>> */
    private $totalorder;
    public $has_linenotes_in_diff = false;

    function __construct($linenotes, $seegradenotes, $editnotes) {
        foreach ($linenotes ? : [] as $file => $notelist) {
            $fln = [];
            foreach ($notelist as $lineid => $note) {
                if ((is_string($note) && $note !== "")
                    || (is_array($note)
                        && ($note[0] || $seegradenotes)
                        && ((string) $note[1] !== "" || $editnotes))
                    || (is_int($note) && $editnotes)) {
                    $note = LineNote::make_json($file, $lineid, $note);
                    $fln[$lineid] = $note;
                    $this->lnseq[] = $note;
                }
            }
            if (!empty($fln)) {
                $this->ln[$file] = $fln;
                $this->fileorder[$file] = count($this->fileorder) + 1;
            }
        }
    }

    /** @return bool */
    function is_empty() {
        return empty($this->lnseq);
    }
    /** @return array<string,int> */
    function fileorder() {
        return $this->fileorder;
    }
    /** @param string $file
     * @return bool */
    function file_has_notes($file) {
        return isset($this->ln[$file]);
    }
    /** @param string $file
     * @return array<string,LineNote> */
    function file($file) {
        return $this->ln[$file] ?? [];
    }

    /** @param array<string,DiffInfo> $diff */
    function set_diff($diff) {
        $this->diff = $diff;
        $this->lnorder = null;

        $this->has_linenotes_in_diff = false;
        foreach ($this->diff as $file => $di) {
            if ($this->file_has_notes($file)) {
                $this->has_linenotes_in_diff = true;
                break;
            }
        }

        $old_fileorder = $this->fileorder;
        $this->fileorder = [];
        foreach ($this->diff as $file => $di) {
            $this->fileorder[$file] = count($this->fileorder) + 1;
        }
        foreach ($old_fileorder as $file => $x) {
            // Normally every file with notes will be present
            // already, but just in case---for example, the
            // handout repo got corrupted...
            if (!isset($this->fileorder[$file]))
                $this->fileorder[$file] = count($this->fileorder) + 1;
        }
    }
    /** @return array<string,int> */
    private function ensure_lnorder() {
        if ($this->lnorder === null) {
            $this->lnorder = $this->totalorder = [];
            usort($this->lnseq, [$this, "compare"]);
            foreach ($this->lnseq as $i => $note) {
                $this->lnorder[$note->lineid . "_" . $note->file] = $i;
            }
        }
        return $this->lnorder;
    }
    /** @return list<LineNote> */
    function seq() {
        $this->ensure_lnorder();
        return $this->lnseq;
    }
    /** @param string $file
     * @param string $lineid
     * @return ?LineNote */
    function get_next($file, $lineid) {
        $this->ensure_lnorder();
        $seq = $this->lnorder[$lineid . "_" . $file] ?? null;
        while ($seq !== null && $seq !== count($this->lnseq) - 1) {
            ++$seq;
            if (!$this->lnseq[$seq]->is_empty()) {
                return $this->lnseq[$seq];
            }
        }
        return null;
    }
    /** @param string $file
     * @param string $lineid
     * @return ?LineNote */
    function get_prev($file, $lineid) {
        $this->ensure_lnorder();
        $seq = $this->lnorder[$lineid . "_" . $file] ?? null;
        while ($seq !== null && $seq !== 0) {
            --$seq;
            if (!$this->lnseq[$seq]->is_empty()) {
                return $this->lnseq[$seq];
            }
        }
        return null;
    }
    /** @param LineNote $a
     * @param LineNote $b
     * @return int */
    function compare($a, $b) {
        if ($a->file != $b->file) {
            return $this->fileorder[$a->file] - $this->fileorder[$b->file];
        } else if (!$this->diff || !isset($this->diff[$a->file])) {
            return strcmp($a->lineid, $b->lineid);
        } else if ($a->lineid[0] === $b->lineid[0]) {
            return (int) substr($a->lineid, 1) - (int) substr($b->lineid, 1);
        }
        $to = $this->totalorder[$a->file] ?? null;
        if (!$to) {
            $to = ["a0" => 0];
            $n = 0;
            foreach ($this->diff[$a->file] as $l) {
                if ($l[0] === "+" || $l[0] === " ") {
                    $to["b" . $l[2]] = ++$n;
                }
                if ($l[0] === "-" || $l[0] === " ") {
                    $to["a" . $l[1]] = ++$n;
                }
            }
            $this->totalorder[$a->file] = $to;
        }
        if (!isset($to[$a->lineid]) || !isset($to[$b->lineid])) {
            error_log(json_encode($a) . " / " . json_encode($b) . " / " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        }
        return $to[$a->lineid] - $to[$b->lineid];
    }
}

<?php
// gradeexport.php -- Peteramati class for JSON-compatible grade entry export
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class GradeExport implements JsonSerializable {
    /** @var Pset
     * @readonly */
    public $pset;
    /** @var bool */
    public $pc_view;
    /** @var bool */
    public $include_entries = true;
    /** @var ?int */
    public $uid;
    /** @var ?list<mixed> */
    public $grades;
    /** @var ?list<mixed> */
    public $autogrades;
    /** @var null|int|float */
    public $total;
    /** @var null|int|float */
    public $total_noextra;
    /** @var bool */
    private $has_total = false;
    /** @var ?string */
    public $grading_hash;
    /** @var ?int */
    public $version;
    /** @var ?int */
    public $answer_version;
    /** @var ?int */
    public $updateat;
    /** @var null|int */
    public $late_hours;
    /** @var null|int */
    public $auto_late_hours;
    /** @var ?bool */
    public $editable;
    /** @var ?list<GradeEntryConfig> */
    private $visible_grades;
    /** @var ?list<bool> */
    private $known_entries;

    /** @param bool $pc_view */
    function __construct(Pset $pset, $pc_view) {
        $this->pset = $pset;
        $this->pc_view = $pc_view;
    }

    /** @param iterable<GradeEntryConfig> $vges */
    function set_visible_grades($vges) {
        assert(!isset($this->grades));
        $this->visible_grades = is_list($vges) ? $vges : iterator_to_array($vges, false);
        $this->has_total = false;
    }

    /** @return list<GradeEntryConfig> */
    function visible_grades() {
        return $this->visible_grades ?? $this->pset->visible_grades($this->pc_view);
    }

    /** @return list<mixed> */
    function blank_gradelist() {
        return array_fill(0, count($this->visible_grades()), null);
    }

    function suppress_absent_extra() {
        $ges = $this->visible_grades();
        $nges = count($ges);
        for ($i = 0; $i !== count($ges); ) {
            if ($ges[$i]->is_extra
                && ($this->grades[$i] ?? 0) == 0) {
                array_splice($ges, $i, 1);
                array_splice($this->grades, $i, 1);
                if ($this->autogrades !== null) {
                    array_splice($this->autogrades, $i, 1);
                }
            } else {
                ++$i;
            }
        }
        if ($i !== $nges) {
            $this->visible_grades = $ges;
        }
    }

    /** @param list<string> $known_entries */
    function suppress_known_entries($known_entries) {
        $this->known_entries = $this->known_entries ?? array_fill(0, count($this->pset->grades), false);
        foreach ($known_entries as $key) {
            if (($ge = $this->pset->grades[$key]))
                $this->known_entries[$ge->pcview_index] = true;
        }
    }

    /** @return null|int|float */
    function total() {
        if (!$this->has_total) {
            $t = $tnx = 0;
            $any = false;
            foreach ($this->visible_grades() as $i => $ge) {
                if (!$ge->no_total
                    && ($gv = $this->grades[$i] ?? null) !== null) {
                    $t += $gv;
                    if (!$ge->is_extra) {
                        $tnx += $gv;
                    }
                    $any = true;
                }
            }
            if ($any) {
                $this->total = round_grade($t);
                $this->total_noextra = round_grade($tnx);
            } else {
                $this->total = $this->total_noextra = null;
            }
            $this->has_total = true;
        }
        return $this->total;
    }

    /** @return null|int|float */
    function total_noextra() {
        if (!$this->has_total) {
            $this->total();
        }
        return $this->total_noextra;
    }

    /** @return array */
    function jsonSerialize() {
        $r = [];
        if (isset($this->uid)) {
            $r["uid"] = $this->uid;
            if ($this->grades !== null) {
                $r["grades"] = $this->grades;
            } else if ($this->pc_view && empty($this->autogrades)) {
                $r["grades"] = [];
            }
            if ($this->pc_view && !empty($this->autogrades)) {
                $r["autogrades"] = $this->autogrades;
            }
            if (!$this->has_total) {
                $this->total();
            }
            if ($this->total !== null) {
                $r["total"] = $this->total;
            }
            if ($this->total_noextra !== null) {
                $r["total_noextra"] = $this->total_noextra;
            }
            if ($this->grading_hash !== null) {
                $r["grading_hash"] = $this->grading_hash;
            }
            if ($this->late_hours !== null) {
                $r["late_hours"] = $this->late_hours;
            }
            if ($this->auto_late_hours !== null) {
                $r["auto_late_hours"] = $this->auto_late_hours;
            }
            if ($this->updateat) {
                $r["updateat"] = $this->updateat;
            }
            if ($this->version !== null) {
                $r["version"] = $this->version;
            }
            if ($this->answer_version !== null) {
                $r["answer_version"] = $this->answer_version;
            }
            if ($this->editable !== null) {
                $r["editable"] = $this->editable;
            }
        }
        if ($this->include_entries || $this->visible_grades !== null) {
            $entries = $order = [];
            $gi = $maxtotal = 0;
            foreach ($this->visible_grades() as $ge) {
                $order[] = $ge->key;
                if (!isset($this->known_entries)
                    || !$this->known_entries[$ge->pcview_index]) {
                    $entries[$ge->key] = $ge->json($this->pc_view, $gi);
                }
                if ($ge->max
                    && !$ge->is_extra
                    && !$ge->no_total
                    && ($this->pc_view || $ge->max_visible)) {
                    $maxtotal += $ge->max;
                }
                ++$gi;
            }
            if ($this->include_entries) {
                if (!empty($entries)) {
                    $r["entries"] = $entries;
                } else if (empty($order)) {
                    $r["entries"] = (object) $entries;
                }
            }
            $r["order"] = $order;
            if ($this->pset->grades_total !== null) {
                $r["maxtotal"] = $this->pset->grades_total;
            } else if ($maxtotal > 0) {
                $r["maxtotal"] = round_grade($maxtotal);
            }
            if ($this->pset->grades_history) {
                $r["history"] = true;
            }
        }
        return $r;
    }
}

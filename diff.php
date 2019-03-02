<?php
// diff.php -- Peteramati diff page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
ContactView::set_path_request(array("/@", "/@/p", "/@/p/h", "/@/p/h/h", "/p/h/h"));
if ($Me->is_empty())
    $Me->escape();
global $User, $Pset, $Info, $Qreq;

$User = $Me;
if (isset($Qreq->u)
    && !($User = ContactView::prepare_user($Qreq->u)))
    redirectSelf(array("u" => null));
assert($User == $Me || $Me->isPC);
Ht::stash_script("peteramati_uservalue=" . json_encode($Me->user_linkpart($User)));

$Pset = ContactView::find_pset_redirect($Qreq->pset);
if ($Pset->gitless) {
    $Conf->errorMsg("That problem set does not use git.");
    $Me->escape(); // XXX stay on this page
}
$Info = PsetView::make($Pset, $User, $Me);
if (!$Info->repo) {
    $Conf->errorMsg("No repository.");
    $Me->escape(); // XXX stay on this page
}
if (!$Qreq->commit || !$Qreq->commit1) {
    if (!$Qreq->commit1) {
        if (!$Info->set_hash(null)) {
            $Me->escape();
        }
        $Qreq->commit1 = $Info->commit_hash();
    }
    if (!$Qreq->commit) {
        $Qreq->commit = $Info->derived_handout_hash();
    }
    if ($Qreq->commit && $Qreq->commit1) {
        redirectSelf(["commit" => $Qreq->commit, "commit1" => $Qreq->commit1]);
    } else {
        $Me->escape();
    }
}
$diff_options = ["wdiff" => false];

$hasha = $hashb = $hasha_mine = $hashb_mine = null;
$hrecent = $Pset->handout_commits();
if (($hasha = git_commit_in_list($hrecent, $Qreq->commit)))
    $diff_options["hasha_hrepo"] = true;
else
    $hasha = $hasha_mine = $Info->set_hash($Qreq->commit);
if (($hashb = git_commit_in_list($hrecent, $Qreq->commit1)))
    $diff_options["hashb_hrepo"] = true;
else
    $hashb = $hashb_mine = $Info->set_hash($Qreq->commit1);
if (!$hasha || !$hashb) {
    if (!$hasha)
        $Conf->errorMsg("Commit " . htmlspecialchars($Qreq->commit) . " is not connected to your repository.");
    if (!$hashb)
        $Conf->errorMsg("Commit " . htmlspecialchars($Qreq->commit1) . " is not connected to your repository.");
    $Me->escape();
}

$diff_options["hasha"] = $hasha;
if (!get($diff_options, "hasha_hrepo") || get($diff_options, "hashb_hrepo"))
    $diff_options["no_full"] = true;

$Conf->header(htmlspecialchars($Pset->title), "home");
ContactView::echo_heading($User);


$commita = $hasha_mine ? $Info->recent_commits($hasha) : $hrecent[$hasha];
$commitb = $hashb_mine ? $Info->recent_commits($hashb) : $hrecent[$hashb];
if ($commita->hash === $Info->grading_hash())
    $commita->subject .= "  ✱"; // space, nbsp
if ($commitb->hash === $Info->grading_hash())
    $commitb->subject .= "  ✱"; // space, nbsp
$TABWIDTH = $Info->commit_info("tabwidth") ? : 4;

echo "<table><tr><td><h2>diff</h2></td><td style=\"padding-left:10px;line-height:110%\">",
    "<div class=\"pa-dl pa-gd\" style=\"padding:2px 5px\"><big><code>", substr($hasha, 0, 7), "</code> ", htmlspecialchars($commita->subject), "</big></div>",
    "<div class=\"pa-dl pa-gi\" style=\"padding:2px 5px\"><big><code>", substr($hashb, 0, 7), "</code> ", htmlspecialchars($commitb->subject), "</big></div>",
    "</td></tr></table><hr>\n";

// collect diff and sort line notes
$lnorder = $hashb_mine ? $Info->viewable_line_notes() : $Info->empty_line_notes();
$diff = $Info->diff($hasha, $hashb, $lnorder, $diff_options);

// print line notes
$notelinks = array();
foreach ($lnorder->seq() as $fl) {
    $f = str_starts_with($fl[0], $Pset->directory_slash) ? substr($fl[0], strlen($Pset->directory_slash)) : $fl[0];
    $notelinks[] = '<a class="uix pa-goto pa-noteref'
        . (!$fl[2] && !$Info->user_can_view_grades() ? " pa-notehidden" : "")
        . '" href="#L' . $fl[1] . '_' . html_id_encode($fl[0])
        . '">' . htmlspecialchars($f) . ':' . substr($fl[1], 1) . '</a>';
}
if (count($notelinks))
    ContactView::echo_group("notes", join(", ", $notelinks));

// diff and line notes
foreach ($diff as $file => $dinfo) {
    $open = $lnorder->file_has_notes($file) || !$dinfo->boring || (!$hasha_mine && !$hashb_mine);
    $Info->echo_file_diff($file, $dinfo, $lnorder, ["open" => $open]);
}

Ht::stash_script('$(".pa-note-entry").autogrow();jQuery(window).on("beforeunload",pa_beforeunload)');
echo "<hr class=\"c\" />\n";
$Conf->footer();

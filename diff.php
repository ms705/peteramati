<?php
// diff.php -- Peteramati diff page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
ContactView::set_path_request(array("/@", "/@/p", "/@/p/h", "/@/p/h/h", "/p/h/h"));
if ($Me->is_empty()) {
    $Me->escape();
}
global $User, $Pset, $Info, $Qreq;

$User = $Me;
if (isset($Qreq->u)
    && !($User = ContactView::prepare_user($Qreq->u))) {
    redirectSelf(["u" => null]);
}
assert($User === $Me || $Me->isPC);
Ht::stash_script("peteramati_uservalue=" . json_encode_browser($Me->user_linkpart($User)));

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

$commita = $Info->find_commit($Qreq->commit);
$commitb = $Info->find_commit($Qreq->commit1);
if (!$commita || !$commitb) {
    if (!$commita) {
        $Conf->errorMsg("Commit " . htmlspecialchars($Qreq->commit) . " is not connected to your repository.");
    }
    if (!$commitb) {
        $Conf->errorMsg("Commit " . htmlspecialchars($Qreq->commit1) . " is not connected to your repository.");
    }
    $Me->escape();
}
$Info->set_hash($commitb->hash);

$diff_options = [
    "wdiff" => false,
    "no_full" => !$commita->is_handout() || $commitb->is_handout()
];
if ($commita->hash === $Info->grading_hash()) {
    $commita->subject .= "  ✱"; // space, nbsp
}
if ($commitb->hash === $Info->grading_hash()) {
    $commitb->subject .= "  ✱"; // space, nbsp
}
$TABWIDTH = $Info->commit_info("tabwidth") ? : 4;


$Conf->header(htmlspecialchars($Pset->title), "home");
ContactView::echo_heading($User);

echo '<div class="pa-psetinfo" data-pa-pset="', htmlspecialchars($Info->pset->urlkey),
    '" data-pa-base-hash="', htmlspecialchars($commita->hash),
    '" data-pa-hash="', htmlspecialchars($commitb->hash);
if (!$Pset->gitless && $Pset->directory) {
    echo '" data-pa-directory="', htmlspecialchars($Pset->directory_slash);
}
if ($Info->user_can_view_grades()) {
    echo '" data-pa-user-can-view-grades="yes';
}
if ($Info->user->extension) {
    echo '" data-pa-user-extension="yes';
}
echo '">';

echo "<table><tr><td><h2>diff</h2></td><td style=\"padding-left:10px;line-height:110%\">",
    "<div class=\"pa-dl pa-gdsamp\" style=\"padding:2px 5px\"><big><code>", substr($commita->hash, 0, 7), "</code> ", htmlspecialchars($commita->subject), "</big></div>",
    "<div class=\"pa-dl pa-gisamp\" style=\"padding:2px 5px\"><big><code>", substr($commitb->hash, 0, 7), "</code> ", htmlspecialchars($commitb->subject), "</big></div>",
    "</td></tr></table>";
echo Ht::button("Hide left", ["class" => "btn ui pa-diff-toggle-hide-left pa-diff-show-right"]);
echo "<hr>\n";

// collect diff and sort line notes
$lnorder = $commitb->is_handout() ? $Info->empty_line_notes() : $Info->viewable_line_notes();
$diff = $Info->diff($commita, $commitb, $lnorder, $diff_options);

// print line notes
$notelinks = array();
foreach ($lnorder->seq() as $fl) {
    $f = str_starts_with($fl[0], $Pset->directory_slash) ? substr($fl[0], strlen($Pset->directory_slash)) : $fl[0];
    $notelinks[] = '<a class="uix pa-goto pa-noteref'
        . (!$fl[2] && !$Info->user_can_view_grades() ? " pa-notehidden" : "")
        . '" href="#L' . $fl[1] . '_' . html_id_encode($fl[0])
        . '">' . htmlspecialchars($f) . ':' . substr($fl[1], 1) . '</a>';
}
if (!empty($notelinks)) {
    ContactView::echo_group("notes", join(", ", $notelinks));
}

// diff and line notes
foreach ($diff as $file => $dinfo) {
    $open = $lnorder->file_has_notes($file) || !$dinfo->boring || empty($notelinks);
    $Info->echo_file_diff($file, $dinfo, $lnorder, ["open" => $open, "only_diff" => true]);
}

Ht::stash_script('$(".pa-note-entry").autogrow();jQuery(window).on("beforeunload",pa_beforeunload)');
echo "</div><hr class=\"c\" />\n";
$Conf->footer();

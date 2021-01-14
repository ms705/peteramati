// pset.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { handle_ui, fold61 } from "./ui.js";
import { event_key, event_modkey } from "./ui-key.js";
import { hoturl, hoturl_gradeapi } from "./hoturl.js";
import { escape_entities } from "./encoders.js";
import { Bubble } from "./tooltip.js";


handle_ui.on("js-repo-copy", function () {
    var node = document.createTextNode(this.getAttribute("data-pa-repo"));
    var bub = Bubble(node, {color: "tooltip", dir: "t"});
    bub.near(this);
    var range = document.createRange();
    range.selectNode(node);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    try {
        document.execCommand("copy");
    } catch (err) {
    }
    window.getSelection().removeAllRanges();
    bub.remove();
    if (window.global_tooltip && window.global_tooltip.elt == this) {
        window.global_tooltip.text(this.getAttribute("data-pa-repo"));
    }
});

handle_ui.on("js-repo-list", function (event) {
    var self = this;
    $.ajax(hoturl("api", {fn: "repositories", u: this.getAttribute("data-pa-user")}), {
        method: "POST", cache: false,
        success: function (data) {
            var t = "Error loading repositories";
            if (data.repositories && data.repositories.length) {
                t = "Repositories: ";
                for (var i = 0; i < data.repositories.length; ++i) {
                    var r = data.repositories[i];
                    i && (t += ", ");
                    t += "<a href=\"" + escape_entities(r.url) + "\">" + escape_entities(r.name) + "</a>";
                }
            } else if (data.repositories) {
                t = "No repositories";
            }
            $("<div style=\"font-size:medium;font-weight:normal\"></div>").html(t).insertAfter(self);
        }
    });
    event.preventDefault();
});

handle_ui.on("js-pset-viewoptions", function () {
    fold61(this.nextSibling, this.parentNode);
});

handle_ui.on("js-pset-setgrader", function () {
    var $form = $(this.closest("form"));
    $.ajax($form[0].getAttribute("action"), {
        data: $form.serializeWith({}),
        type: "POST", cache: false,
        dataType: "json",
        success: function (data) {
            var a;
            $form.find(".ajaxsave61").html(data.ok ? "Saved" : "<span class='error'>Error: " + data.error + "</span>");
            if (data.ok && (a = $form.find("a.actas")).length) {
                a.attr("href", a.attr("href").replace(/actas=[^&;]+/, "actas=" + encodeURIComponent(data.grader_email)));
            }
        },
        error: function () {
            $form.find(".ajaxsave61").html("<span class='error'>Failed</span>");
        }
    });
});

handle_ui.on("js-pset-setcommit", function () {
    this.closest("form").submit();
});

handle_ui.on("js-pset-flag", function () {
    let self = this, form = self.closest("form");
    if (this.name === "flag" && !form.elements.flagreason) {
        $(self).before('<span class="flagreason">Why do you want to flag this commit? &nbsp;<input type="text" name="flagreason" value="" placeholder="Optional reason" /> &nbsp;</span>');
        $(form.elements.flagreason).on("keypress", function (evt) {
            if (!event_modkey(evt) && event_key(evt) === "Enter") {
                evt.preventDefault();
                evt.stopImmediatePropagation();
                $(self).click();
            }
        }).autogrow()[0].focus();
        $(self).html("OK");
    } else if (this.name === "flag") {
        $.post(hoturl_gradeapi(this, "=api/flag", {flagid: "new"}),
            {reason: form.elements.flagreason.value},
            function (data) {
                if (data && data.ok) {
                    $(form).find(".flagreason").remove();
                    $(self).replaceWith("<strong>Flagged</strong>");
                } else {
                    $(form).find(".ajaxsave61").html('<span class="error">Failed</span>');
                }
            });
    } else if (this.name == "resolveflag") {
        $.post(hoturl_gradeapi(this, "=api/flag", {flagid: this.getAttribute("data-flagid"), resolve: 1}), {},
            function (data) {
                if (data && data.ok) {
                    $(self).replaceWith("<strong>Resolved</strong>");
                } else {
                    $(form).find(".ajaxsave61").html('<span class="error">Failed</span>');
                }
            });
    }
});

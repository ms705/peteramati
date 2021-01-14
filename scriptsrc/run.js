// run.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { wstorage, sprintf, strftime } from "./utils.js";
import { hasClass, addClass, removeClass, fold61, handle_ui } from "./ui.js";
import { event_key, event_modkey } from "./ui-key.js";
import { render_terminal } from "./render-terminal.js";
import { grades_fetch } from "./grades.js";

export function run(button, opts) {
    const $f = $(button).closest("form"),
        category = button.getAttribute("data-pa-run-category") || button.value,
        directory = $(button).closest(".pa-psetinfo").attr("data-pa-directory"),
        therun = document.getElementById("pa-run-" + category),
        therunout = therun.closest(".pa-runout"),
        thepre = $(therun).find("pre");
    let thexterm,
        checkt,
        kill_checkt;

    if (opts.unfold && therun.dataset.paTimestamp) {
        checkt = +therun.dataset.paTimestamp;
    } else {
        if ($f.prop("outstanding")) {
            return true;
        }
        $f.find("button").prop("disabled", true);
        $f.prop("outstanding", true);
    }
    delete therun.dataset.paTimestamp;

    therunout && removeClass(therunout, "hidden");
    fold61(therun, therunout, true);
    if (!checkt && !opts.noclear) {
        thepre.html("");
        addClass(thepre[0].parentElement, "pa-run-short");
        thepre[0].removeAttribute("data-pa-terminal-style");
        $(therun).children(".pa-runrange").remove();
    } else if (therun.lastChild) {
        $(therun.lastChild).find("span.pa-runcursor").remove();
    }

    function stop_button(on) {
        const h3 = therunout ? therunout.firstChild : null;
        if (h3 && h3.tagName === "H3") {
            const btn = $(h3).find(".pa-runstop");
            if (on && !btn.length) {
                $("<button class=\"btn btn-danger pa-runstop\" type=\"button\">Stop</button>").click(stop).appendTo(h3);
            } else if (!on && btn.length) {
                btn.remove();
            }
        }
    }

    function terminal_char_width(min, max) {
        const x = $('<span style="position:absolute">0</span>').appendTo(thepre),
            w = Math.trunc(thepre.width() / x.width() / 1.33);
        x.remove();
        return Math.max(min, Math.min(w, max));
    }

    if (therun.dataset.paXtermJs
        && therun.dataset.paXtermJs !== "false"
        && window.Terminal) {
        removeClass(thepre[0].parentElement, "pa-run-short");
        addClass(thepre[0].parentElement, "pa-run-xterm-js");
        thexterm = new Terminal({cols: terminal_char_width(80, 132), rows: 25});
        thexterm.open(thepre[0]);
        thexterm.attachCustomKeyEventHandler(function (event) {
            if (event.type === "keydown") {
                let key = event_key(event), mod = event_modkey(event);
                if (key.length === 1) {
                    if ((mod & 0xE) === 0 && event_key.printable(event)) {
                        // keep `key`
                    } else if ((mod & 0xE) === event_modkey.CTRL
                               && key >= "a"
                               && key <= "z") {
                        key = String.fromCharCode(key.charCodeAt(0) - 96);
                    } else if ((mod & 0xE) === event_modkey.META
                               && key == "v") {
                        navigator.clipboard.readText().then(tx => write(tx));
                        event.preventDefault();
                        return;
                    } else {
                        key = "";
                    }
                } else {
                    if (key === "Enter" && !mod) {
                        key = "\r";
                    } else if (key === "Escape" && !mod) {
                        key = "\x1B";
                    } else if (key === "Backspace" && !mod) {
                        key = "\x7F";
                    } else if (key === "Tab" && !mod) {
                        key = "\x09";
                    } else if (key === "ArrowUp" && !mod) {
                        key = "\x1B[A";
                    } else if (key === "ArrowDown" && !mod) {
                        key = "\x1B[B";
                    } else if (key === "ArrowRight" && !mod) {
                        key = "\x1B[C";
                    } else if (key === "ArrowLeft" && !mod) {
                        key = "\x1B[D";
                    } else {
                        key = "";
                    }
                }
                if (key !== "") {
                    write(key);
                    event.preventDefault();
                }
            }
            return false;
        });
        if (opts.focus) {
            thexterm.focus();
        }
    }

    function scroll_therun() {
        if (!thexterm
            && (hasClass(therun, "pa-run-short")
                || therun.hasAttribute("data-pa-runbottom"))) {
            requestAnimationFrame(function () {
                if (therun.scrollHeight > therun.clientHeight) {
                    removeClass(therun, "pa-run-short");
                }
                if (therun.hasAttribute("data-pa-runbottom")) {
                    therun.scrollTop = Math.max(therun.scrollHeight - therun.clientHeight, 0);
                }
            });
        }
    }

    if (!therun.hasAttribute("data-pa-opened")) {
        therun.setAttribute("data-pa-opened", "true");
        if (!thexterm) {
            therun.setAttribute("data-pa-runbottom", "true");
            therun.addEventListener("scroll", function () {
                requestAnimationFrame(function () {
                    if (therun.scrollTop + therun.clientHeight >= therun.scrollHeight - 10)
                        therun.setAttribute("data-pa-runbottom", "true");
                    else
                        therun.removeAttribute("data-pa-runbottom");
                });
            });
            scroll_therun();
        }
    }

    let ibuffer = "", // initial buffer; holds data before any results arrive
        offset = -1, backoff = 50, queueid = null, times = null;

    function hide_cursor() {
        if (thexterm) {
            thexterm.write("\x1b[?25l"); // “hide cursor” escape
        } else if (therun.lastChild) {
            $(therun.lastChild).find(".pa-runcursor").remove();
        }
    }

    function done() {
        $f.find("button").prop("disabled", false);
        $f.prop("outstanding", false);
        hide_cursor();
        if (button.hasAttribute("data-pa-run-grade")) {
            grades_fetch.call(button.closest(".pa-psetinfo"));
        }
    }

    function append(str) {
        if (thexterm) {
            thexterm.write(str);
        } else {
            render_terminal(thepre[0], str, {cursor: true, directory: directory});
        }
    }

    function append_html(html) {
        if (typeof html === "string") {
            html = $(html)[0];
        }
        if (thexterm) {
            if (window.console) {
                console.log("xterm.js cannot render " + html);
            }
        } else {
            render_terminal(thepre[0], html, {cursor: true});
        }
    }

    function append_data(str, data) {
        if (ibuffer !== null) { // haven't started generating output
            ibuffer += str;
            var pos = ibuffer.indexOf("\n\n");
            if (pos < 0) {
                return; // not ready yet
            }

            str = ibuffer.substr(pos + 2);
            ibuffer = null;

            var tsmsg = "";
            if (data && data.timestamp) {
                tsmsg = "...started " + strftime("%l:%M:%S%P %e %b %Y", new Date(data.timestamp * 1000));
            }

            if (thexterm) {
                if (tsmsg !== "") {
                    tsmsg = "\x1b[3;1;38;5;86m" + tsmsg + "\x1b[m\r\n";
                }
                if (!opts.noclear) {
                    tsmsg = "\x1bc" + tsmsg;
                }
                str = tsmsg + str;
            } else {
                if (!opts.noclear) {
                    thepre.html("");
                }
                if (tsmsg !== "") {
                    append_html("<span class=\"pa-runtime\">" + tsmsg + "</span>");
                }
            }
        }
        if (str !== "") {
            append(str);
        }
    }

    function parse_times(times) {
        let a = [0, 0], p = 0;
        while (p < times.length) {
            const c = times.indexOf(",", p);
            if (c < 0) {
                break;
            }
            let n = times.indexOf("\n", c + 1);
            if (n < 0) {
                n = times.length;
            }
            a.push(+times.substring(p, c), +times.substring(c + 1, n));
            p = n + 1;
        }
        return a;
    }

    function append_timed(data, at_end) {
        var erange, etime, ebutton, espeed,
            tpos, tstart, tlast, timeout, running, factor;
        if (times) {
            return;
        }
        times = data.time_data;
        if (typeof times === "string") {
            times = parse_times(times);
        }
        factor = data.time_factor;
        if (times.length > 2) {
            erange = $('<div class="pa-runrange"><button type="button" class="pa-runrange-play"></button><input type="range" class="pa-runrange-range" min="0" max="' + times[times.length - 2] + '"><span class="pa-runrange-time"></span><span class="pa-runrange-speed-slow" title="Slow">🐢</span><input type="range" class="pa-runrange-speed" min="0.1" max="10" step="0.1"><span class="pa-runrange-speed-fast" title="Fast">🐇</span></div>').prependTo(therun);
            etime = erange[0].lastChild;
            ebutton = erange[0].firstChild;
            erange = ebutton.nextSibling;
            etime = erange.nextSibling;
            espeed = etime.nextSibling.nextSibling;
            erange.addEventListener("input", function () {
                running = false;
                addClass(ebutton, "paused");
                f(+this.value);
            }, false);
            ebutton.addEventListener("click", function () {
                if (hasClass(ebutton, "paused")) {
                    removeClass(ebutton, "paused");
                    running = true;
                    tstart = (new Date).getTime();
                    if (tlast < times[times.length - 2])
                        tstart -= tlast / factor;
                    f(null);
                } else {
                    addClass(ebutton, "paused");
                    running = false;
                }
            }, false);
            espeed.addEventListener("input", function () {
                factor = +this.value;
                wstorage.site(false, "pa-runspeed-" + category, [factor, (new Date).getTime()]);
                if (running) {
                    tstart = (new Date).getTime() - tlast / factor;
                    f(null);
                }
            }, false);
        }
        if ((tpos = wstorage.site_json(false, "pa-runspeed-" + category))
            && tpos[1] >= (new Date).getTime() - 86400000) {
            factor = tpos[0];
        }
        if (factor < 0.1 || factor > 10) {
            factor = 1;
        }
        if (espeed) {
            espeed.value = factor;
        }
        data = {data: data.data, timestamp: data.timestamp};

        function set_time() {
            if (erange) {
                erange.value = tlast;
                etime.innerHTML = sprintf("%d:%02d.%03d", Math.trunc(tlast / 60000), Math.trunc(tlast / 1000) % 60, Math.trunc(tlast) % 1000);
            }
        }

        function f(time) {
            if (time === null) {
                if (running)
                    time = ((new Date).getTime() - tstart) * factor;
                else
                    return;
            }
            var npos = tpos;
            if (npos >= times.length || time < times[npos])
                npos = 0;
            if (npos + 2 < times.length && time >= times[npos]) {
                var rpos = times.length;
                while (npos < rpos) {
                    var m = npos + (((rpos - npos) >> 1) & ~1);
                    if (time <= times[m])
                        rpos = m;
                    else
                        npos = m + 2;
                }
            }
            while (npos < times.length && time >= times[npos]) {
                npos += 2;
            }
            tlast = time;

            if (npos < tpos) {
                ibuffer = "";
                tpos = 0;
            }

            var str = data.data;
            append_data(str.substring(tpos < times.length ? times[tpos + 1] : str.length,
                                      npos < times.length ? times[npos + 1] : str.length),
                        data);
            scroll_therun();
            set_time();

            tpos = npos;
            if (timeout) {
                timeout = clearTimeout(timeout);
            }
            if (running) {
                if (tpos < times.length) {
                    timeout = setTimeout(f, Math.min(100, (times[tpos] - (tpos ? times[tpos - 2] : 0)) / factor), null);
                } else {
                    if (ebutton)
                        addClass(ebutton, "paused");
                    hide_cursor();
                }
            }
        }

        if (at_end) {
            tpos = times.length;
            tlast = times[tpos - 2];
            running = false;
            ebutton && addClass(ebutton, "paused");
            set_time();
        } else {
            tpos = 0;
            tlast = 0;
            tstart = (new Date).getTime();
            running = true;
            if (times.length) {
                f(null);
            }
        }
    }

    let send_out = 0, send_args = {};

    function succeed(data) {
        var x, t;

        if (queueid) {
            thepre.find("span.pa-runqueue").remove();
        }
        if (data && data.onqueue) {
            queueid = data.queueid;
            t = "On queue, " + data.nahead + (data.nahead == 1 ? " job" : " jobs") + " ahead";
            if (data.headage) {
                if (data.headage < 10) {
                    x = data.headage;
                } else {
                    x = Math.round(data.headage / 5 + 0.5) * 5;
                }
                t += ", oldest began about " + x + (x == 1 ? " second" : " seconds") + " ago";
            }
            thepre[0].insertBefore(($("<span class='pa-runqueue'>" + t + "</span>"))[0], thepre[0].lastChild);
            setTimeout(send, 10000);
            return;
        }

        stop_button(data && (data.status === "working" || data.status === "workingconflict"));

        if (!data || !data.ok) {
            x = "Unknown error";
            if (data && data.loggedout) {
                x = "You have been logged out (perhaps due to inactivity). Please reload this page.";
            } else if (data) {
                if (data.error_text) {
                    x = data.error_text;
                } else if (data.error && data.error !== true) {
                    x = data.error;
                } else if (data.message) {
                    x = data.message;
                }
                if (data.errorcode === 1001 // ERRORCODE_RUNCONFLICT
                    && data.checkt) {
                    kill_checkt = data.checkt;
                }
            }
            append("\x1b[1;3;31m" + x + "\x1b[m\r\n");
            scroll_therun();
            return done();
        }

        checkt = checkt || data.timestamp;
        if (data.data && data.offset < offset) {
            data.data = data.data.substring(offset - data.offset);
        }
        if (data.data) {
            offset = data.lastoffset;
            if (data.done && data.time_data != null && ibuffer === "") {
                // Parse timing data
                append_timed(data);
                return;
            }

            append_data(data.data, data);
            backoff = 100;
        }
        if (data.result) {
            if (ibuffer !== null) {
                append_data("\n\n", data);
            }
            append_data(data.result, data);
        }
        if (!data.data && !data.result) {
            backoff = Math.min(backoff * 2, 500);
        }

        scroll_therun();
        if (data.status == "old") {
            setTimeout(send, 2000);
        } else if (!data.done) {
            setTimeout(send, backoff);
        } else {
            done();
            if (data.timed && !hasClass(therun.firstChild, "pa-runrange")) {
                send({offset: 0}, succeed_add_times);
            }
        }
    }

    function succeed_add_times(data) {
        --send_out;
        if (data.data && data.done && data.time_data != null) {
            append_timed(data, true);
        }
    }

    function send(args, success) {
        if (args && args.stop) {
            send_args.stop = 1;
        }
        if (args && args.write) {
            send_args.write = (send_args.write || "").concat(args.write);
        }
        if (send_args.write && send_out > 0) {
            return;
        }

        let a = {};
        if (!$f[0].run) {
            a.run = category;
        }
        a.offset = offset;
        if (checkt) {
            a.check = checkt;
        } else if (args && args.stop && kill_checkt) {
            a.check = kill_checkt;
        }
        queueid && (a.queueid = queueid);
        Object.assign(a, send_args);
        delete send_args.write;
        ++send_out;

        jQuery.ajax($f.attr("action"), {
            data: $f.serializeWith(a),
            type: "POST", cache: false, dataType: "json", timeout: 30000,
            success: function (data) {
                --send_out;
                (success || succeed)(data);
                send_args.write && send({});
            },
            error: function () {
                $f.find(".ajaxsave61").html("Failed");
                $f.prop("outstanding", false);
            }
        });
    }

    function stop() {
        send({stop: 1});
    }

    function write(value) {
        send({write: value});
    }

    if (opts.headline && opts.noclear && !thexterm && thepre[0].firstChild) {
        append("\n\n");
    }
    if (opts.headline && opts.headline instanceof Node) {
        append_html(opts.headline);
    } else if (opts.headline) {
        append("\x1b[1;37m" + opts.headline + "\x1b[m\n");
    }
    if (opts.unfold && therun.getAttribute("data-pa-content")) {
        append(therun.getAttribute("data-pa-content"));
    }
    if (opts.focus) {
        $(therunout).scrollIntoView();
    }
    therun.removeAttribute("data-pa-content");
    scroll_therun();

    send();
    return false;
}


handle_ui.on("pa-runner", function () {
    run(this, {focus: true});
});

handle_ui.on("pa-run-show", function () {
    const parent = this.closest(".pa-runout"),
        name = parent.id.substring(4),
        therun = document.getElementById("pa-run-" + name);
    if (therun.dataset.paTimestamp && !$(therun).is(":visible")) {
        const thebutton = jQuery(".pa-runner[value='" + name + "']")[0];
        run(thebutton, {unfold: true});
    } else {
        fold61(therun, jQuery("#run-" + name));
    }
});

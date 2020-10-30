// hoturl.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

function serialize_object(x) {
    if (typeof x === "string")
        return x;
    else if (x) {
        var k, v, a = [];
        for (k in x)
            if ((v = x[k]) != null)
                a.push(encodeURIComponent(k) + "=" + encodeURIComponent(v));
        return a.join("&");
    } else
        return "";
}

function hoturl_add(url, component) {
    var hash = url.indexOf("#");
    if (hash >= 0) {
        component += url.substring(hash);
        url = url.substring(0, hash);
    }
    return url + (url.indexOf("?") < 0 ? "?" : "&") + component;
}

function hoturl_clean_before(x, page_component, prefix) {
    if (x.first !== false && x.v.length) {
        for (var i = 0; i < x.v.length; ++i) {
            var m = page_component.exec(x.v[i]);
            if (m) {
                x.pt += prefix + m[1] + "/";
                x.v.splice(i, 1);
                x.first = m[1];
                return;
            }
        }
        x.first = false;
    }
}

function hoturl_clean(x, page_component) {
    if (x.last !== false && x.v.length) {
        for (var i = 0; i < x.v.length; ++i) {
            var m = page_component.exec(x.v[i]);
            if (m) {
                x.t += "/" + m[1];
                x.v.splice(i, 1);
                x.last = m[1];
                return;
            }
        }
        x.last = false;
    }
}

export function hoturl(page, options) {
    var k, v, t, a, m, x, anchor = "", want_forceShow;
    if (siteinfo.site_relative == null || siteinfo.suffix == null) {
        siteinfo.site_relative = siteinfo.suffix = "";
        log_jserror("missing siteinfo");
    }

    x = {pt: "", t: page + siteinfo.suffix};
    if (typeof options === "string") {
        if ((m = options.match(/^(.*?)(#.*)$/))) {
            options = m[1];
            anchor = m[2];
        }
        x.v = options.split(/&/);
    } else {
        x.v = [];
        for (k in options) {
            v = options[k];
            if (v == null)
                /* skip */;
            else if (k === "anchor")
                anchor = "#" + v;
            else
                x.v.push(encodeURIComponent(k) + "=" + encodeURIComponent(v));
        }
    }

    if (page === "help") {
        hoturl_clean(x, /^t=(\w+)$/);
    } else if (page.substr(0, 3) === "api") {
        if (page.length > 3) {
            x.t = "api" + siteinfo.suffix;
            x.v.push("fn=" + page.substr(4));
        }
        hoturl_clean_before(x, /^u=([^?&#]+)$/, "~");
        hoturl_clean(x, /^fn=(\w+)$/);
        hoturl_clean(x, /^pset=([^?&#]+)$/);
        hoturl_clean(x, /^commit=([0-9A-Fa-f]+)$/);
        want_forceShow = true;
    } else if (page === "index") {
        hoturl_clean_before(x, /^u=([^?&#]+)$/, "~");
    } else if (page === "pset" || page === "run") {
        hoturl_clean_before(x, /^u=([^?&#]+)$/, "~");
        hoturl_clean(x, /^pset=([^?&#]+)$/);
        hoturl_clean(x, /^commit=([0-9A-Fa-f]+)$/);
    }

    if (siteinfo.defaults)
        x.v.push(serialize_object(siteinfo.defaults));
    if (x.v.length)
        x.t += "?" + x.v.join("&");
    return siteinfo.site_relative + x.pt + x.t + anchor;
}

export function hoturl_post(page, options) {
    options = serialize_object(options);
    options += (options ? "&" : "") + "post=" + siteinfo.postvalue;
    return hoturl(page, options);
}

export function hoturl_gradeparts(e, args) {
    var p = e.closest(".pa-psetinfo"), v;
    args = args || {};
    v = p.getAttribute("data-pa-user");
    args.u = v || peteramati_uservalue;
    if ((v = p.getAttribute("data-pa-pset"))) {
        args.pset = v;
    }
    if ((v = p.getAttribute("data-pa-hash"))) {
        args.commit = v;
    }
    return args;
}

export function url_absolute(url, loc) {
    var x = "", m;
    loc = loc || window.location.href;
    if (!/^\w+:\/\//.test(url)
        && (m = loc.match(/^(\w+:)/)))
        x = m[1];
    if (x && !/^\/\//.test(url)
        && (m = loc.match(/^\w+:(\/\/[^\/]+)/)))
        x += m[1];
    if (x && !/^\//.test(url)
        && (m = loc.match(/^\w+:\/\/[^\/]+(\/[^?#]*)/))) {
        x = (x + m[1]).replace(/\/[^\/]+$/, "/");
        while (url.substring(0, 3) === "../") {
            x = x.replace(/\/[^\/]*\/$/, "/");
            url = url.substring(3);
        }
    }
    return x + url;
}

export function hoturl_absolute_base() {
    if (!siteinfo.absolute_base)
        siteinfo.absolute_base = url_absolute(siteinfo.base);
    return siteinfo.absolute_base;
}


let outstanding = 0, waiting = [];

function post_ajax(url, data, method, resolve) {
    return function () {
        ++outstanding;
        $.ajax(url, {
            data: data, method: method || "POST", cache: false, dataType: "json",
            success: function (data) {
                resolve(data);
                --outstanding;
                waiting.length && waiting.shift()();
            }
        });
    };
}

export function api_conditioner(url, data, method) {
    return new Promise(function (resolve, reject) {
        var f = post_ajax(url, data, method, resolve);
        outstanding < 5 ? f() : waiting.push(f);
    });
}
// gc-letter.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { GradeClass } from "./gc.js";


const lm = {
    98: "A+", 95: "A", 92: "A-", 88: "B+", 85: "B", 82: "B-",
    78: "C+", 75: "C", 72: "C-", 68: "D+", 65: "D", 62: "D-", 50: "F",
    70: "S", 90: "S*", 0: "NC"
};

GradeClass.add("letter", {
    text: v => (v == null ? "" : lm[v] || "" + v),
    entry: function (id, opts) {
        opts.max_text = "letter grade";
        return GradeClass.basic_entry.call(this, id, opts);
    },
    justify: "left",
    tics: () => {
        const a = [];
        for (let g in lm) {
            if (lm[g].length === 1)
                a.push({x: g, text: lm[g]});
        }
        for (let g in lm) {
            if (lm[g].length === 2)
                a.push({x: g, text: lm[g], label_space: 5});
        }
        for (let g in lm) {
            if (lm[g].length === 2)
                a.push({x: g, text: lm[g].substring(1), label_space: 2, notic: true});
        }
        return a;
    }
});

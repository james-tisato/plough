const NO_AVERAGE = 99999999999999.9;

function strip_link_html(item) {
    if (item.startsWith("<a")) {
        const href_regex = /<a.*>(.*?)<\/a>/;
        const href_match = item.match(href_regex);
        return href_match[1];
    }
    else {
        return item;
    }
}

function extract_batting_score(score) {
    const score_no_html = strip_link_html(score);
    const score_regex = /(\d+)(\*?)/;
    const score_match = score_no_html.match(score_regex);
    return [parseInt(score_match[1]), score_match[2] === "*"];
}

function extract_bowling_figures(figures) {
    const figures_no_html = strip_link_html(figures);
    let [wickets, runs] = figures_no_html.split("/");
    return [parseInt(wickets), parseInt(runs)];
}

jQuery.extend( jQuery.fn.dataTableExt.oSort, {
    /**
	 * Average
	 */
	'average-pre': function ( a ) {
        if (a === "-") {
            return NO_AVERAGE;
        }
        else {
            return parseFloat(a);
        }
	},

    /**
     * Batting score
     */
     'batting-score-asc': function ( a, b ) {
         const [aScore, aNotOut] = extract_batting_score(a);
         const [bScore, bNotOut] = extract_batting_score(b);
         if (aScore === bScore) {
             return bNotOut - aNotOut;
         }
         else {
             return bScore - aScore;
         }
     },
     'batting-score-desc': function ( a, b ) {
         const [aScore, aNotOut] = extract_batting_score(a);
         const [bScore, bNotOut] = extract_batting_score(b);
         if (aScore === bScore) {
             return aNotOut - bNotOut;
         }
         else {
             return aScore - bScore;
         }
     },

    /**
	 * Bowling figures
	 */
	'bowling-figures-asc': function ( a, b ) {
        const [aWickets, aRuns] = extract_bowling_figures(a);
        const [bWickets, bRuns] = extract_bowling_figures(b);
        if (aWickets === bWickets) {
            return bRuns - aRuns;
        }
        else {
            return aWickets - bWickets;
        }
	},
	'bowling-figures-desc': function ( a, b ) {
        const [aWickets, aRuns] = extract_bowling_figures(a);
        const [bWickets, bRuns] = extract_bowling_figures(b);
        if (aWickets === bWickets) {
            return aRuns - bRuns;
        }
        else {
            return bWickets - aWickets;
        }
	}
} );

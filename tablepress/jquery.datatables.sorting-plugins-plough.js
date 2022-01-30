const NO_AVERAGE = 99999999999999.9;

function extract_bowling_figures(figures) {
    let [wickets, runs] = figures.split("/");
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
	 * Bowling figures
	 */
	'bowling-figures-asc': function ( a, b ) {
        const [aWickets, aRuns] = extract_bowling_figures(a);
        const [bWickets, bRuns] = extract_bowling_figures(b);
        if (aWickets == bWickets) {
            return bRuns - aRuns;
        }
        else {
            return aWickets - bWickets;
        }
	},
	'bowling-figures-desc': function ( a, b ) {
        const [aWickets, aRuns] = extract_bowling_figures(a);
        const [bWickets, bRuns] = extract_bowling_figures(b);
        if (aWickets == bWickets) {
            return aRuns - bRuns;
        }
        else {
            return bWickets - aWickets;
        }
	}
} );

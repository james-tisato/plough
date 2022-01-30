function deformat(figures) {
    let [wickets, runs] = figures.split("/");
    return [parseInt(wickets), parseInt(runs)];
}

jQuery.extend( jQuery.fn.dataTableExt.oSort, {
	/**
	 * Bowling figures
	 */
	'bowling-figures-asc': function ( a, b ) {
        const [aWickets, aRuns] = deformat(a);
        const [bWickets, bRuns] = deformat(b);
        if (aWickets == bWickets) {
            return bRuns - aRuns;
        }
        else {
            return aWickets - bWickets;
        }
	},
	'bowling-figures-desc': function ( a, b ) {
        const [aWickets, aRuns] = deformat(a);
        const [bWickets, bRuns] = deformat(b);
        if (aWickets == bWickets) {
            return aRuns - bRuns;
        }
        else {
            return bWickets - aWickets;
        }
	}
} );

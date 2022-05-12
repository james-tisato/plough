__TODO__
* Website
    * Infrastructure
    * Styling
        * Clean up custom CSS:
            * Change default table style so we don't need to specify centered text for every column
            * Reduce number of table style sections (don't need one per distinct column count)
    * History
        * Auto-sizing of roll of honour table?
    * Stats
    * Photos
        * More photos for different categories
            * Bowling
            * Fielding
            * Away games
            * Tour
        * Update front page slideshow
        * Update page header backgrounds
        * Update photo gallery
* Plugin
    * Infrastructure
        * Logging
            * Start using levels properly
            * Add custom exception handler?
            * Can we do the same for warnings?
    * Stats
        * Bugs
            * Warnings in some tests with new PHP 7.4 version
            * Duplicate batting performance entries being created when loading career partnerships?
        * Refactoring
        * Improvements
        * Performance
            * Set up xdebug + cachegrind viewer on laptop
        * New features
            * Tour stats
            * Add static season summary support
            * Page per player
                * What will the format be?
                    * Current analysis spreadsheet only shows breakdown of single season / whole career
                    * Should there be a top-level year-by-year summary that is available on every page and allows easier navigation?
                    * What are the use cases in more detail?
                * How will it be implemented?
                    * Dynamic page that takes player name as a parameter
                    * Build temporary tables on the fly by querying database
                        * Probably need to extract some of the code in import function to build a table in-memory but never actually save it
                        * Construct all the relevant parameters as used in shortcode_table and call the renderer directly, then include rendered HTML in our page
                        * How will graphs work?
                            * Looks like we just need to attach the necessary Chartist attributes to the render options and generate_chart will be called automatically by the renderer
                            * Where does the chart end up though?
                    * Build proof of concept
                        * New page template based on blog post template
                        * New page based on this template
                        * Call function to generate table content
                        * Open DB, fetch some data and convert to CSV
                        * Import into in memory table
                        * Try to call renderer
                        * Embed result in content
                    * How can we define the URL in terms of the player's name?
            * Team summary stats
                * Results
                    * Matches
                        * Won / Lost / NR / Total
                    * Batting
                        * Total runs
                        * Average per innings - is this PCC Par?
                        * Average per wicket
                        * Highest score / lowest score (in losing match)
                        * Distribution of team scores - graph?
                        * Average scores per batting position
                        * 50s, 100s
                        * Ducks, 4s, 6s
                        * Partnerships?
                    * Bowling
                        * Total runs against
                        * Total wickets taken / total wickets available
                        * Average wickets per innings
                        * Average runs per wicket
                        * Highest score against / lowest score against
                        * Distribution of scores against - graph?
                        * Five fors
                        * Total overs, maidens, wides, no balls
                    * Fielding
                        * Fielding catches
                        * Run outs
                        * Wk catches
                        * Stumpings

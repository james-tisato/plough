__TODO__
* Website
    * Infrastructure
        * Upgrades
            * PHP 7.3
                * Server
                * Desktop - including XDebug upgrade
                * Laptop - including XDebug upgrade
    * 2019 season setup
        * Stats
            * When season starts:
                * Update ploughmanscc.com/statistics link
        * Tour
            * Add 2019 details
    * Styling
        * Clean up custom CSS:
            * Change default table style so we don't need to specify centered text for every column
            * Reduce number of table style sections (don't need one per distinct column count)
    * Fixtures
        * Fix bad quotes in match reports
            * 2017
            * 2016
    * Live scores
        * Show "no live games today" text when appropriate
    * History
        * Auto-sizing of roll of honour table?
    * Stats
        * Average sort doesn't work when people have no average
        * Best bowling sort doesn't work properly
        * Fix 2017 page
            * Make table styles consistent
        * Add summary info to 2018 page
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
        * Some kind of mock plugin runner that will kick the tires of the plugin init code?
        * Update period - can we do regular after the weekend but then not much during the week?
        * Logging
            * Start using levels properly
            * Add custom exception handler?
            * Can we do the same for warnings?
        * Switch to class autoloading
    * Stats
        * Bugs
        * Refactoring
            * Consider normalising match counts within DB schema (not just career base statics)
        * Improvements
        * Performance
            * Set up xdebug + cachegrind viewer on laptop
        * New features
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
            * Excel export
        * New tests

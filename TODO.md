__TODO__
* Website
    * Infrastructure
        * Upgrades
            * PHP 7.3
            * Windows machine PHP version?
    * 2019 season setup
        * New mobile menu plugin
            * How does the styling work? How can we make the styles the same as the current menus?
            * Enable mobile menu plugin
            * Set up multiple menu levels
            * Test on phone, tablet, desktop
        * Fixtures
            * Add net bookings
            * Add to multi-season menu list
        * Stats
            * Create page
            * Create 2019 tables and add to page
            * When season starts:
                * Update ploughmanscc.com/statistics link
                * Add to multi-season menu list
        * Tour
            * Add 2019 details
    * Styling
        * Clean up custom CSS:
            * Change default table style so we don't need to specify centered text for every column
            * Reduce number of table style sections (don't need one per distinct column count)
    * Fixtures
        * Link to match reports
            * 2017
            * 2016
        * Link to scorecards
            * 2017
            * 2016
        * Fix bad quotes in match reports
            * 2017
            * 2016
    * Live scores
        * Show "no live games today" text when appropriate
    * History
        * Auto-sizing of roll of honour table?
    * Stats
        * Upload latest plugin and update CSV auto-import paths
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
        * 2019 season setup
            * Check that 2018 test baselines match what we have on the website now
            * Update config to 2019
        * Bugs
        * Refactoring
            * Consider normalising match counts within DB schema (not just career base statics)
        * Improvements
            * Add DB table indices for common queries
        * Performance
            * Set up xdebug + cachegrind viewer on laptop and desktop
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
                    * How can we define the URL in terms of the player's name?
            * Team summary stats
            * League table
                * Scrape SCL site - XML is poorly formed
                * How will we support multiple seasons?
                * Highlight Plough using row highlighter
            * Excel export
        * New tests

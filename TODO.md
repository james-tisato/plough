__TODO__
* Website
    * Infrastructure
        * Upgrades
            * PHP 7.3
    * 2019 season setup
        * New mobile menu plugin
            * How does the styling work? How can we make the styles the same as the current menus?
            * Enable mobile menu plugin
            * Set up multiple menu levels
            * Test on phone, tablet, desktop
        * Fixtures
            * Create page
            * Add net bookings
            * Add fixtures (when Leon has them)
            * Update ploughmanscc.com/fixtures link
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
            * Separate active players from career base
            * Check that 2018 test baselines match what we have on the website now
            * Update config to 2019
        * Bugs
        * Refactoring
            * Improve season support in summary and milestone code
                * Retain career summaries as of end of each season
                * Remove is_null logic for season vs career summaries
            * Normalise career base data - matches
        * New features
            * Add static season summary support
            * Add database views
            * Page per player
            * Team summary stats
            * League table
                * Scrape SCL site
                * Highlight Plough using row highlighter
            * Excel export
        * New tests
            * No update required

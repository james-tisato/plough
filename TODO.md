__TODO__
* Website
    * Infrastructure
        * Upgrades
            * PHP 7.3
            * Wordpress 5.0.2
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
        * Can we make advanced CSS editor window larger? Maybe just expand entire sidebar?
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
        * Add last updated table and insert it on every stats page with generated tables
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
        * Test harness
            * Integrate to packaging script
        * Switch to class autoloading
    * Stats
        * 2019 season setup
            * Check that 2018 test baselines match what we have on the website now
            * Rebase career "base" files to be as-of end of 2018
            * Update all references from 2018 to 2019
        * Bugs
        * Refactoring
            * Move initial stats building into separate functions
            * Split updater into separate files
        * New features
            * Milestones
                * Calculation
                    * Args:
                        * Start value
                        * Current value
                        * Array of milestone values
                    * Returns: (how?)
                        * Achieved milestones
                        * Next milestone
                    * Implementation:
                        * Find starting point in milestones list, using start value
                        * Iterate through milestones until > current value
                        * Return difference
            * Add database views
            * Page per player
            * Team summary stats
            * League table
            * Excel export

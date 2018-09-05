__TODO__
* New features
    * Last updated
      * Set global variables (or util functions to get globals) in plugin for last updated datetime and last included match
      * Set live stats page to use custom page template
      * Modify custom template to include last updated info
      * https://wordpress.stackexchange.com/questions/51145/get-php-variable-from-functions-php-and-echo-it-in-theme-template-files
    * League table
    * Page per player
    * Team summary stats
    * Live career stats
      * Sort out fielding table structure - split career into fielding and keeping?
      * Sort out fielding + keeping table header names
      * Fix styling on site to allow tables to be different widths
      * Updated baseline from Nick, including my own fix
      * Create test baselines manually
      * Refactor load career base into shared function
      * Publish latest plugin to site
      * Update career stats page to include live tables and text indicating this
* Bugs
  * Average sort doesn't work when people have no average
* WP support
  * Some kind of mock plugin runner that will kick the tires of the plugin init code?
* Logging
  * Start using levels properly
  * Add custom exception handler?
    * Can we do the same for warnings?
* Excel export
* Test harness
  * Integrate to packaging script
* Refactoring
  * Move initial stats building into separate functions
  * Switch to class autoloading

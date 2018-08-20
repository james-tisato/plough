__TODO__
* Output tidying
  * Add stats subfolder and update auto-import links
* Logging
  * Use stdout logging only when running locally
  * Start using levels properly
  * Add custom exception handler?
    * Can we do the same for warnings?
* Test harness
  * Test configs
  * Run for all test configs and compare outputs to baselines
  * Integrate to packaging script
* Incremental update
  * Consider how league tables will work - not just Plough
  * Add DB "last updated" table
  * Adjust "from time" in matches URL (with default based on season)
  * For each match found, if already in DB - delete all related entries and process again
  * Clear summary tables and re-populate
* New features
    * League table
    * Page per player
    * Team summary stats
    * Live career stats
* Refactoring
  * Move initial stats building into separate functions
  * Switch to class autoloading
* WP support
  * Some kind of mock plugin runner that will kick the tires of the plugin init code?
__TODO__
* XML-based configuration as input
  * Or JSON?
  * Pass as command line argument
  * Parameters:
    * Input data source
	* Cache data sink
	* Output data sink
	* Use existing DB or create fresh
  * Configs:
    * Default - from web, no caching, output to folder
	* Test - Basic - from file, no caching, output to result
* Output tidying
  * Add stats subfolder and update auto-import links
* Logging
  * PHP install modules via cPanel?
    * Monolog and Wonolog
  * Or write out own based on PSR
  * Add logging throughout
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
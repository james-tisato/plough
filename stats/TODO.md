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
* Logging
  * Bundle proper logging package with our plugin? If not, write our own
  * Add logging throughout
  * Add custom exception handler?
  * Can we do the same for warnings?
* WP Cron plugin
  * Where is my action?
* Plugin
* Integration
  * Plugin hook into Cron job
  * Test Cron job manually and on schedule
  * Add new stats tables in Tablepress
  * Set up auto-import to new tables from CSVs
  * New test page with new tables
  * Deprecate old tables, update stats page with new tables
  * Add fielding tables to stats page
* Test harness
  * Test configs
  * Run for all test configs and compare outputs to baselines
  * Integrate to packaging script
* Incremental update
  * Add DB "last updated" table
  * Adjust "from time" in matches URL (with default based on season)
  * For each match found, if already in DB - delete all related entries and process again
  * Clear summary tables and re-populate
* Refactoring
  * Move initial stats building into separate functions
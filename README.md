# Ploughmans Cricket Club (PCC) statistics
This repo primarily provides a Wordpress plugin that automatically generates the cricket stats tables published at https://ploughmanscclegacy.com/.

## Stats data flow
1. Each week the club enters scorecard data from its matches on the ECB Play-Cricket site: https://ploughmans.play-cricket.com/
    * For example: https://ploughmans.play-cricket.com/website/results/4552843
2. The Wordpress plugin triggers a regular Cron job to check for new matches that have been published since the last update and consumes any new matches it finds:
    * Cron job configuration: [stats/init.php](stats/init.php)
    * Stats update entry point: [stats/updater.php](stats/updater.php)
3. The Play-Cricket API is used to list matches played by the club and fetch all scorecard details:
    * https://play-cricket.ecb.co.uk/hc/en-us/sections/360000978518-API-Experienced-Developers-Only
4. The details of each match are consumed and stored in a SQLite DB:
    * DB schema: [stats/db.php](stats/db.php)
    * Match detail consumption: [stats/match-consumer.php](stats/match-consumer.php)
5. The static career stats for the club, which were calculated outside Play-Cricket up till the end of 2017, are loaded into the DB:
    * Career summary generator: [stats/career-summary-generator.php](stats/career-summary-generator.php) - see "load base" functions
    * Static career summary base data: [stats/static](stats/static)
6. For each season since 2017, a season summary is calculated for each player (using the consumed scorecard data for that season) and stored in the DB:
    * Season summary generator: [stats/season-summary-generator.php](stats/season-summary-generator.php)
7. As each set of season summaries are completed, they are added to the current career summaries for each player to give a new set of career stats up to that point in time:
    * Career summary generator: [stats/career-summary-generator.php](stats/career-summary-generator.php) - see "add season to career summary" functions
8. Once all seasons of scorecards have been processed and summarised, the DB contains:
    * Summary stats for matches / batting / bowling / fielding for each player for the season
    * The latest overall career stats for each player for these categories
9. We also calculate any milestones that an "active" player may be approaching or has already passed for each season:
    * Milestone generator: [stats/milestone-generator.php](stats/milestone-generator.php)
10. We export the summary data from the DB to a set of CSV files for each season and for the latest career summaries:
    * CSV generator: [stats/csv-generator.php](stats/csv-generator.php)
11. The Tablepress plugin on the Wordpress installation is configured to populate a set of tables from the generated CSV files. This Cron job runs periodically as well.
12. When all relevant Tablepress tables have been updated, the latest stats pages on the website are now up to date, e.g.
    * https://ploughmanscclegacy.com/statistics-career/
    * https://ploughmanscclegacy.com/statistics/
    * https://ploughmanscclegacy.com/statistics-2021-partnerships/

The end result is that both season and career stats are automatically updated and visible on the club website within ~15-30 minutes of scorecards being entered each weekend.

## Database
The SQLite DB can be wiped and rebuilt from scratch at any point. We persist it for efficiency but it is wiped each time a new version of the plugin is deployed.

## Configuration
[stats/config](stats/config) contains a couple of configuration files to control operation of the updater:
* [default](stats/config/default.xml) - the configuration used in production on the Wordpress deployment
* [local-test](stats/config/local-test.xml) - the configuration used when running the updater locally with [stats/local-update.php](stats/local-update.php)

The configs allow you to choose between sourcing match data from the web or from file (`InputMapperName`). Match data can also be fetched from the web and dumped to file (`InputDumpDir`) and then used as the input for the next run by setting `InputMapperName` to `file` and `InputMapperDir` to the dump directory.

## Testing
[stats/test.php](stats/test.php) runs a suite of regression tests defined in [stats/test/input](stats/test/input). Each test runs the updater against a set of dumped input data (captured at some point in the past), generates the output CSVs and compares them to a set of baseline output CSVs. Tests fail if the CSV results are not identical.

Individual tests can be run with `php test.php <test-name>`, whereas `php test.php` runs the full suite.

## Packaging
The plugin is packaged for deployment to the Wordpress site using [package.php](package.php) - `php package.php`. The resultant plugin zip can be found in the `dist` directory.

## PHP environment
The version of PHP that the plugin runs on in production is managed by SiteGround where the site is hosted. Currently it uses PHP 7.4.

## Continuous integration
There is a plan to set up automated testing and packaging on circleci at some point in the future. Currently tests are run locally whenever changes are made, and packaging is done locally as well. Plugin deployment is done via the WP Admin UI.

## Updating for a new season
1. Update stats/config/default.xml and stats/config/local-test.xml to have CurrentSeason = <new season year>.
2. Update helpers.php to have an entry for the new season year, depending on what divisions the club is playing that year.
3. Update LEAGUE_TABLE_DIVISON_ID_MAP in data-mapper.php to have the league table ids for the new season.

## Other Notes
### SSL certificates
- On Macbook, downloaded latest cacert.pem from https://curl.se/docs/caextract.html and put it in /usr/local/php5/ssl/cert.pem

### Updating at the start of a new season
1. Create regression test for previous season:
    1. Set input dump dir in local-test.xml to dump to the input folder for the new test, e.g. `<Pair Key="InputDumpDir" Value="{stats_root}/test/input/All-2024" />` - this might take a while.
    2. Run local-update.php to dump the test input into the above folder.
    3. Create test config, e.g. test/test/All-2024.xml, by copying the previous year's file and updating.
    4. Create static data folder in test/static by copying the current data from stats/static.
    5. Run the new test and wait for it to fail (all baseline files are missing).
    6. Copy the result folder that was created in the previous step into the baseline folder.
    7. Run the test again and ensure it passes.
    8. Revert changes to local-test.xml.
2. Update stats/config/default.xml and stats/config/local-test.xml to set current season.
3. Update plugin version in plough.php.
4. Add all new test files and commit changes.
5. Package and deploy.

# Ploughmans Cricket Club (PCC) statistics
This repo primarily provides a Wordpress plugin that automatically generates the cricket stats tables published at https://ploughmanscc.com/.

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


## Notes
### SSL certificates
- On Macbook, downloaded latest cacert.pem from https://curl.se/docs/caextract.html and put it in /usr/local/php5/ssl/cert.pem

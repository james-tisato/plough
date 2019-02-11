<?php
    namespace plough\stats;
    use plough\log;

    require_once(__DIR__ . "/../logger.php");
    require_once(__DIR__ . "/../utils.php");

    require_once("career-summary-generator.php");
    require_once("config.php");
    require_once("csv-generator.php");
    require_once("data-mapper.php");
    require_once("db.php");
    require_once("helpers.php");
    require_once("league-table-consumer.php");
    require_once("match-consumer.php");
    require_once("milestone-generator.php");
    require_once("season-summary-generator.php");

    class Updater
    {
        // Properties
        private $_config;
        private $_db;

        private $_match_consumer;
        private $_league_table_consumer;
        private $_career_summary_generator;
        private $_season_summary_generator;
        private $_milestone_generator;
        private $_csv_generator;

        // Public methods
        public function __construct(Config $config)
        {
            $this->_config = $config;

            // Config
            $db_dir = $this->_config->getDbDir();
            if (!file_exists($db_dir))
                \plough\mkdirs($db_dir);

            $output_dir = $this->_config->getOutputDir();
            if (!file_exists($output_dir))
                \plough\mkdirs($output_dir);

            if ($this->_config->dumpInputs())
            {
                $dump_dir = $this->_config->getInputDumpDir();

                if (!file_exists($dump_dir))
                    \plough\mkdirs($dump_dir);
            }

            log\info("");
            $db_path = \plough\get_stats_db_path($this->_config);
            log\info("Using database path [$db_path]");
            if (file_exists($db_path))
            {
                if ($this->_config->clearDb())
                {
                    log\info("Deleting old database");
                    unlink($db_path);
                    $create_db_schema = true;
                }
                else
                {
                    log\info("Using existing database");
                    $create_db_schema = false;
                }
            }
            else
            {
                log\info("Database does not exist - creating");
                $create_db_schema = true;
            }

            // Open database and create schema if required
            $this->_db = new \SQLite3($db_path);
            db_enable_foreign_keys($this->_db);

            if ($create_db_schema)
            {
                log\info("Creating database schema");
                db_create_schema($this->_db);
            }

            $this->_match_consumer = new MatchConsumer(
                $this->_config, $this->_db
                );
            $this->_league_table_consumer = new LeagueTableConsumer(
                $this->_config, $this->_db
                );
            $this->_career_summary_generator = new CareerSummaryGenerator(
                $this->_config, $this->_db
                );
            $this->_season_summary_generator = new SeasonSummaryGenerator(
                $this->_db
                );
            $this->_milestone_generator = new MilestoneGenerator(
                $this->_db
                );
            $this->_csv_generator = new CsvGenerator(
                $this->_config, $this->_db, $this->_milestone_generator
                );
        }

        public function update_stats()
        {
            log\info("");
            $db = $this->_db;
            $career_base_season = $this->_config->getCareerBaseSeason();
            $first_update_season = $career_base_season + 1;
            $current_season = $this->_config->getCurrentSeason();

            $last_update = get_last_update_datetime($db);
            date_default_timezone_set("Europe/London");
            $current_datetime = date(DATETIME_FORMAT);

            log\info("Consuming league table for $current_season...");
            $this->_league_table_consumer->consume_league_table($current_season);
            log\info("");

            log\info("Career base data calculated up to $career_base_season");
            log\info("Current season is $current_season");
            log\info("Stats will be updated for seasons $first_update_season to $current_season");
            log\info("");

            log\info("Consuming matches since last update...");
            $matches_consumed = false;
            for ($season = $first_update_season; $season <= $current_season; $season++)
            {
                log\info("");
                log\info("  Season $season");
                $matches_consumed_this_season = $this->_match_consumer->consume_matches_since_last_update(
                    $season, $last_update
                    );
                $matches_consumed |= $matches_consumed_this_season;
            }

            if ($matches_consumed)
            {
                log\info("");
                log\info("Clearing existing summary tables...");
                $this->_season_summary_generator->clear_summary_tables();
                $this->_career_summary_generator->clear_summary_tables();
                $this->_milestone_generator->clear_milestones();

                log\info("");
                log\info("Loading career base tables...");
                log\info("  Batting");
                $this->_career_summary_generator->load_batting_career_summary_base();
                log\info("  Bowling");
                $this->_career_summary_generator->load_bowling_career_summary_base();
                log\info("  Fielding");
                $this->_career_summary_generator->load_fielding_career_summary_base();

                log\info("");
                log\info("Copying career base to career summary...");
                $this->_career_summary_generator->copy_base_to_summary_tables();

                log\info("");
                log\info("Building summary tables...");
                for ($season = $first_update_season; $season <= $current_season; $season++)
                {
                    log\info("");
                    log\info("  Season $season");

                    log\info("    Generating season summaries");
                    log\info("      Batting");
                    $this->_season_summary_generator->generate_batting_summary($season);
                    log\info("      Bowling");
                    $this->_season_summary_generator->generate_bowling_summary($season);
                    log\info("      Fielding");
                    $this->_season_summary_generator->generate_fielding_summary($season);

                    log\info("");
                    log\info("    Generating career milestones achieved this season...");
                    $this->_milestone_generator->generate_milestones($season);

                    log\info("");
                    log\info("    Adding season summaries to career summaries...");
                    log\info("      Batting");
                    $this->_career_summary_generator->add_season_to_career_batting_summary($season);
                    log\info("      Bowling");
                    $this->_career_summary_generator->add_season_to_career_bowling_summary($season);
                    log\info("      Fielding");
                    $this->_career_summary_generator->add_season_to_career_fielding_summary($season);
                }

                // Mark DB update
                log\info("");
                log\info("Setting update time in database to [$current_datetime]");
                $insert_update = db_create_insert_update($db);
                $insert_update->bindValue(":UpdateTime", $current_datetime);
                $insert_update->execute();
            }
            else
            {
                log\info("  No update required");
            }

            // Generate outputs
            log\info("");
            log\info("Generating CSV output...");
            for ($season = $first_update_season; $season <= $current_season; $season++)
            {
                log\info("");
                log\info("  Season $season");
                $this->_csv_generator->generate_season_csv_files($season);

                log\info("");
                log\info("  Career to end of $season");
                $this->_csv_generator->generate_career_csv_files($season);
            }

            log\info("");
            log\info("  Other");
            $this->_csv_generator->generate_other_csv_files($current_season);

            log\info("");
        }
    }
?>

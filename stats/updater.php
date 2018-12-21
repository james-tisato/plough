<?php
    namespace plough\stats;

    use plough\log;

    require_once(__DIR__ . "/../logger.php");
    require_once(__DIR__ . "/../utils.php");

    require_once("config.php");
    require_once("data-mapper.php");
    require_once("db.php");

    // Constants
    const CLUB_NAME = "Ploughmans CC";
    const DELETED = "Deleted";
    const SEASON = 2018;
    const NO_PC_PLAYER_ID = -1;
    const DATETIME_FORMAT = "Y-m-d h:m:s";

    // Modes of dismissal
    const DID_NOT_BAT = "did not bat";
    const CAUGHT = "ct";
    const RUN_OUT = "ro";
    const STUMPED = "st";

    // Stats period types
    const PERIOD_CAREER = 1;
    const PERIOD_SEASON = 2;

    // Helpers
    function get_batting_average($runs, $innings, $not_outs)
    {
        $num_outs = $innings - $not_outs;
        if ($num_outs > 0)
            return ($runs / $num_outs);
        else
            return null;
    }

    function get_batting_strike_rate($runs, $balls)
    {
        if ($balls)
            return ($runs / $balls) * 100.0;
        else
            return null;
    }

    function collapse_overs($completed_overs, $partial_balls)
    {
        $partial_overs = floor($partial_balls / 6);
        $partial_balls = $partial_balls % 6;
        $completed_overs += $partial_overs;
        return array($completed_overs, $partial_balls);
    }

    function get_bowling_average($runs, $wickets)
    {
        if ($wickets > 0)
            return ($runs / $wickets);
        else
            return null;
    }

    function get_total_balls($completed_overs, $partial_balls)
    {
        return $completed_overs * 6 + $partial_balls;
    }

    function get_bowling_economy_rate($runs, $completed_overs, $partial_balls)
    {
        $total_balls = get_total_balls($completed_overs, $partial_balls);
        if ($total_balls > 0)
            return ($runs / ($total_balls / 6.0));
        else
            return null;
    }

    function get_bowling_strike_rate($completed_overs, $partial_balls, $wickets)
    {
        $total_balls = get_total_balls($completed_overs, $partial_balls);
        if ($wickets > 0)
            return ($total_balls / $wickets);
        else
            return null;
    }

    class Updater
    {
        // Properties
        private $_config;

        // Public methods
        public function __construct(Config $config)
        {
            $this->_config = $config;
        }

        public function update_stats()
        {
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
                $dump_data_mapper = $this->_config->getInputDumpDataMapper();

                if (!file_exists($dump_dir))
                    \plough\mkdirs($dump_dir);
            }

            $input_mapper = $this->_config->getInputDataMapper();

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
            $db = new \SQLite3($db_path);
            db_enable_foreign_keys($db);

            if ($create_db_schema)
            {
                log\info("Creating database schema");
                db_create_schema($db);
            }

            // Prepare statements
            $insert_update = db_create_insert_update($db);
            $insert_match = db_create_insert_match($db);
            $delete_match = db_create_delete_match($db);
            $insert_player = db_create_insert_player($db);
            $update_player = db_create_update_player($db);
            $insert_player_perf = db_create_insert_player_performance($db);
            $insert_batting_perf = db_create_insert_batting_performance($db);
            $insert_bowling_perf = db_create_insert_bowling_performance($db);
            $insert_fielding_perf = db_create_insert_fielding_performance($db);

            // Set up player cache, seeding it from the database
            $player_cache = array();
            $statement = $db->prepare(
               'SELECT
                     PcPlayerId,
                     PlayerId
                FROM Player
                ORDER BY PlayerId
                ');
            $result = $statement->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC))
                $player_cache[$row["PcPlayerId"]] = $row["PlayerId"];

            // Get match list
            log\info("");
            log\info("Fetching match list...");

            if ($create_db_schema)
            {
                $matches_from_date = "01/01/" . SEASON;
                log\info("  Since the database was created from scratch, fetching matches updated since [$matches_from_date]");
            }
            else
            {
                $statement = $db->prepare(
                   'SELECT
                         UpdateTime
                    FROM DbUpdate
                    ORDER BY UpdateTime DESC
                    LIMIT 1
                    ');
                $last_update_str = $statement->execute()->fetchArray(SQLITE3_ASSOC)["UpdateTime"];
                log\info("  Datebase was last updated at [$last_update_str]");
                $last_update = date_create_from_format(DATETIME_FORMAT, $last_update_str);
                $matches_from_date = $last_update->format('Y-m-d');
                log\info("  Fetching matches since last update date [$matches_from_date]");
            }

            $current_datetime = gmdate(DATETIME_FORMAT);
            $matches_path = $input_mapper->getMatchesPath(SEASON, $matches_from_date);
            $matches_str = file_get_contents($matches_path);

            if ($this->_config->dumpInputs())
                file_put_contents($dump_data_mapper->getMatchesPath(SEASON, $matches_from_date), $matches_str);

            $matches = json_decode($matches_str, true)["matches"];
            $num_matches = count($matches);
            log\info("  $num_matches matches found");
            log\info("");

            if ($num_matches > 0)
            {
                log\info("Fetching match details...");
                foreach ($matches as $match_idx => $match)
                {
                    // Player performance cache for this match
                    $player_perf_cache = array();
                    $pc_match_id = $match["id"];

                    log\info("  Processing match $match_idx (Play-Cricket id $pc_match_id)...");

                    // Get match detail
                    $match_detail_path = $input_mapper->getMatchDetailPath($pc_match_id);
                    $match_detail_str = file_get_contents($match_detail_path);

                    if ($this->_config->dumpInputs())
                        file_put_contents($dump_data_mapper->getMatchDetailPath($pc_match_id), $match_detail_str);

                    $match_detail = json_decode($match_detail_str, true)["match_details"][0];

                    if ($match_detail["status"] == DELETED)
                    {
                        log\info("    Skipping match because it was deleted...");
                    }
                    else if (empty($match_detail["result"]))
                    {
                        log\info("    Skipping match because it is a future fixture...");
                    }
                    else
                    {
                        // Start transaction for deleting whole of match
                        $db->exec('BEGIN');

                        // Delete match (and associated performances) if it has been added to the database before
                        $delete_match->bindValue(":PcMatchId", $pc_match_id);
                        $delete_match->execute();

                        // End transaction for deleting whole of match
                        $db->exec('COMMIT');

                        // Start transaction for adding whole of match
                        $db->exec('BEGIN');

                        // Get team info
                        if ($match_detail["home_club_name"] == CLUB_NAME)
                        {
                            $is_plough_match = 1;
                            $is_plough_home = 1;
                            $plough_team_id = $match_detail["home_team_id"];
                            $players = $match_detail["players"][0]["home_team"];
                        }
                        else if ($match_detail["away_club_name"] == CLUB_NAME)
                        {
                            $is_plough_match = 1;
                            $is_plough_home = 0;
                            $plough_team_id = $match_detail["away_team_id"];
                            $players = $match_detail["players"][1]["away_team"];
                        }
                        else
                        {
                            $is_plough_match = 0;
                            $is_plough_home = 0;
                        }

                        // Insert match
                        $insert_match->bindValue(":PcMatchId", $pc_match_id);
                        $insert_match->bindValue(":Status", $match_detail["status"]);
                        $insert_match->bindValue(":MatchDate", $match_detail["match_date"]);
                        $insert_match->bindValue(":CompetitionType", $match_detail["competition_type"]);
                        $insert_match->bindValue(":HomeClubId", $match_detail["home_club_id"]);
                        $insert_match->bindValue(":HomeClubName", $match_detail["home_club_name"]);
                        $insert_match->bindValue(":HomeTeamId", $match_detail["home_team_id"]);
                        $insert_match->bindValue(":HomeTeamName", $match_detail["home_team_name"]);
                        $insert_match->bindValue(":AwayClubId", $match_detail["away_club_id"]);
                        $insert_match->bindValue(":AwayClubName", $match_detail["away_club_name"]);
                        $insert_match->bindValue(":AwayTeamId", $match_detail["away_team_id"]);
                        $insert_match->bindValue(":AwayTeamName", $match_detail["away_team_name"]);
                        $insert_match->bindValue(":IsPloughMatch", $is_plough_match);
                        $insert_match->bindValue(":IsPloughHome", $is_plough_home);
                        $insert_match->bindValue(":Result", $match_detail["result"]);
                        $insert_match->bindValue(":ResultAppliedTo", $match_detail["result_applied_to"]);
                        $insert_match->bindValue(":TossWonBy", $match_detail["toss_won_by_team_id"]);
                        $insert_match->bindValue(":BattedFirst", $match_detail["batted_first"]);
                        $match_id = db_insert_and_return_id($db, $insert_match);

                        if ($is_plough_match)
                        {
                            foreach ($players as $player)
                            {
                                $pc_player_id = $player["player_id"];
                                $player_name = $player["player_name"];

                                if (!array_key_exists($pc_player_id, $player_cache))
                                {
                                    // Player doesn't exist - insert
                                    $insert_player->bindValue(":PcPlayerId", $pc_player_id);
                                    $insert_player->bindValue(":Name", $player_name);
                                    $insert_player->bindValue(":Active", 1);
                                    $player_id = db_insert_and_return_id($db, $insert_player);
                                    $player_cache[$pc_player_id] = $player_id;
                                }
                                else
                                {
                                    // Player exists - update player name and/or PC player id in case it has changed
                                    // Also mark as active because they have played a game this season
                                    $update_player->bindValue(":PcPlayerId", $pc_player_id);
                                    $update_player->bindValue(":Name", $player_name);
                                    $update_player->bindValue(":Active", 1);
                                    $update_player->execute();
                                }

                                // Insert player performance
                                $player_id = $player_cache[$pc_player_id];
                                $insert_player_perf->bindValue(":MatchId", $match_id);
                                $insert_player_perf->bindValue(":PlayerId", $player_id);
                                $insert_player_perf->bindValue(":Captain", \plough\int_from_bool($player["captain"]));
                                $insert_player_perf->bindValue(":Wicketkeeper", \plough\int_from_bool($player["wicket_keeper"]));
                                $player_perf_id = db_insert_and_return_id($db, $insert_player_perf);
                                $player_perf_cache[$pc_player_id] = $player_perf_id;
                            }

                            $innings = $match_detail["innings"];
                            foreach ($innings as $inning_idx => $inning)
                            {
                                if ($inning["team_batting_id"] == $plough_team_id)
                                {
                                    // Plough batting
                                    $batting_perfs = $inning["bat"];
                                    foreach ($batting_perfs as $batting_perf_idx => $batting_perf)
                                    {
                                        $pc_player_id = $batting_perf["batsman_id"];

                                        if (!empty($pc_player_id))
                                        {
                                            $player_id = $player_cache[$pc_player_id];
                                            $player_perf_id = $player_perf_cache[$pc_player_id];

                                            $how_out = $batting_perf["how_out"];
                                            if ($how_out != DID_NOT_BAT)
                                            {
                                                $insert_batting_perf->bindValue(":PlayerPerformanceId", $player_perf_id);
                                                $insert_batting_perf->bindValue(":PlayerId", $player_id);
                                                $insert_batting_perf->bindValue(":Position", $batting_perf["position"]);
                                                $insert_batting_perf->bindValue(":HowOut", $how_out);
                                                $insert_batting_perf->bindValue(":Runs", $batting_perf["runs"]);
                                                $insert_batting_perf->bindValue(":Balls", $batting_perf["balls"]);
                                                $insert_batting_perf->bindValue(":Fours", $batting_perf["fours"]);
                                                $insert_batting_perf->bindValue(":Sixes", $batting_perf["sixes"]);
                                                $insert_batting_perf->execute();
                                            }
                                        }
                                    }
                                }
                                else
                                {
                                    // Plough bowling
                                    $bowling_perfs = $inning["bowl"];
                                    foreach ($bowling_perfs as $bowling_perf_idx => $bowling_perf)
                                    {
                                        $pc_player_id = $bowling_perf["bowler_id"];
                                        $player_id = $player_cache[$pc_player_id];
                                        $player_perf_id = $player_perf_cache[$pc_player_id];

                                        // Handle full and partial overs
                                        $over_parts = explode(".", $bowling_perf["overs"]);
                                        $completed_overs = $over_parts[0];
                                        if (count($over_parts) > 1)
                                            $partial_balls = $over_parts[1];
                                        else
                                            $partial_balls = 0;

                                        $insert_bowling_perf->bindValue(":PlayerPerformanceId", $player_perf_id);
                                        $insert_bowling_perf->bindValue(":PlayerId", $player_id);
                                        $insert_bowling_perf->bindValue(":Position", $bowling_perf_idx + 1);
                                        $insert_bowling_perf->bindValue(":CompletedOvers", $completed_overs);
                                        $insert_bowling_perf->bindValue(":PartialBalls", $partial_balls);
                                        $insert_bowling_perf->bindValue(":Maidens", $bowling_perf["maidens"]);
                                        $insert_bowling_perf->bindValue(":Runs", $bowling_perf["runs"]);
                                        $insert_bowling_perf->bindValue(":Wickets", $bowling_perf["wickets"]);
                                        $insert_bowling_perf->bindValue(":Wides", $bowling_perf["wides"]);
                                        $insert_bowling_perf->bindValue(":NoBalls", $bowling_perf["no_balls"]);
                                        $insert_bowling_perf->execute();
                                    }

                                    // Plough fielding
                                    $player_to_fielding = array();

                                    $batting_perfs = $inning["bat"];
                                    foreach ($batting_perfs as $batting_perf_idx => $batting_perf)
                                    {
                                        $pc_player_id = $batting_perf["fielder_id"];
                                        if (!empty($pc_player_id))
                                        {
                                            if (!array_key_exists($pc_player_id, $player_to_fielding))
                                            {
                                                $player_to_fielding[$pc_player_id] = array(
                                                    "catches" => 0,
                                                    "run_outs" => 0,
                                                    "stumpings" => 0
                                                    );
                                            }

                                            $how_out = $batting_perf["how_out"];
                                            if ($how_out == CAUGHT)
                                                $player_to_fielding[$pc_player_id]["catches"]++;
                                            else if ($how_out == RUN_OUT)
                                                $player_to_fielding[$pc_player_id]["run_outs"]++;
                                            else if ($how_out == STUMPED)
                                                $player_to_fielding[$pc_player_id]["stumpings"]++;
                                        }
                                    }

                                    foreach($player_to_fielding as $pc_player_id => $fielding)
                                    {
                                        $player_id = $player_cache[$pc_player_id];
                                        $player_perf_id = $player_perf_cache[$pc_player_id];
                                        $insert_fielding_perf->bindValue(":PlayerPerformanceId", $player_perf_id);
                                        $insert_fielding_perf->bindValue(":PlayerId", $player_id);
                                        $insert_fielding_perf->bindValue(":Catches", $fielding["catches"]);
                                        $insert_fielding_perf->bindValue(":RunOuts", $fielding["run_outs"]);
                                        $insert_fielding_perf->bindValue(":Stumpings", $fielding["stumpings"]);
                                        $insert_fielding_perf->execute();
                                    }
                                }
                            }
                        }

                        // End transaction for adding whole of match
                        $db->exec('COMMIT');
                    }
                }

                // Build summaries
                log\info("");
                log\info("Loading career base tables...");
                log\info("  Batting");
                $this->load_batting_career_summary_base($db);
                log\info("  Bowling");
                $this->load_bowling_career_summary_base($db);
                log\info("  Fielding");
                $this->load_fielding_career_summary_base($db);

                log\info("");
                log\info("Building summary tables...");
                log\info("  Batting");
                log\info("    Season " . SEASON);
                $this->generate_batting_summary($db);
                log\info("    Career");
                $this->generate_career_batting_summary($db);
                log\info("  Bowling");
                log\info("    Season " . SEASON);
                $this->generate_bowling_summary($db);
                log\info("    Career");
                $this->generate_career_bowling_summary($db);
                log\info("  Fielding");
                log\info("    Season " . SEASON);
                $this->generate_fielding_summary($db);
                log\info("    Career");
                $this->generate_career_fielding_summary($db);

                log\info("");
                log\info("Generating milestones...");
                $this->generate_milestones($db);

                // Mark DB update
                log\info("");
                log\info("Setting update time in database to [$current_datetime]");
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
            log\info("  Batting");
            log\info("    Season " . SEASON);
            $this->generate_batting_summary_csv($db, PERIOD_SEASON);
            log\info("    Career");
            $this->generate_batting_summary_csv($db, PERIOD_CAREER);
            log\info("  Bowling");
            log\info("    Season " . SEASON);
            $this->generate_bowling_summary_csv($db, PERIOD_SEASON);
            log\info("    Career");
            $this->generate_bowling_summary_csv($db, PERIOD_CAREER);
            log\info("  Fielding");
            log\info("    Season " . SEASON);
            $this->generate_fielding_summary_csv($db, PERIOD_SEASON);
            log\info("    Career");
            $this->generate_fielding_summary_csv($db, PERIOD_CAREER);
            log\info("  Keeping");
            log\info("    Season " . SEASON);
            $this->generate_keeping_summary_csv($db, PERIOD_SEASON);
            log\info("    Career");
            $this->generate_keeping_summary_csv($db, PERIOD_CAREER);
        }

        // Private helpers
        private function get_players_by_name($db)
        {
            $players = array();
            $statement = $db->prepare(
                'SELECT * FROM Player ORDER BY Name'
                );
            $result = $statement->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC))
                $players[$row["Name"]] = $row;

            return $players;
        }

        private function generate_csv_output($output_name, $header, $statement)
        {
            $output_dir = $this->_config->getOutputDir();
            $out = fopen("$output_dir/$output_name.csv", "w");
            \plough\fputcsv_eol($out, $header);

            $result = $statement->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC))
            {
                $formatted_row = array();
                foreach ($row as $key => $value)
                {
                    $formatted_value = $value;

                    if (is_null($value))
                        $formatted_value = "-";
                    else if (is_float($value))
                        $formatted_value = sprintf("%.2f", $value);

                    array_push($formatted_row, $formatted_value);
                }

                \plough\fputcsv_eol($out, $formatted_row);
            }
            fclose($out);
        }

        private function generate_career_summary(
            $db,
            $summary_type,
            $insert_career_summary,
            $combine_career_base_and_season
            )
        {
            db_truncate_table($db, "Career" . $summary_type . "Summary");
            $players = $this->get_players_by_name($db);

            foreach ($players as $player_name => $player)
            {
                $player_id = $player["PlayerId"];

                // Career base
                $statement = $db->prepare(
                    'SELECT * FROM Career' . $summary_type . 'SummaryBase WHERE PlayerId = :PlayerId'
                    );
                $statement->bindValue(":PlayerId", $player_id);
                $career_base = $statement->execute()->fetchArray(SQLITE3_ASSOC);

                // Season
                $statement = $db->prepare(
                    'SELECT * FROM ' . $summary_type . 'Summary WHERE PlayerId = :PlayerId'
                    );
                $statement->bindValue(":PlayerId", $player_id);
                $season = $statement->execute()->fetchArray(SQLITE3_ASSOC);

                $career_summary = null;
                if (!empty($career_base))
                {
                    if (!empty($season))
                    {
                        // Sum career base and season
                        $career_summary = $combine_career_base_and_season($career_base, $season);
                        $career_summary["PlayerId"] = $player_id;
                    }
                    else
                    {
                        // No season - use career base
                        $career_summary = $career_base;
                    }
                }
                else if (!empty($season))
                {
                    // No career base - use season
                    $career_summary = $season;
                }

                if ($career_summary)
                {
                    db_bind_values_from_row($insert_career_summary, $career_summary);
                    $insert_career_summary->execute();
                }
            }
        }

        private function load_career_summary_base(
            $db,
            $summary_type,
            $insert_career_summary_base,
            $bind_row_to_insert
            )
        {
            $players = $this->get_players_by_name($db);

            db_truncate_table($db, "Career" . $summary_type . "SummaryBase");
            $insert_player = db_create_insert_player($db);

            $career_base_path = $this->_config->getStaticDir() . "/career-stats-" . strtolower($summary_type) . "-end-" . (SEASON - 1) . ".csv";
            log\debug("    $career_base_path");
            if (file_exists($career_base_path))
            {
                $base = fopen($career_base_path, "r");
                while ($row = fgetcsv($base))
                {
                    if ($row[0] == "Player")
                    {
                        $idx = array_flip($row);
                    }
                    else
                    {
                        $name = $row[$idx["Player"]];
                        if (!array_key_exists($name, $players))
                        {
                            $active = $row[$idx["Active"]];
                            if ($active == "Y")
                                $active = 1;
                            else
                                $active = 0;

                            $insert_player->bindValue(":PcPlayerId", NO_PC_PLAYER_ID);
                            $insert_player->bindValue(":Name", $name);
                            $insert_player->bindValue(":Active", $active);
                            $player_id = db_insert_and_return_id($db, $insert_player);
                        }
                        else
                        {
                            $player_id = $players[$name]["PlayerId"];
                        }

                        $bind_row_to_insert($row, $idx, $player_id, $insert_career_summary_base);
                        $insert_career_summary_base->execute();
                    }
                }

                fclose($base);
            }
            else
            {
                log\warning("      File not found");
            }
        }

        private function load_batting_career_summary_base($db)
        {
            $bind_row_to_insert = function ($row, $idx, $player_id, $insert_career_batting_summary_base)
            {
                $high_score = $row[$idx["HS"]];
                $high_score_not_out = (strpos($high_score, "*") !== false);
                $high_score = str_replace("*", "", $high_score);

                $innings = $row[$idx["Inns"]];
                $not_outs = $row[$idx["NO"]];
                $runs = $row[$idx["Runs"]];
                $average = get_batting_average($runs, $innings, $not_outs);
                $balls_str = $row[$idx["Balls"]];
                $balls = (empty($balls_str) ? null : $balls_str);
                $strike_rate = get_batting_strike_rate($runs, $balls);

                $insert_career_batting_summary_base->bindValue(":PlayerId", $player_id);
                $insert_career_batting_summary_base->bindValue(":Matches", $row[$idx["Mat"]]);
                $insert_career_batting_summary_base->bindValue(":Innings", $innings);
                $insert_career_batting_summary_base->bindValue(":NotOuts", $not_outs);
                $insert_career_batting_summary_base->bindValue(":Runs", $runs);
                $insert_career_batting_summary_base->bindValue(":Average", $average);
                $insert_career_batting_summary_base->bindValue(":StrikeRate", $strike_rate);
                $insert_career_batting_summary_base->bindValue(":HighScore", $high_score);
                $insert_career_batting_summary_base->bindValue(":HighScoreNotOut", $high_score_not_out);
                $insert_career_batting_summary_base->bindValue(":Fifties", $row[$idx["50s"]]);
                $insert_career_batting_summary_base->bindValue(":Hundreds", $row[$idx["100s"]]);
                $insert_career_batting_summary_base->bindValue(":Ducks", $row[$idx["0s"]]);
                $insert_career_batting_summary_base->bindValue(":Balls", $balls);
                $insert_career_batting_summary_base->bindValue(":Fours", $row[$idx["4s"]]);
                $insert_career_batting_summary_base->bindValue(":Sixes", $row[$idx["6s"]]);
            };

            $insert_career_batting_summary_base = db_create_insert_career_batting_summary_base($db);
            $this->load_career_summary_base($db, "Batting", $insert_career_batting_summary_base, $bind_row_to_insert);
        }

        private function generate_batting_summary($db)
        {
            $players = $this->get_players_by_name($db);

            db_truncate_table($db, "BattingSummary");
            $insert_batting_summary = db_create_insert_batting_summary($db);

            foreach ($players as $player_name => $player)
            {
                $player_id = $player["PlayerId"];

                // Filter
                $db->query('
                    DROP TABLE IF EXISTS IncludedPerformance
                    ');
                $db->query(
                    'CREATE TEMPORARY TABLE IncludedPerformance (
                        PlayerPerformanceId INTEGER PRIMARY KEY
                        )
                    ');
                $statement = $db->prepare(
                    'INSERT INTO IncludedPerformance
                     SELECT
                        pp.PlayerPerformanceId
                     FROM PlayerPerformance pp
                     INNER JOIN Match m on m.MatchId = pp.MatchId
                     LEFT JOIN BattingPerformance bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
                     WHERE
                            pp.PlayerId = :PlayerId
                        --and m.CompetitionType != \'League\'
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $statement->execute();

                // Basic fields
                $statement = $db->prepare(
                    'SELECT
                         p.PlayerId
                        ,COUNT(pp.PlayerPerformanceId) AS Matches
                        ,COUNT(bp.BattingPerformanceId) AS Innings
                        ,SUM(CASE bp.HowOut
                            WHEN "no" THEN 1
                            WHEN "rh" THEN 1
                            ELSE 0 END) AS NotOuts
                        ,SUM(bp.Runs) AS Runs
                        ,(CAST(SUM(bp.Runs) AS FLOAT) / (COUNT(bp.BattingPerformanceId) - SUM(CASE bp.HowOut WHEN "no" THEN 1 WHEN "rh" THEN 1 ELSE 0 END))) AS Average
                        ,((CAST(SUM(bp.Runs) AS FLOAT) / SUM(bp.Balls)) * 100.0) AS StrikeRate
                        ,SUM(CASE WHEN bp.Runs >= 50 AND bp.Runs < 100 THEN 1 ELSE 0 END) AS Fifties
                        ,SUM(CASE WHEN bp.Runs >= 100 THEN 1 ELSE 0 END) AS Hundreds
                        ,SUM(CASE WHEN bp.Runs = 0 and bp.HowOut <> "no" THEN 1 ELSE 0 END) AS Ducks
                        ,SUM(bp.Balls) as Balls
                        ,SUM(bp.Fours) as Fours
                        ,SUM(bp.Sixes) as Sixes
                    FROM Player p
                    INNER JOIN PlayerPerformance pp on pp.PlayerId = p.PlayerId
                    --INNER JOIN IncludedPerformance ip on ip.PlayerPerformanceId = pp.PlayerPerformanceId
                    LEFT JOIN BattingPerformance bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
                    WHERE
                            p.PlayerId = :PlayerId
                    GROUP BY p.PlayerId, p.Name
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                if (empty($result))
                    continue;

                db_bind_values_from_row($insert_batting_summary, $result);

                // High score
                $statement = $db->prepare(
                   'SELECT
                         p.PlayerId as PlayerId
                        ,bp.Runs as HighScore
                        ,(CASE bp.HowOut
                            WHEN "no" THEN 1
                            WHEN "rh" THEN 1
                            ELSE 0 END) as HighScoreNotOut
                    FROM Player p
                    INNER JOIN PlayerPerformance pp on pp.PlayerId = p.PlayerId
                    INNER JOIN IncludedPerformance ip on ip.PlayerPerformanceId = pp.PlayerPerformanceId
                    LEFT JOIN BattingPerformance bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
                    WHERE
                            p.PlayerId = :PlayerId
                    ORDER BY HighScore DESC, HighScoreNotOut DESC
                    LIMIT 1
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                db_bind_values_from_row($insert_batting_summary, $result);

                // Insert
                $insert_batting_summary->execute();
            }
        }

        private function generate_career_batting_summary($db)
        {
            $combine = function($career_base, $season)
            {
                $career_summary = array();
                $career_summary["Matches"] = $career_base["Matches"] + $season["Matches"];
                $career_summary["Innings"] = $career_base["Innings"] + $season["Innings"];
                $career_summary["NotOuts"] = $career_base["NotOuts"] + $season["NotOuts"];
                $career_summary["Runs"] = $career_base["Runs"] + $season["Runs"];

                $career_summary["Average"] = get_batting_average(
                    $career_summary["Runs"], $career_summary["Innings"], $career_summary["NotOuts"]
                    );

                if ($career_base["Balls"])
                {
                    $career_summary["Balls"] = $career_base["Balls"] + $season["Balls"];
                    $career_summary["StrikeRate"] = get_batting_strike_rate($career_summary["Runs"], $career_summary["Balls"]);
                }
                else
                {
                    $career_summary["Balls"] = null;
                    $career_summary["StrikeRate"] = null;
                }

                if ($career_base["HighScore"] > $season["HighScore"])
                {
                    $career_summary["HighScore"] = $career_base["HighScore"];
                    $career_summary["HighScoreNotOut"] = $career_base["HighScoreNotOut"];
                }
                else if ($season["HighScore"] > $career_base["HighScore"])
                {
                    $career_summary["HighScore"] = $season["HighScore"];
                    $career_summary["HighScoreNotOut"] = $season["HighScoreNotOut"];
                }
                else
                {
                    $career_summary["HighScore"] = $season["HighScore"];
                    $career_summary["HighScoreNotOut"] = max($career_base["HighScoreNotOut"], $season["HighScoreNotOut"]);
                }

                $career_summary["Fifties"] = $career_base["Fifties"] + $season["Fifties"];
                $career_summary["Hundreds"] = $career_base["Hundreds"] + $season["Hundreds"];
                $career_summary["Ducks"] = $career_base["Ducks"] + $season["Ducks"];
                $career_summary["Fours"] = $career_base["Fours"] + $season["Fours"];
                $career_summary["Sixes"] = $career_base["Sixes"] + $season["Sixes"];

                return $career_summary;
            };

            $this->generate_career_summary(
                $db,
                "Batting",
                db_create_insert_career_batting_summary($db),
                $combine
                );
        }

        private function generate_batting_summary_csv($db, $period_type)
        {
            if ($period_type == PERIOD_CAREER)
            {
                $table_name = "CareerBattingSummary";
                $output_name = "batting_career_ind_summary";
            }
            else if ($period_type == PERIOD_SEASON)
            {
                $table_name = "BattingSummary";
                $output_name = "batting_ind_summary";
            }

            $header = array(
                "Player", "Mat", "Inns", "NO", "Runs", "Ave", "SR",
                "HS", "50s", "100s", "0s", "4s", "6s", "Balls", "Active"
                );

            $statement = $db->prepare(
               'SELECT
                      p.Name
                     ,bs.Matches
                     ,bs.Innings
                     ,bs.NotOuts
                     ,bs.Runs
                     ,bs.Average
                     ,bs.StrikeRate
                     ,(CAST(bs.HighScore AS TEXT) || CASE bs.HighScoreNotOut WHEN 1 THEN \'*\' ELSE \'\' END) as HighScore
                     ,bs.Fifties
                     ,bs.Hundreds
                     ,bs.Ducks
                     ,bs.Fours
                     ,bs.Sixes
                     ,bs.Balls
                     ,CASE p.Active WHEN 1 THEN "Y" ELSE "N" END AS Active
                FROM Player p
                INNER JOIN ' . $table_name . ' bs on bs.PlayerId = p.PlayerId
                WHERE bs.Innings > 0
                ORDER by bs.Runs DESC, bs.Average DESC, bs.Innings DESC, bs.NotOuts DESC, bs.Matches DESC, p.Name
                ');

            $this->generate_csv_output($output_name, $header, $statement);
        }

        private function load_bowling_career_summary_base($db)
        {
            $bind_row_to_insert = function ($row, $idx, $player_id, $insert_career_bowling_summary_base)
            {
                $total_overs = explode(".", $row[$idx["Overs"]]);
                if (count($total_overs) == 2)
                    $partial_balls = $total_overs[1];
                else
                    $partial_balls = 0;
                $completed_overs = $total_overs[0];

                $runs = $row[$idx["Runs"]];
                $wickets = $row[$idx["Wkts"]];
                $average = get_bowling_average($runs, $wickets);
                $economy_rate = get_bowling_economy_rate($runs, $completed_overs, $partial_balls);
                $strike_rate = get_bowling_strike_rate($completed_overs, $partial_balls, $wickets);

                $insert_career_bowling_summary_base->bindValue(":PlayerId", $player_id);
                $insert_career_bowling_summary_base->bindValue(":Matches", $row[$idx["Mat"]]);
                $insert_career_bowling_summary_base->bindValue(":CompletedOvers", $completed_overs);
                $insert_career_bowling_summary_base->bindValue(":PartialBalls", $partial_balls);
                $insert_career_bowling_summary_base->bindValue(":Maidens", $row[$idx["Mdns"]]);
                $insert_career_bowling_summary_base->bindValue(":Runs", $runs);
                $insert_career_bowling_summary_base->bindValue(":Wickets", $wickets);
                $insert_career_bowling_summary_base->bindValue(":Average", $average);
                $insert_career_bowling_summary_base->bindValue(":EconomyRate", $economy_rate);
                $insert_career_bowling_summary_base->bindValue(":StrikeRate", $strike_rate);
                $insert_career_bowling_summary_base->bindValue(":BestBowlingWickets", $row[$idx["Best wkts"]]);
                $insert_career_bowling_summary_base->bindValue(":BestBowlingRuns", $row[$idx["Best runs"]]);
                $insert_career_bowling_summary_base->bindValue(":FiveFors", $row[$idx["5wi"]]);
                $insert_career_bowling_summary_base->bindValue(":Wides", $row[$idx["Wides"]]);
                $insert_career_bowling_summary_base->bindValue(":NoBalls", $row[$idx["NBs"]]);
            };

            $insert_career_bowling_summary_base = db_create_insert_career_bowling_summary_base($db);
            $this->load_career_summary_base($db, "Bowling", $insert_career_bowling_summary_base, $bind_row_to_insert);
        }

        private function generate_bowling_summary($db)
        {
            $players = $this->get_players_by_name($db);

            db_truncate_table($db, "BowlingSummary");
            $insert_bowling_summary = db_create_insert_bowling_summary($db);

            foreach ($players as $player_name => $player)
            {
                $player_id = $player["PlayerId"];

                // Filter
                $db->query('
                    DROP TABLE IF EXISTS IncludedPerformance
                    ');
                $db->query(
                    'CREATE TEMPORARY TABLE IncludedPerformance (
                        PlayerPerformanceId INTEGER PRIMARY KEY
                        )
                    ');
                $statement = $db->prepare(
                   'INSERT INTO IncludedPerformance
                    SELECT
                        pp.PlayerPerformanceId
                    FROM PlayerPerformance pp
                    INNER JOIN Match m on m.MatchId = pp.MatchId
                    LEFT JOIN BowlingPerformance bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
                    WHERE
                            pp.PlayerId = :PlayerId
                        --and bp.Position in (1, 2)
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $statement->execute();

                // Basic fields
                $statement = $db->prepare(
                   'SELECT
                         p.PlayerId
                        ,COUNT(pp.PlayerPerformanceId) AS Matches
                        ,SUM(bp.Maidens) as Maidens
                        ,SUM(bp.Runs) AS Runs
                        ,SUM(bp.Wickets) AS Wickets
                        ,(CAST(SUM(bp.Runs) AS FLOAT) / (SUM(bp.Wickets))) AS Average
                        ,SUM(CASE WHEN bp.Wickets >= 5 THEN 1 ELSE 0 END) AS FiveFors
                        ,SUM(bp.Wides) as Wides
                        ,SUM(bp.NoBalls) as NoBalls
                    FROM Player p
                    INNER JOIN PlayerPerformance pp on pp.PlayerId = p.PlayerId
                    INNER JOIN IncludedPerformance ip on ip.PlayerPerformanceId = pp.PlayerPerformanceId
                    LEFT JOIN BowlingPerformance bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
                    WHERE
                            p.PlayerId = :PlayerId
                    GROUP BY p.PlayerId, p.Name
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                if (empty($result))
                    continue;

                $runs = $result["Runs"];
                $wickets = $result["Wickets"];
                db_bind_values_from_row($insert_bowling_summary, $result);

                // Overs, balls, economy rate, strike rate
                $statement = $db->prepare(
                   'SELECT
                         p.PlayerId
                        ,SUM(bp.CompletedOvers) as CompletedOvers
                        ,SUM(bp.PartialBalls) as PartialBalls
                    FROM Player p
                    INNER JOIN PlayerPerformance pp on pp.PlayerId = p.PlayerId
                    INNER JOIN IncludedPerformance ip on ip.PlayerPerformanceId = pp.PlayerPerformanceId
                    LEFT JOIN BowlingPerformance bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
                    WHERE
                        p.PlayerId = :PlayerId
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                $collapsed_overs = collapse_overs($result["CompletedOvers"], $result["PartialBalls"]);
                $completed_overs = $collapsed_overs[0];
                $partial_balls = $collapsed_overs[1];

                $economy_rate = get_bowling_economy_rate($runs, $completed_overs, $partial_balls);
                $strike_rate = get_bowling_strike_rate($completed_overs, $partial_balls, $wickets);
                $insert_bowling_summary->bindValue(":CompletedOvers", $completed_overs);
                $insert_bowling_summary->bindValue(":PartialBalls", $partial_balls);
                $insert_bowling_summary->bindValue(":EconomyRate", $economy_rate);
                $insert_bowling_summary->bindValue(":StrikeRate", $strike_rate);

                // Best bowling
                $statement = $db->prepare(
                   'SELECT
                         p.PlayerId as player_id
                        ,bp.Wickets as BestBowlingWickets
                        ,bp.Runs as BestBowlingRuns
                    FROM Player p
                    INNER JOIN PlayerPerformance pp on pp.PlayerId = p.PlayerId
                    INNER JOIN IncludedPerformance ip on ip.PlayerPerformanceId = pp.PlayerPerformanceId
                    LEFT JOIN BowlingPerformance bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
                    WHERE
                            p.PlayerId = :PlayerId
                    ORDER BY BestBowlingWickets DESC, BestBowlingRuns ASC
                    LIMIT 1
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                db_bind_values_from_row($insert_bowling_summary, $result);

                // Insert
                $insert_bowling_summary->execute();
            }
        }

        private function generate_career_bowling_summary($db)
        {
            $combine = function($career_base, $season)
            {
                $career_summary = array();
                $career_summary["Matches"] = $career_base["Matches"] + $season["Matches"];
                $collapsed_overs = collapse_overs(
                    $career_base["CompletedOvers"] + $season["CompletedOvers"],
                    $career_base["PartialBalls"] + $season["PartialBalls"]
                    );
                $career_summary["CompletedOvers"] = $collapsed_overs[0];
                $career_summary["PartialBalls"] = $collapsed_overs[1];
                $career_summary["Maidens"] = $career_base["Maidens"] + $season["Maidens"];
                $career_summary["Runs"] = $career_base["Runs"] + $season["Runs"];
                $career_summary["Wickets"] = $career_base["Wickets"] + $season["Wickets"];
                $career_summary["Average"] = get_bowling_average($career_summary["Runs"], $career_summary["Wickets"]);
                $career_summary["EconomyRate"] = get_bowling_economy_rate(
                    $career_summary["Runs"], $career_summary["CompletedOvers"], $career_summary["PartialBalls"]
                    );
                $career_summary["StrikeRate"] = get_bowling_strike_rate(
                    $career_summary["CompletedOvers"], $career_summary["PartialBalls"], $career_summary["Wickets"]
                    );

                if ($career_base["BestBowlingWickets"] > $season["BestBowlingWickets"])
                {
                    $career_summary["BestBowlingWickets"] = $career_base["BestBowlingWickets"];
                    $career_summary["BestBowlingRuns"] = $career_base["BestBowlingRuns"];
                }
                else if ($season["BestBowlingWickets"] > $career_base["BestBowlingWickets"])
                {
                    $career_summary["BestBowlingWickets"] = $season["BestBowlingWickets"];
                    $career_summary["BestBowlingRuns"] = $season["BestBowlingRuns"];
                }
                else
                {
                    $career_summary["BestBowlingWickets"] = $season["BestBowlingWickets"];
                    $career_summary["BestBowlingRuns"] = min($career_base["BestBowlingRuns"], $season["BestBowlingRuns"]);
                }

                $career_summary["FiveFors"] = $career_base["FiveFors"] + $season["FiveFors"];
                $career_summary["Wides"] = $career_base["Wides"] + $season["Wides"];
                $career_summary["NoBalls"] = $career_base["NoBalls"] + $season["NoBalls"];

                return $career_summary;
            };

            $this->generate_career_summary(
                $db,
                "Bowling",
                db_create_insert_career_bowling_summary($db),
                $combine
                );
        }

        private function generate_bowling_summary_csv($db, $period_type)
        {
            if ($period_type == PERIOD_CAREER)
            {
                $table_name = "CareerBowlingSummary";
                $output_name = "bowling_career_ind_summary";
            }
            else if ($period_type == PERIOD_SEASON)
            {
                $table_name = "BowlingSummary";
                $output_name = "bowling_ind_summary";
            }

            $header = array(
                "Player", "Mat", "Overs", "Mdns", "Runs", "Wkts", "Ave",
                "Econ", "SR", "Best", "5wi", "Wides", "NBs", "Active"
                );

            $statement = $db->prepare(
                'SELECT
                      p.Name
                     ,bs.Matches
                     ,(CAST(bs.CompletedOvers AS TEXT) || \'.\' || CAST(bs.PartialBalls AS TEXT)) as Overs
                     ,bs.Maidens
                     ,bs.Runs
                     ,bs.Wickets
                     ,bs.Average
                     ,bs.EconomyRate
                     ,bs.StrikeRate
                     ,(CAST(bs.BestBowlingWickets AS TEXT) || \'/\' || CAST(bs.BestBowlingRuns AS TEXT)) as BestBowling
                     ,bs.FiveFors
                     ,bs.Wides
                     ,bs.NoBalls
                     ,CASE p.Active WHEN 1 THEN "Y" ELSE "N" END AS Active
                FROM Player p
                INNER JOIN ' . $table_name . ' bs on bs.PlayerId = p.PlayerId
                WHERE
                        (bs.CompletedOvers > 0 OR bs.PartialBalls > 0)
                ORDER by bs.Wickets DESC, bs.Average, bs.EconomyRate
                ');

            $this->generate_csv_output($output_name, $header, $statement);
        }

        private function load_fielding_career_summary_base($db)
        {
            $bind_row_to_insert = function ($row, $idx, $player_id, $insert_career_fielding_summary_base)
            {
                $catches_fielding = $row[$idx["Ct"]];
                $run_outs = $row[$idx["RO"]];
                $total_fielding = $catches_fielding + $run_outs;
                $catches_keeping = $row[$idx["Wk Ct"]];
                $stumpings = $row[$idx["St"]];
                $total_keeping = $catches_keeping + $stumpings;

                $insert_career_fielding_summary_base->bindValue(":PlayerId", $player_id);
                $insert_career_fielding_summary_base->bindValue(":Matches", $row[$idx["Mat"]]);
                $insert_career_fielding_summary_base->bindValue(":CatchesFielding", $catches_fielding);
                $insert_career_fielding_summary_base->bindValue(":RunOuts", $run_outs);
                $insert_career_fielding_summary_base->bindValue(":TotalFieldingWickets", $total_fielding);
                $insert_career_fielding_summary_base->bindValue(":CatchesKeeping", $catches_keeping);
                $insert_career_fielding_summary_base->bindValue(":Stumpings", $stumpings);
                $insert_career_fielding_summary_base->bindValue(":TotalKeepingWickets", $total_keeping);
            };

            $insert_career_fielding_summary_base = db_create_insert_career_fielding_summary_base($db);
            $this->load_career_summary_base($db, "Fielding", $insert_career_fielding_summary_base, $bind_row_to_insert);
        }

        private function generate_fielding_summary($db)
        {
            $players = $this->get_players_by_name($db);

            db_truncate_table($db, "FieldingSummary");
            $insert_fielding_summary = db_create_insert_fielding_summary($db);

            foreach ($players as $player)
            {
                $player_id = $player["PlayerId"];

                // filter

                // Basic fields
                $statement = $db->prepare(
                   'SELECT
                         p.PlayerId as PlayerId
                        ,COUNT(pp.PlayerPerformanceId) AS Matches
                        ,SUM(CASE WHEN pp.Wicketkeeper = 0 THEN fp.Catches ELSE 0 END) as CatchesFielding
                        ,SUM(fp.RunOuts) as RunOuts
                        ,SUM(CASE WHEN pp.Wicketkeeper = 1 THEN fp.Catches ELSE 0 END) as CatchesKeeping
                        ,SUM(fp.Stumpings) as Stumpings
                    FROM Player p
                    INNER JOIN PlayerPerformance pp on pp.PlayerId = p.PlayerId
                    LEFT JOIN FieldingPerformance fp on fp.PlayerPerformanceId = pp.PlayerPerformanceId
                    WHERE
                            p.PlayerId = :PlayerId
                    GROUP BY p.PlayerId, p.Name
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                if (empty($result))
                    continue;
                db_bind_values_from_row($insert_fielding_summary, $result);

                // Totals
                $total_fielding = $result["CatchesFielding"] + $result["RunOuts"];
                $insert_fielding_summary->bindValue(":TotalFieldingWickets", $total_fielding);
                $total_keeping = $result["CatchesKeeping"] + $result["Stumpings"];
                $insert_fielding_summary->bindValue(":TotalKeepingWickets", $total_keeping);

                // Insert
                $insert_fielding_summary->execute();
            }
        }

        private function generate_career_fielding_summary($db)
        {
            $combine = function($career_base, $season)
            {
                $career_summary = array();
                $career_summary["Matches"] = $career_base["Matches"] + $season["Matches"];
                $career_summary["CatchesFielding"] = $career_base["CatchesFielding"] + $season["CatchesFielding"];
                $career_summary["RunOuts"] = $career_base["RunOuts"] + $season["RunOuts"];
                $career_summary["TotalFieldingWickets"] = $career_base["TotalFieldingWickets"] + $season["TotalFieldingWickets"];
                $career_summary["CatchesKeeping"] = $career_base["CatchesKeeping"] + $season["CatchesKeeping"];
                $career_summary["Stumpings"] = $career_base["Stumpings"] + $season["Stumpings"];
                $career_summary["TotalKeepingWickets"] = $career_base["TotalKeepingWickets"] + $season["TotalKeepingWickets"];

                return $career_summary;
            };

            $this->generate_career_summary(
                $db,
                "Fielding",
                db_create_insert_career_fielding_summary($db),
                $combine
                );
        }

        private function generate_fielding_summary_csv($db, $period_type)
        {
            if ($period_type == PERIOD_CAREER)
            {
                $table_name = "CareerFieldingSummary";
                $output_name = "fielding_career_ind_summary";
            }
            else if ($period_type == PERIOD_SEASON)
            {
                $table_name = "FieldingSummary";
                $output_name = "fielding_ind_summary";
            }

            $header = array(
                "Player", "Mat", "Ct", "RO", "Total", "Active"
                );

            $statement = $db->prepare(
               'SELECT
                      p.Name
                     ,fs.Matches
                     ,fs.CatchesFielding
                     ,fs.RunOuts
                     ,fs.TotalFieldingWickets
                     ,CASE p.Active WHEN 1 THEN "Y" ELSE "N" END AS Active
                FROM Player p
                INNER JOIN ' . $table_name . ' fs on fs.PlayerId = p.PlayerId
                WHERE
                        fs.TotalFieldingWickets > 0
                ORDER by fs.TotalFieldingWickets DESC, fs.CatchesFielding DESC, fs.Matches DESC, p.Name
                ');

            $this->generate_csv_output($output_name, $header, $statement);
        }

        private function generate_keeping_summary_csv($db, $period_type)
        {
            if ($period_type == PERIOD_CAREER)
            {
                $table_name = "CareerFieldingSummary";
                $output_name = "keeping_career_ind_summary";
            }
            else if ($period_type == PERIOD_SEASON)
            {
                $table_name = "FieldingSummary";
                $output_name = "keeping_ind_summary";
            }

            $header = array(
                "Player", "Mat", "Wk Ct", "St", "Wk Total", "Active"
                );

            $statement = $db->prepare(
               'SELECT
                      p.Name
                     ,fs.Matches
                     ,fs.CatchesKeeping
                     ,fs.Stumpings
                     ,fs.TotalKeepingWickets
                     ,CASE p.Active WHEN 1 THEN "Y" ELSE "N" END AS Active
                FROM Player p
                INNER JOIN ' . $table_name . ' fs on fs.PlayerId = p.PlayerId
                WHERE
                        fs.TotalKeepingWickets > 0
                ORDER by fs.TotalKeepingWickets DESC, fs.CatchesKeeping DESC, fs.Matches DESC, p.Name
                ');

            $this->generate_csv_output($output_name, $header, $statement);
        }

        private function generate_milestones($db)
        {
            $insert_milestone = db_create_insert_milestone($db);

            $statement = $db->prepare(
               'SELECT
                     p.PlayerId
                    ,bas.matches
                    ,bas.runs
                    ,bas.Fifties
                    ,bas.Hundreds
                FROM Player p
                INNER JOIN CareerBattingSummary bas ON bas.PlayerId = p.PlayerId
                INNER JOIN CareerBowlingSummary bos ON bos.PlayerId = p.PlayerId
                INNER JOIN CareerFieldingSummary fs ON fs.PlayerId = p.PlayerId
                ORDER BY p.PlayerId
                ');
            $result = $statement->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC))
            {

            }
        }
    }
?>

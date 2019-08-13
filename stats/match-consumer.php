<?php
    namespace plough\stats;
    use plough\log;

    require_once(__DIR__ . "/../logger.php");
    require_once("config.php");
    require_once("db.php");
    require_once("helpers.php");

    // Constants
    const CLUB_NAME = "Ploughmans CC";
    const DELETED = "Deleted";
    const PC_DATE_FORMAT = "d/m/Y";
    const UNSURE_NAME = "Unsure";

    // Modes of dismissal
    const DID_NOT_BAT = "did not bat";
    const CAUGHT = "ct";
    const RUN_OUT = "run out";
    const STUMPED = "st";

    class MatchConsumer
    {
        // Properties
        private $_config;
        private $_db;

        private $_player_cache;

        // Public functions
        public function __construct($config, $db)
        {
            $this->_config = $config;
            $this->_db = $db;

            // Set up player cache, seeding it from the database
            $this->_player_cache = array();
            $statement = $db->prepare(
               'SELECT
                     PcPlayerId,
                     PlayerId
                FROM Player
                ORDER BY PlayerId
                ');
            $result = $statement->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC))
                $this->_player_cache[$row["PcPlayerId"]] = $row["PlayerId"];
        }

        // Consumes all matches that have changed since the last update
        public function consume_matches_since_last_update($season, $last_update)
        {
            $db = $this->_db;
            $input_mapper = $this->_config->getInputDataMapper();

            log\info("    Fetching match list...");
            if (is_null($last_update))
            {
                $matches_from_date = "01/01/" . $season;
                log\info("      Since the database was created from scratch, fetching matches updated since [$matches_from_date]");
            }
            else
            {
                $last_update_str = $last_update->format(DATETIME_FORMAT);
                log\info("      Datebase was last updated at [$last_update_str]");
                $matches_from_date = $last_update->format('Y-m-d');
                log\info("      Fetching matches since last update date [$matches_from_date]");
            }

            $matches_path = $input_mapper->getMatchesPath($season, $matches_from_date);
            //log\debug("        " . $matches_path);
            $matches_str = file_get_contents($matches_path);

            if ($this->_config->dumpInputs())
            {
                $matches_path = $this->_config->getInputDumpDataMapper()->getMatchesPath(
                    $season, $matches_from_date
                    );
                $matches_dir = dirname($matches_path);

                if (!file_exists($matches_dir))
                    \plough\mkdirs($matches_dir);

                file_put_contents($matches_path, $matches_str);
            }

            $matches_list = json_decode($matches_str, true);
            if (array_key_exists("result_summary", $matches_list))
                $matches = $matches_list["result_summary"];
            else
                $matches = $matches_list["matches"];

            $num_matches = count($matches);
            log\info("      $num_matches matches found");
            log\info("");

            if ($num_matches > 0)
            {
                $this->consume_matches($season, $matches);

                // At least one match consumed
                return true;
            }
            else
            {
                // No update done
                return false;
            }
        }

        // Private functions
        private function consume_matches($season, $matches)
        {
            $db = $this->_db;
            $insert_match = db_create_insert_match($db);
            $delete_match = db_create_delete_match($db);
            $input_mapper = $this->_config->getInputDataMapper();

            log\info("    Fetching match details...");
            foreach ($matches as $match_idx => $match)
            {
                $pc_match_id = $match["id"];
                log\info("      Processing match $match_idx (Play-Cricket id $pc_match_id)...");

                // Get match detail
                $match_detail_path = $input_mapper->getMatchDetailPath($season, $pc_match_id);
                $match_detail_str = file_get_contents($match_detail_path);

                if ($this->_config->dumpInputs())
                {
                    $match_detail_path = $this->_config->getInputDumpDataMapper()->getMatchDetailPath(
                        $season, $pc_match_id
                        );
                    $match_detail_dir = dirname($match_detail_path);

                    if (!file_exists($match_detail_dir))
                        \plough\mkdirs($match_detail_dir);

                    file_put_contents($match_detail_path, $match_detail_str);
                }

                $match_detail = json_decode($match_detail_str, true)["match_details"][0];

                if ($match_detail["status"] == DELETED)
                {
                    log\info("        Skipping match because it was deleted...");
                }
                else if (empty($match_detail["result"]))
                {
                    log\info("        Skipping match because it is a future fixture...");
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

                    $match_date = date_create_from_format(
                        PC_DATE_FORMAT,
                        $match_detail["match_date"]
                        );
                    $match_date_str = $match_date->format(DATE_FORMAT);

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
                    $insert_match->bindValue(":Season", $season);
                    $insert_match->bindValue(":MatchDate", $match_date_str);
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
                        $this->consume_player_performances(
                            $players, $match_id, $match_detail, $plough_team_id
                            );
                    }

                    // End transaction for adding whole of match
                    $db->exec('COMMIT');
                }
            }
        }

        private function consume_player_performances($players, $match_id, $match_detail, $plough_team_id)
        {
            $db = $this->_db;
            $insert_player = db_create_insert_player($db);
            $update_player = db_create_update_player($db);
            $insert_player_perf = db_create_insert_player_performance($db);

            // Player performance cache for this match
            $player_perf_cache = array();

            foreach ($players as $player)
            {
                $pc_player_id = $player["player_id"];
                $player_name = $player["player_name"];

                if ($player_name != UNSURE_NAME)
                {
                    if (!array_key_exists($pc_player_id, $this->_player_cache))
                    {
                        // Player doesn't exist - insert
                        $insert_player->bindValue(":PcPlayerId", $pc_player_id);
                        $insert_player->bindValue(":Name", $player_name);
                        $insert_player->bindValue(":Active", 1);
                        $player_id = db_insert_and_return_id($db, $insert_player);
                        $this->_player_cache[$pc_player_id] = $player_id;
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
                    $player_id = $this->_player_cache[$pc_player_id];
                    $insert_player_perf->bindValue(":MatchId", $match_id);
                    $insert_player_perf->bindValue(":PlayerId", $player_id);
                    $insert_player_perf->bindValue(":Captain", \plough\int_from_bool($player["captain"]));
                    $insert_player_perf->bindValue(":Wicketkeeper", \plough\int_from_bool($player["wicket_keeper"]));
                    $player_perf_id = db_insert_and_return_id($db, $insert_player_perf);
                    $player_perf_cache[$pc_player_id] = $player_perf_id;
                }
            }

            $innings = $match_detail["innings"];
            foreach ($innings as $inning_idx => $inning)
            {
                if ($inning["team_batting_id"] == $plough_team_id)
                {
                    // Plough batting
                    $batting_perfs = $inning["bat"];
                    $this->consume_batting_performances($batting_perfs, $player_perf_cache);
                }
                else
                {
                    // Plough bowling
                    $bowling_perfs = $inning["bowl"];
                    $this->consume_bowling_performances($bowling_perfs, $player_perf_cache);

                    // Plough fielding
                    $oppo_batting_perfs = $inning["bat"];
                    $this->consume_fielding_performances($oppo_batting_perfs, $player_perf_cache);
                }
            }
        }

        private function consume_batting_performances($batting_perfs, $player_perf_cache)
        {
            $db = $this->_db;
            $insert_batting_perf = db_create_insert_batting_performance($db);

            foreach ($batting_perfs as $batting_perf_idx => $batting_perf)
            {
                $pc_player_id = $batting_perf["batsman_id"];
                $pc_player_name = $batting_perf["batsman_name"];

                if (!empty($pc_player_id) && $pc_player_name != UNSURE_NAME)
                {
                    $player_id = $this->_player_cache[$pc_player_id];
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

        private function consume_bowling_performances($bowling_perfs, $player_perf_cache)
        {
            $db = $this->_db;
            $insert_bowling_perf = db_create_insert_bowling_performance($db);
            foreach ($bowling_perfs as $bowling_perf_idx => $bowling_perf)
            {
                $pc_player_id = $bowling_perf["bowler_id"];
                $player_name = $bowling_perf["bowler_name"];

                if ($player_name != UNSURE_NAME)
                {
                    $player_id = $this->_player_cache[$pc_player_id];
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
            }
        }

        private function consume_fielding_performances($oppo_batting_perfs, $player_perf_cache)
        {
            $db = $this->_db;
            $insert_fielding_perf = db_create_insert_fielding_performance($db);

            $player_to_fielding = array();
            foreach ($oppo_batting_perfs as $oppo_batting_perf_idx => $oppo_batting_perf)
            {
                $pc_player_id = $oppo_batting_perf["fielder_id"];
                $player_name = $oppo_batting_perf["fielder_name"];

                if (!empty($pc_player_id) && $player_name != UNSURE_NAME)
                {
                    if (!array_key_exists($pc_player_id, $player_to_fielding))
                    {
                        $player_to_fielding[$pc_player_id] = array(
                            "catches" => 0,
                            "run_outs" => 0,
                            "stumpings" => 0
                            );
                    }

                    $how_out = $oppo_batting_perf["how_out"];
                    if ($how_out == CAUGHT)
                        $player_to_fielding[$pc_player_id]["catches"]++;
                    else if ($how_out == RUN_OUT)
                        $player_to_fielding[$pc_player_id]["run_outs"]++;
                    else if ($how_out == STUMPED)
                        $player_to_fielding[$pc_player_id]["stumpings"]++;
                }
            }

            foreach ($player_to_fielding as $pc_player_id => $fielding)
            {
                $player_id = $this->_player_cache[$pc_player_id];
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
?>

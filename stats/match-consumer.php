<?php
    namespace plough\stats;
    use plough\log;

    require_once(__DIR__ . "/../logger.php");
    require_once("config.php");
    require_once("db.php");
    require_once("helpers.php");

    // Constants
    const ABANDONED = "Abandoned";
    const DELETED = "Deleted";
    const UNSURE_NAME = "Unsure";

    // Modes of dismissal
    const DID_NOT_BAT = "did not bat";
    const CAUGHT = "ct";
    const RUN_OUT = "run out";
    const STUMPED = "st";
    const NOT_OUT = "not out";

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
            $matches_str = safe_file_get_contents($matches_path);

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
                $match_detail_str = safe_file_get_contents($match_detail_path);

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
                else if ($match_detail["result_description"] == ABANDONED && 
                         empty($match_detail["innings"][0]["overs"]) &&
                         empty($match_detail["innings"][1]["overs"]))
                {
                    log\info("        Skipping match because it was abandoned without a ball being bowled...");
                }
                else if (strpos(strtolower($match_detail["match_notes"]), "excluded") !== false)
                {
                    log\info("        Skipping match because it is marked as excluded from Plough stats...");
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
                    $match_result = $match_detail["result"];
                    $result_applied_to_team_id = $match_detail["result_applied_to"];
                    $toss_won_by_team_id = $match_detail["toss_won_by_team_id"];
                    $batted_first_team_id = $match_detail["batted_first"];

                    // Get team info
                    if ($match_detail["home_club_name"] == CLUB_NAME)
                    {
                        $plough_club_id = $match_detail["home_club_id"];
                        $plough_team_id = $match_detail["home_team_id"];
                        $plough_team_name = $match_detail["home_team_name"];
                        $plough_match = 1;
                        $plough_home = 1;
                        $players = $match_detail["players"][0]["home_team"];
                        $oppo_club_id = $match_detail["away_club_id"];
                        $oppo_club_name = $match_detail["away_club_name"];
                        $oppo_team_id = $match_detail["away_team_id"];
                        $oppo_team_name = $match_detail["away_team_name"];
                    }
                    else if ($match_detail["away_club_name"] == CLUB_NAME)
                    {
                        $plough_club_id = $match_detail["away_club_id"];
                        $plough_team_id = $match_detail["away_team_id"];
                        $plough_team_name = $match_detail["away_team_name"];
                        $plough_match = 1;
                        $plough_home = 0;
                        $players = $match_detail["players"][1]["away_team"];
                        $oppo_club_id = $match_detail["home_club_id"];
                        $oppo_club_name = $match_detail["home_club_name"];
                        $oppo_team_id = $match_detail["home_team_id"];
                        $oppo_team_name = $match_detail["home_team_name"];
                    }
                    else
                    {
                        $plough_club_id = NULL;
                        $plough_team_id = NULL;
                        $plough_team_name = NULL;
                        $plough_match = 0;
                        $plough_home = NULL;
                        $plough_won_match = NULL;
                        $plough_won_toss = NULL;
                        $plough_batted_first = NULL;
                        $oppo_club_id = NULL;
                        $oppo_club_name = NULL;
                        $oppo_team_id = NULL;
                        $oppo_team_name = NULL;
                    }

                    if ($plough_match)
                    {
                        $plough_won_match = ($match_result == 'W' && $result_applied_to_team_id == $plough_team_id) ? 1 : 0;
                        $plough_won_toss = ($toss_won_by_team_id == $plough_team_id) ? 1 : 0;
                        $plough_batted_first = ($batted_first_team_id == $plough_team_id) ? 1 : 0;
                    }

                    if (strpos(strtolower($match_detail["match_notes"]), "tour") !== false)
                        $competition_type = "Tour";
                    else
                        $competition_type = $match_detail["competition_type"];

                    // Insert match
                    $insert_match->bindValue(":PcMatchId", $pc_match_id);
                    $insert_match->bindValue(":Status", $match_detail["status"]);
                    $insert_match->bindValue(":Season", $season);
                    $insert_match->bindValue(":MatchDate", $match_date_str);
                    $insert_match->bindValue(":CompetitionType", $competition_type);
                    $insert_match->bindValue(":HomeClubId", $match_detail["home_club_id"]);
                    $insert_match->bindValue(":HomeClubName", $match_detail["home_club_name"]);
                    $insert_match->bindValue(":HomeTeamId", $match_detail["home_team_id"]);
                    $insert_match->bindValue(":HomeTeamName", $match_detail["home_team_name"]);
                    $insert_match->bindValue(":AwayClubId", $match_detail["away_club_id"]);
                    $insert_match->bindValue(":AwayClubName", $match_detail["away_club_name"]);
                    $insert_match->bindValue(":AwayTeamId", $match_detail["away_team_id"]);
                    $insert_match->bindValue(":AwayTeamName", $match_detail["away_team_name"]);
                    $insert_match->bindValue(":PloughClubId", $plough_club_id);
                    $insert_match->bindValue(":PloughTeamId", $plough_team_id);
                    $insert_match->bindValue(":PloughTeamName", $plough_team_name);
                    $insert_match->bindValue(":PloughMatch", $plough_match);
                    $insert_match->bindValue(":PloughHome", $plough_home);
                    $insert_match->bindValue(":PloughWonMatch", $plough_won_match);
                    $insert_match->bindValue(":PloughWonToss", $plough_won_toss);
                    $insert_match->bindValue(":PloughBattedFirst", $plough_batted_first);
                    $insert_match->bindValue(":OppoClubId", $oppo_club_id);
                    $insert_match->bindValue(":OppoClubName", $oppo_club_name);
                    $insert_match->bindValue(":OppoTeamId", $oppo_team_id);
                    $insert_match->bindValue(":OppoTeamName", $oppo_team_name);
                    $insert_match->bindValue(":Result", $match_result);
                    $insert_match->bindValue(":ResultAppliedToTeamId", $result_applied_to_team_id);
                    $insert_match->bindValue(":TossWonByTeamId", $toss_won_by_team_id);
                    $insert_match->bindValue(":BattedFirstTeamId", $batted_first_team_id);
                    $match_id = db_insert_and_return_id($db, $insert_match);

                    if ($plough_match)
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
            $update_player = db_create_update_player_name($db);
            $insert_player_perf = db_create_insert_player_performance($db);

            // Player performance cache for this match
            $player_perf_cache = array();

            foreach ($players as $player)
            {
                $pc_player_id = $player["player_id"];
                $player_name = $player["player_name"];

                if ($player_name != UNSURE_NAME)
                {
                    // We don't set the Active flag for players here - it is done later
                    if (!array_key_exists($pc_player_id, $this->_player_cache))
                    {
                        // Player doesn't exist - insert
                        $insert_player->bindValue(":PcPlayerId", $pc_player_id);
                        $insert_player->bindValue(":Name", $player_name);
                        $insert_player->bindValue(":Active", 0);
                        $player_id = db_insert_and_return_id($db, $insert_player);
                        $this->_player_cache[$pc_player_id] = $player_id;
                    }
                    else
                    {
                        // Player exists - update player name in case it has changed
                        $update_player->bindValue(":PcPlayerId", $pc_player_id);
                        $update_player->bindValue(":Name", $player_name);
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
                    $batting_perf_cache = $this->consume_batting_performances($batting_perfs, $player_perf_cache);

                    $this->consume_batting_partnerships($inning, $batting_perf_cache);
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
            $batting_perf_cache = array();

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
                        $batting_perf_id = db_insert_and_return_id($db, $insert_batting_perf);
                        $batting_perf_cache[$pc_player_id] = $batting_perf_id;
                    }
                }
            }

            return $batting_perf_cache;
        }

        private function consume_batting_partnerships($inning, $batting_perf_cache)
        {
            $db = $this->_db;
            $insert_batting_partnership = db_create_insert_batting_partnership($db);

            $total_wickets = intval($inning["wickets"]);
            $total_runs = intval($inning["runs"]);
            $batting_perfs = $inning["bat"];
            $batting_fow = $inning["fow"];

            // Only try to process partnership data if no wickets were lost or
            // we actually have FOW data available
            if ($total_wickets === 0 || count($batting_fow) > 0)
            {
                if ($total_wickets < 10)
                {
                    // Find not out batsmen
                    $not_out_batting_perfs = array();
                    foreach($batting_perfs as $batting_perf)
                    {
                        if ($batting_perf["how_out"] === NOT_OUT)
                        {
                            array_push($not_out_batting_perfs, $batting_perf);
                            if (count($not_out_batting_perfs) === 2)
                                break;
                        }
                    }

                    if (count($not_out_batting_perfs) == 2)
                    {
                        // Insert final not out partnership as another FOW entry to make the
                        // loop below easier
                        $final_fow = array(
                            "batsman_out_id" => $not_out_batting_perfs[0]["batsman_id"],
                            "batsman_out_name" => $not_out_batting_perfs[0]["batsman_name"],
                            "batsman_in_id" => $not_out_batting_perfs[1]["batsman_id"],
                            "batsman_in_name" => $not_out_batting_perfs[1]["batsman_name"],
                            "wickets" => $total_wickets + 1,
                            "runs" => $total_runs
                        );
                        array_push($batting_fow, $final_fow);
                    }
                }

                $last_runs = 0;
                $last_wicket = 0;
                foreach ($batting_fow as $fow)
                {
                    $pc_player_id_out = $fow["batsman_out_id"];
                    $pc_player_name_out = $fow["batsman_out_name"];
                    $pc_player_id_in = $fow["batsman_in_id"];
                    $pc_player_name_in = $fow["batsman_in_name"];
                    $wicket = intval($fow["wickets"]);
                    $runs_at_wicket = $fow["runs"] == "" ? null : intval($fow["runs"]);

                    if (!is_null($last_runs) && !is_null($runs_at_wicket) && ($wicket == $last_wicket + 1) &&
                        !empty($pc_player_id_out) && $pc_player_name_out != UNSURE_NAME &&
                        !empty($pc_player_id_in) && $pc_player_name_in != UNSURE_NAME)
                    {
                        $batting_perf_id_out = $batting_perf_cache[$pc_player_id_out];
                        $batting_perf_id_in = $batting_perf_cache[$pc_player_id_in];
                        $partnership_runs = $runs_at_wicket - $last_runs;
                        $not_out = $wicket > $total_wickets;

                        $insert_batting_partnership->bindValue(":BattingPerformanceIdOut", $batting_perf_id_out);
                        $insert_batting_partnership->bindValue(":BattingPerformanceIdIn", $batting_perf_id_in);
                        $insert_batting_partnership->bindValue(":Wicket", $wicket);
                        $insert_batting_partnership->bindValue(":Runs", $partnership_runs);
                        $insert_batting_partnership->bindValue(":NotOut", $not_out);
                        $insert_batting_partnership->execute();
                    }

                    $last_runs = $runs_at_wicket;
                    $last_wicket = $wicket;
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
            foreach ($player_perf_cache as $pc_player_id => $player_perf_id)
            {
                // Every player in the match gets a fielding performance, even if they didn't get any
                // fielding stats, since they still fielded
                $player_to_fielding[$pc_player_id] = array(
                    "catches" => 0,
                    "run_outs" => 0,
                    "stumpings" => 0
                    );
            }

            foreach ($oppo_batting_perfs as $oppo_batting_perf_idx => $oppo_batting_perf)
            {
                $pc_player_id = $oppo_batting_perf["fielder_id"];
                $player_name = $oppo_batting_perf["fielder_name"];

                if (!empty($pc_player_id) && $player_name != UNSURE_NAME)
                {
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

<?php
    namespace plough\stats;
    use plough\log;

    require_once(__DIR__ . "/../logger.php");
    require_once("config.php");
    require_once("db.php");
    require_once("helpers.php");
    require_once("summary-aggregator.php");

    class CareerSummaryGenerator
    {
        // Properties
        private $_config;
        private $_db;
        private $_aggregator;

        // Public functions
        public function __construct($config, $db)
        {
            $this->_config = $config;
            $this->_db = $db;
            $this->_aggregator = new SummaryAggregator($db, PERIOD_CAREER, PERIOD_SEASON, PERIOD_CAREER);
        }

        public function clear_summary_tables()
        {
            $db = $this->_db;

            db_truncate_table($db, "CareerMatchesSummary");
            db_truncate_table($db, "CareerMatchesSummaryBase");

            db_truncate_table($db, "CareerBattingSummary");
            db_truncate_table($db, "CareerBattingSummaryBase");

            db_truncate_table($db, "CareerBowlingSummary");
            db_truncate_table($db, "CareerBowlingSummaryBase");

            db_truncate_table($db, "CareerFieldingSummary");
            db_truncate_table($db, "CareerFieldingSummaryBase");
        }

        public function clear_career_partnerships()
        {
            // Clearing the historical matches containing the partnerships is enough
            // to clear the partnerships and all intermediate data using the
            // delete cascades
            $db = $this->_db;
            $statement = $db->prepare(
                'DELETE FROM Match WHERE Season <= ' . $this->_config->getCareerBaseSeason()
                );
            $statement->execute();
        }

        public function load_career_summary_bases()
        {
            log\info("  Matches");
            $this->load_matches_career_summary_base();
            log\info("  Batting");
            $this->load_batting_career_summary_base();
            log\info("  Bowling");
            $this->load_bowling_career_summary_base();
            log\info("  Fielding");
            $this->load_fielding_career_summary_base();
            log\info("  Partnerships");
            $this->load_career_partnerships();
        }

        public function copy_base_to_summary_tables()
        {
            $db = $this->_db;
            $this->copy_base_to_summary("Matches", db_create_insert_career_matches_summary($db));
            $this->copy_base_to_summary("Batting", db_create_insert_career_batting_summary($db));
            $this->copy_base_to_summary("Bowling", db_create_insert_career_bowling_summary($db));
            $this->copy_base_to_summary("Fielding", db_create_insert_career_fielding_summary($db));
        }

        public function add_season_to_career_summaries($season)
        {
            $this->_aggregator->aggregate_summaries($season - 1, "Regular", $season, "Regular", $season, "Regular");
            $this->_aggregator->aggregate_summaries($season - 1, "Tour", $season, "Tour", $season, "Tour");
        }

        // Matches
        private function load_matches_career_summary_base()
        {
            $db = $this->_db;

            $bind_row_to_insert = function ($row, $idx, $player_id, $insert_career_matches_summary_base)
            {
                $insert_career_matches_summary_base->bindValue(":PlayerId", $player_id);
                $insert_career_matches_summary_base->bindValue(":Matches", $row[$idx["Mat"]]);
                $insert_career_matches_summary_base->bindValue(":MatchesCaptaining", 0);
                $insert_career_matches_summary_base->bindValue(":MatchesFielding", 0);
                $insert_career_matches_summary_base->bindValue(":MatchesKeeping", 0);
            };

            $insert_career_matches_summary_base = db_create_insert_career_matches_summary_base($db);
            $this->load_career_summary_base("Matches", $insert_career_matches_summary_base, $bind_row_to_insert);
        }

        // Batting
        private function load_batting_career_summary_base()
        {
            $db = $this->_db;

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
                $insert_career_batting_summary_base->bindValue(":Innings", $innings);
                $insert_career_batting_summary_base->bindValue(":NotOuts", $not_outs);
                $insert_career_batting_summary_base->bindValue(":Runs", $runs);
                $insert_career_batting_summary_base->bindValue(":Average", $average);
                $insert_career_batting_summary_base->bindValue(":StrikeRate", $strike_rate);
                $insert_career_batting_summary_base->bindValue(":HighScore", $high_score);
                $insert_career_batting_summary_base->bindValue(":HighScoreNotOut", $high_score_not_out);
                $insert_career_batting_summary_base->bindValue(":HighScoreMatchId", NULL);
                $insert_career_batting_summary_base->bindValue(":Fifties", $row[$idx["50s"]]);
                $insert_career_batting_summary_base->bindValue(":Hundreds", $row[$idx["100s"]]);
                $insert_career_batting_summary_base->bindValue(":Ducks", $row[$idx["0s"]]);
                $insert_career_batting_summary_base->bindValue(":Balls", $balls);
                $insert_career_batting_summary_base->bindValue(":Fours", $row[$idx["4s"]]);
                $insert_career_batting_summary_base->bindValue(":Sixes", $row[$idx["6s"]]);
            };

            $insert_career_batting_summary_base = db_create_insert_career_batting_summary_base($db);
            $this->load_career_summary_base("Batting", $insert_career_batting_summary_base, $bind_row_to_insert);
        }

        // Bowling
        private function load_bowling_career_summary_base()
        {
            $db = $this->_db;

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
                $insert_career_bowling_summary_base->bindValue(":BestBowlingMatchId", NULL);
                $insert_career_bowling_summary_base->bindValue(":FiveFors", $row[$idx["5wi"]]);
                $insert_career_bowling_summary_base->bindValue(":Wides", $row[$idx["Wides"]]);
                $insert_career_bowling_summary_base->bindValue(":NoBalls", $row[$idx["NBs"]]);
            };

            $insert_career_bowling_summary_base = db_create_insert_career_bowling_summary_base($db);
            $this->load_career_summary_base("Bowling", $insert_career_bowling_summary_base, $bind_row_to_insert);
        }

        // Fielding
        private function load_fielding_career_summary_base()
        {
            $db = $this->_db;

            $bind_row_to_insert = function ($row, $idx, $player_id, $insert_career_fielding_summary_base)
            {
                $catches_fielding = $row[$idx["Ct"]];
                $run_outs = $row[$idx["RO"]];
                $total_fielding = $catches_fielding + $run_outs;
                $catches_keeping = $row[$idx["Wk Ct"]];
                $stumpings = $row[$idx["St"]];
                $total_keeping = $catches_keeping + $stumpings;

                $insert_career_fielding_summary_base->bindValue(":PlayerId", $player_id);
                $insert_career_fielding_summary_base->bindValue(":CatchesFielding", $catches_fielding);
                $insert_career_fielding_summary_base->bindValue(":RunOuts", $run_outs);
                $insert_career_fielding_summary_base->bindValue(":TotalFieldingWickets", $total_fielding);
                $insert_career_fielding_summary_base->bindValue(":CatchesKeeping", $catches_keeping);
                $insert_career_fielding_summary_base->bindValue(":Stumpings", $stumpings);
                $insert_career_fielding_summary_base->bindValue(":TotalKeepingWickets", $total_keeping);
            };

            $insert_career_fielding_summary_base = db_create_insert_career_fielding_summary_base($db);
            $this->load_career_summary_base("Fielding", $insert_career_fielding_summary_base, $bind_row_to_insert);
        }

        // Private functions
        private function copy_base_to_summary(
            $summary_type,
            $insert_career_summary
            )
        {
            $db = $this->_db;

            $statement = $db->prepare(
                'SELECT * FROM Career' . $summary_type . 'SummaryBase'
                );
            $result = $statement->execute();

            $db->exec('BEGIN');
            while ($row = $result->fetchArray(SQLITE3_ASSOC))
            {
                db_bind_values_from_row($insert_career_summary, $row);
                $insert_career_summary->execute();
            }
            $db->exec('COMMIT');
        }

        private function load_career_summary_base(
            $summary_type,
            $insert_career_summary_base,
            $bind_row_to_insert
            )
        {
            $db = $this->_db;
            $career_base_season = $this->_config->getCareerBaseSeason();
            $players = get_players_by_name($db);

            $career_base_path =
                $this->_config->getStaticDir() . "/" . $this->get_career_base_filename($summary_type);

            if (file_exists($career_base_path))
            {
                $db->exec('BEGIN');

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
                        $player_id = $this->create_or_get_player_id($name, $players);
                        $insert_career_summary_base->bindValue(":Season", $career_base_season);
                        $bind_row_to_insert($row, $idx, $player_id, $insert_career_summary_base);
                        $insert_career_summary_base->bindValue(":MatchType", "Regular");
                        $insert_career_summary_base->execute();
                    }
                }

                $db->exec('COMMIT');
                fclose($base);
            }
            else
            {
                log\warning("      File not found");
            }
        }

        public function load_career_partnerships()
        {
            $db = $this->_db;
            $players = get_players_by_name($db);
            $partnerships_path =
                $this->_config->getStaticDir() . "/" . $this->get_career_base_filename("partnerships");

            if (file_exists($partnerships_path))
            {
                $db->exec('BEGIN');

                $match_cache = array();
                $perf_cache = array();
                $base = fopen($partnerships_path, "r");
                while ($row = fgetcsv($base))
                {
                    if ($row[0] == "Wicket")
                    {
                        $idx = array_flip($row);
                    }
                    else
                    {
                        // Get or insert match
                        $pc_match_id = $row[$idx["PcMatchId"]] === "" ? NULL : intval($row[$idx["PcMatchId"]]);
                        $match_date = date_create_from_format(EXCEL_DATE_FORMAT, $row[$idx["Date"]]);
                        $match_date_str = $match_date->format(DATE_FORMAT);
                        $oppo = $row[$idx["Opposition"]];
                        $match_cache_key = "{$match_date_str}_{$oppo}";
                        if (!array_key_exists($match_cache_key, $match_cache))
                        {
                            $insert_match = db_create_insert_match($db);
                            $insert_match->bindValue(":PcMatchId", $pc_match_id);
                            $insert_match->bindValue(":Season", intval($match_date->format('Y')));
                            $insert_match->bindValue(":MatchDate", $match_date_str);
                            $insert_match->bindValue(":CompetitionType", $row[$idx["Type"]]);
                            $insert_match->bindValue(":PloughTeamName", $row[$idx["Team"]]);
                            $insert_match->bindValue(":PloughMatch", true);
                            $insert_match->bindValue(":OppoClubName", $oppo);
                            $match_id = db_insert_and_return_id($db, $insert_match);
                            $match_cache[$match_cache_key] = $match_id;
                        }
                        else
                        {
                            $match_id = $match_cache[$match_cache_key];
                        }

                        // Get or insert players and performances
                        $batting_perf_id_out = NULL;
                        $batting_perf_id_in = NULL;
                        $performance_out = array($row[$idx["Batsman Out"]], $row[$idx["Score Out"]], intval($row[$idx["Position Out"]]), true);
                        $performance_in = array($row[$idx["Batsman In"]], $row[$idx["Score In"]], intval($row[$idx["Position In"]]), false);
                        foreach (array($performance_out, $performance_in) as $performance)
                        {
                            list($name, $score, $position, $out) = $performance;
                            $player_id = $this->create_or_get_player_id($name, $players);
                            $perf_cache_key = "${match_cache_key}_{$player_id}";
                            if (!array_key_exists($perf_cache_key, $perf_cache))
                            {
                                // Player performance
                                $insert_player_perf = db_create_insert_player_performance($db);
                                $insert_player_perf->bindValue(":MatchId", $match_id);
                                $insert_player_perf->bindValue(":PlayerId", $player_id);
                                $player_perf_id = db_insert_and_return_id($db, $insert_player_perf);

                                // Batting performance
                                $how_out = strpos($score, "*") !== false ? "not out" : "bowled";
                                $runs = intval(str_replace("*", "", $score));
                                $insert_batting_perf = db_create_insert_batting_performance($db);
                                $insert_batting_perf->bindValue(":PlayerPerformanceId", $player_perf_id);
                                $insert_batting_perf->bindValue(":PlayerId", $player_id);
                                $insert_batting_perf->bindValue(":Position", $position);
                                $insert_batting_perf->bindValue(":HowOut", $how_out);
                                $insert_batting_perf->bindValue(":Runs", $runs);
                                $batting_perf_id = db_insert_and_return_id($db, $insert_batting_perf);

                                $perf_cache[$perf_cache_key] = array($player_perf_id, $batting_perf_id);
                            }
                            else
                            {
                                list($player_perf_id, $batting_perf_id) = $perf_cache[$perf_cache_key];
                            }

                            if ($out)
                                $batting_perf_id_out = $batting_perf_id;
                            else
                                $batting_perf_id_in = $batting_perf_id;
                        }

                        // Insert partnership
                        $insert_batting_partnership = db_create_insert_batting_partnership($db);
                        $runs = intval(str_replace("*", "", $row[$idx["Runs"]]));
                        $not_out = strpos($row[$idx["Runs"]], "*") !== false;
                        $insert_batting_partnership->bindValue(":BattingPerformanceIdOut", $batting_perf_id_out);
                        $insert_batting_partnership->bindValue(":BattingPerformanceIdIn", $batting_perf_id_in);
                        $insert_batting_partnership->bindValue(":Wicket", $row[$idx["Wicket"]]);
                        $insert_batting_partnership->bindValue(":Runs", $runs);
                        $insert_batting_partnership->bindValue(":NotOut", $not_out);
                        $insert_batting_partnership->execute();
                    }
                }

                $db->exec('COMMIT');
                fclose($base);
            }
            else
            {
                log\warning("      File not found");
            }
        }

        private function get_career_base_filename($summary_type)
        {
            return "career-stats-" . strtolower($summary_type) .
                   "-end-" . ($this->_config->getCareerBaseSeason()) . ".csv";
        }

        private function create_or_get_player_id($name, &$players)
        {
            $db = $this->_db;

            if (!array_key_exists($name, $players))
            {
                // We don't set the Active flag for players here - it is done later
                $insert_player = db_create_insert_player($db);
                $insert_player->bindValue(":PcPlayerId", NO_PC_PLAYER_ID);
                $insert_player->bindValue(":Name", $name);
                $insert_player->bindValue(":Active", 0);
                $player_id = db_insert_and_return_id($db, $insert_player);
                $players[$name] = $db->query("SELECT * FROM Player WHERE PlayerId = " . $player_id)->fetchArray(SQLITE3_ASSOC);
            }

            return $players[$name]["PlayerId"];
        }
    }
?>

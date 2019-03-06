<?php
    namespace plough\stats;
    use plough\log;

    require_once(__DIR__ . "/../logger.php");
    require_once("config.php");
    require_once("db.php");
    require_once("helpers.php");

    class CareerSummaryGenerator
    {
        // Properties
        private $_config;
        private $_db;

        // Public functions
        public function __construct($config, $db)
        {
            $this->_config = $config;
            $this->_db = $db;
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

        public function copy_base_to_summary_tables()
        {
            $db = $this->_db;
            $this->copy_base_to_summary("Matches", db_create_insert_career_matches_summary($db));
            $this->copy_base_to_summary("Batting", db_create_insert_career_batting_summary($db));
            $this->copy_base_to_summary("Bowling", db_create_insert_career_bowling_summary($db));
            $this->copy_base_to_summary("Fielding", db_create_insert_career_fielding_summary($db));
        }

        // Matches
        public function load_matches_career_summary_base()
        {
            $db = $this->_db;

            $bind_row_to_insert = function ($row, $idx, $player_id, $insert_career_matches_summary_base)
            {
                $insert_career_matches_summary_base->bindValue(":PlayerId", $player_id);
                $insert_career_matches_summary_base->bindValue(":Matches", $row[$idx["Mat"]]);
            };

            $insert_career_matches_summary_base = db_create_insert_career_matches_summary_base($db);
            $this->load_career_summary_base("Matches", $insert_career_matches_summary_base, $bind_row_to_insert);
        }

        public function add_season_to_career_matches_summary($season)
        {
            $db = $this->_db;

            $combine = function($current_career, $season)
            {
                $career_summary = array();
                $career_summary["Matches"] = $current_career["Matches"] + $season["Matches"];

                return $career_summary;
            };

            $this->add_season_to_career_summary(
                $season,
                "Matches",
                db_create_insert_career_matches_summary($db),
                $combine
                );
        }

        // Batting
        public function load_batting_career_summary_base()
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

        public function add_season_to_career_batting_summary($season)
        {
            $db = $this->_db;

            $combine = function($current_career, $season)
            {
                $career_summary = array();
                $career_summary["Innings"] = $current_career["Innings"] + $season["Innings"];
                $career_summary["NotOuts"] = $current_career["NotOuts"] + $season["NotOuts"];
                $career_summary["Runs"] = $current_career["Runs"] + $season["Runs"];

                $career_summary["Average"] = get_batting_average(
                    $career_summary["Runs"], $career_summary["Innings"], $career_summary["NotOuts"]
                    );

                if ($current_career["Balls"])
                {
                    $career_summary["Balls"] = $current_career["Balls"] + $season["Balls"];
                    $career_summary["StrikeRate"] = get_batting_strike_rate($career_summary["Runs"], $career_summary["Balls"]);
                }
                else
                {
                    $career_summary["Balls"] = null;
                    $career_summary["StrikeRate"] = null;
                }

                if ($current_career["HighScore"] > $season["HighScore"])
                {
                    $career_summary["HighScore"] = $current_career["HighScore"];
                    $career_summary["HighScoreNotOut"] = $current_career["HighScoreNotOut"];
                }
                else if ($season["HighScore"] > $current_career["HighScore"])
                {
                    $career_summary["HighScore"] = $season["HighScore"];
                    $career_summary["HighScoreNotOut"] = $season["HighScoreNotOut"];
                }
                else
                {
                    $career_summary["HighScore"] = $season["HighScore"];
                    $career_summary["HighScoreNotOut"] = max($current_career["HighScoreNotOut"], $season["HighScoreNotOut"]);
                }

                $career_summary["Fifties"] = $current_career["Fifties"] + $season["Fifties"];
                $career_summary["Hundreds"] = $current_career["Hundreds"] + $season["Hundreds"];
                $career_summary["Ducks"] = $current_career["Ducks"] + $season["Ducks"];
                $career_summary["Fours"] = $current_career["Fours"] + $season["Fours"];
                $career_summary["Sixes"] = $current_career["Sixes"] + $season["Sixes"];

                return $career_summary;
            };

            $this->add_season_to_career_summary(
                $season,
                "Batting",
                db_create_insert_career_batting_summary($db),
                $combine
                );
        }

        // Bowling
        public function load_bowling_career_summary_base()
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
                $insert_career_bowling_summary_base->bindValue(":FiveFors", $row[$idx["5wi"]]);
                $insert_career_bowling_summary_base->bindValue(":Wides", $row[$idx["Wides"]]);
                $insert_career_bowling_summary_base->bindValue(":NoBalls", $row[$idx["NBs"]]);
            };

            $insert_career_bowling_summary_base = db_create_insert_career_bowling_summary_base($db);
            $this->load_career_summary_base("Bowling", $insert_career_bowling_summary_base, $bind_row_to_insert);
        }

        public function add_season_to_career_bowling_summary($season)
        {
            $db = $this->_db;

            $combine = function($current_career, $season)
            {
                $career_summary = array();
                $collapsed_overs = collapse_overs(
                    $current_career["CompletedOvers"] + $season["CompletedOvers"],
                    $current_career["PartialBalls"] + $season["PartialBalls"]
                    );
                $career_summary["CompletedOvers"] = $collapsed_overs[0];
                $career_summary["PartialBalls"] = $collapsed_overs[1];
                $career_summary["Maidens"] = $current_career["Maidens"] + $season["Maidens"];
                $career_summary["Runs"] = $current_career["Runs"] + $season["Runs"];
                $career_summary["Wickets"] = $current_career["Wickets"] + $season["Wickets"];
                $career_summary["Average"] = get_bowling_average($career_summary["Runs"], $career_summary["Wickets"]);
                $career_summary["EconomyRate"] = get_bowling_economy_rate(
                    $career_summary["Runs"], $career_summary["CompletedOvers"], $career_summary["PartialBalls"]
                    );
                $career_summary["StrikeRate"] = get_bowling_strike_rate(
                    $career_summary["CompletedOvers"], $career_summary["PartialBalls"], $career_summary["Wickets"]
                    );

                if ($current_career["BestBowlingWickets"] > $season["BestBowlingWickets"])
                {
                    $career_summary["BestBowlingWickets"] = $current_career["BestBowlingWickets"];
                    $career_summary["BestBowlingRuns"] = $current_career["BestBowlingRuns"];
                }
                else if ($season["BestBowlingWickets"] > $current_career["BestBowlingWickets"])
                {
                    $career_summary["BestBowlingWickets"] = $season["BestBowlingWickets"];
                    $career_summary["BestBowlingRuns"] = $season["BestBowlingRuns"];
                }
                else
                {
                    $career_summary["BestBowlingWickets"] = $season["BestBowlingWickets"];
                    $career_summary["BestBowlingRuns"] = min($current_career["BestBowlingRuns"], $season["BestBowlingRuns"]);
                }

                $career_summary["FiveFors"] = $current_career["FiveFors"] + $season["FiveFors"];
                $career_summary["Wides"] = $current_career["Wides"] + $season["Wides"];
                $career_summary["NoBalls"] = $current_career["NoBalls"] + $season["NoBalls"];

                return $career_summary;
            };

            $this->add_season_to_career_summary(
                $season,
                "Bowling",
                db_create_insert_career_bowling_summary($db),
                $combine
                );
        }

        // Fielding
        public function load_fielding_career_summary_base()
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

        public function add_season_to_career_fielding_summary($season)
        {
            $db = $this->_db;

            $combine = function($current_career, $season)
            {
                $career_summary = array();
                $career_summary["CatchesFielding"] = $current_career["CatchesFielding"] + $season["CatchesFielding"];
                $career_summary["RunOuts"] = $current_career["RunOuts"] + $season["RunOuts"];
                $career_summary["TotalFieldingWickets"] = $current_career["TotalFieldingWickets"] + $season["TotalFieldingWickets"];
                $career_summary["CatchesKeeping"] = $current_career["CatchesKeeping"] + $season["CatchesKeeping"];
                $career_summary["Stumpings"] = $current_career["Stumpings"] + $season["Stumpings"];
                $career_summary["TotalKeepingWickets"] = $current_career["TotalKeepingWickets"] + $season["TotalKeepingWickets"];

                return $career_summary;
            };

            $this->add_season_to_career_summary(
                $season,
                "Fielding",
                db_create_insert_career_fielding_summary($db),
                $combine
                );
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

        private function add_season_to_career_summary(
            $season,
            $summary_type,
            $insert_career_summary,
            $combine_career_and_season
            )
        {
            $db = $this->_db;
            $players = get_players_by_name($db);
            $career_summary_table = "Career" . $summary_type . "Summary";

            $db->exec('BEGIN');

            foreach ($players as $player_name => $player)
            {
                $player_id = $player["PlayerId"];

                // Current career summary (i.e. most recent career summary entry)
                $statement = $db->prepare(
                    'SELECT
                        *
                    FROM ' . $career_summary_table . '
                    WHERE
                            PlayerId = :PlayerId
                    ORDER BY Season DESC
                    LIMIT 1
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $current_career = $statement->execute()->fetchArray(SQLITE3_ASSOC);

                // Season
                $statement = $db->prepare(
                    'SELECT
                        *
                     FROM ' . $summary_type . 'Summary
                     WHERE
                            PlayerId = :PlayerId
                        AND Season = :Season
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $statement->bindValue(":Season", $season);
                $season_summary = $statement->execute()->fetchArray(SQLITE3_ASSOC);

                $new_career = null;
                if (!empty($current_career))
                {
                    if (!empty($season_summary))
                    {
                        // Sum current career and season
                        $new_career = $combine_career_and_season($current_career, $season_summary);
                        $new_career["PlayerId"] = $player_id;
                    }
                    else
                    {
                        // No season - use current career
                        $new_career = $current_career;
                    }
                }
                else if (!empty($season_summary))
                {
                    // No current career - use season
                    $new_career = $season_summary;
                }

                if ($new_career)
                {
                    $new_career["Season"] = $season;
                    db_bind_values_from_row($insert_career_summary, $new_career);
                    $insert_career_summary->execute();
                }
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
            $insert_player = db_create_insert_player($db);

            $active_players = $this->load_active_players_map();
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
                        if (!array_key_exists($name, $players))
                        {
                            $insert_player->bindValue(":PcPlayerId", NO_PC_PLAYER_ID);
                            $insert_player->bindValue(":Name", $name);
                            $insert_player->bindValue(":Active", $active_players[$name]);
                            $player_id = db_insert_and_return_id($db, $insert_player);
                        }
                        else
                        {
                            $player_id = $players[$name]["PlayerId"];
                        }

                        $insert_career_summary_base->bindValue(":Season", $career_base_season);
                        $bind_row_to_insert($row, $idx, $player_id, $insert_career_summary_base);
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

        private function load_active_players_map()
        {
            $result = array();

            $active_players_path = $this->_config->getStaticDir() . "/active-players-base.csv";
            if (file_exists($active_players_path))
            {
                $base = fopen($active_players_path, "r");
                while ($row = fgetcsv($base))
                {
                    if ($row[0] == "Player")
                    {
                        $idx = array_flip($row);
                    }
                    else
                    {
                        $name = $row[$idx["Player"]];
                        $active_str = $row[$idx["Active"]];
                        $active = ($active_str == "Y");

                        $result[$name] = $active;
                    }
                }
            }

            return $result;
        }

        function get_career_base_filename($summary_type)
        {
            return "career-stats-" . strtolower($summary_type) .
                   "-end-" . ($this->_config->getCareerBaseSeason()) . ".csv";
        }
    }
?>

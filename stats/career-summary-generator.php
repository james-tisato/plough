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
            $this->load_career_summary_base("Batting", $insert_career_batting_summary_base, $bind_row_to_insert);
        }

        public function generate_career_batting_summary($db)
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
            $this->load_career_summary_base("Bowling", $insert_career_bowling_summary_base, $bind_row_to_insert);
        }

        public function generate_career_bowling_summary()
        {
            $db = $this->_db;

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
                $insert_career_fielding_summary_base->bindValue(":Matches", $row[$idx["Mat"]]);
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

        public function generate_career_fielding_summary($db)
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
                "Fielding",
                db_create_insert_career_fielding_summary($db),
                $combine
                );
        }

        // Private functions
        private function generate_career_summary(
            $summary_type,
            $insert_career_summary,
            $combine_career_base_and_season
            )
        {
            $db = $this->_db;
            db_truncate_table($db, "Career" . $summary_type . "Summary");
            $players = get_players_by_name($db);

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
            $summary_type,
            $insert_career_summary_base,
            $bind_row_to_insert
            )
        {
            $db = $this->_db;
            $players = get_players_by_name($db);

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
    }
?>

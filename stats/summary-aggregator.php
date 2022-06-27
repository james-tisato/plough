<?php
    namespace plough\stats;
    use plough\log;

    require_once(__DIR__ . "/../logger.php");
    require_once("config.php");
    require_once("db.php");
    require_once("helpers.php");

    class SummaryAggregator
    {
        // Properties
        private $_db;

        // Public functions
        public function __construct($db, $source1_period, $source2_period, $result_period)
        {
            $this->_db = $db;
            $this->_source1_period = $source1_period;
            $this->_source2_period = $source2_period;
            $this->_result_period = $result_period;
        }

        private function get_result_inserter($summary_type)
        {
            if ($this->_result_period === PERIOD_CAREER)
            {
                if ($summary_type === "Matches")
                    return db_create_insert_career_matches_summary($this->_db);
                else if ($summary_type === "Batting")
                    return db_create_insert_career_batting_summary($this->_db);
                else if ($summary_type === "Bowling")
                    return db_create_insert_career_bowling_summary($this->_db);
                else if ($summary_type === "Fielding")
                    return db_create_insert_career_fielding_summary($this->_db);
            }
            else if ($this->_result_period === PERIOD_SEASON)
            {
                if ($summary_type === "Matches")
                    return db_create_insert_season_matches_summary($this->_db);
                else if ($summary_type === "Batting")
                    return db_create_insert_season_batting_summary($this->_db);
                else if ($summary_type === "Bowling")
                    return db_create_insert_season_bowling_summary($this->_db);
                else if ($summary_type === "Fielding")
                    return db_create_insert_season_fielding_summary($this->_db);
            }
        }

        public function aggregate_summaries(
            $source1_season,
            $source1_match_type,
            $source2_season,
            $source2_match_type,
            $result_season,
            $result_match_type
            )
        {
            $this->aggregate_matches_summaries(
                $source1_season, $source1_match_type, $source2_season, $source2_match_type, $result_season, $result_match_type
                );
            $this->aggregate_batting_summaries(
                $source1_season, $source1_match_type, $source2_season, $source2_match_type, $result_season, $result_match_type
                );
            $this->aggregate_bowling_summaries(
                $source1_season, $source1_match_type, $source2_season, $source2_match_type, $result_season, $result_match_type
                );
            $this->aggregate_fielding_summaries(
                $source1_season, $source1_match_type, $source2_season, $source2_match_type, $result_season, $result_match_type
                );
        }

        // Matches
        private function aggregate_matches_summaries(
            $source1_season,
            $source1_match_type,
            $source2_season,
            $source2_match_type,
            $result_season,
            $result_match_type
            )
        {

            $combine = function($summary_base, $source1, $source2)
            {
                $result = array();
                $result["Matches"] = $source1["Matches"] + $source2["Matches"];
                $result["MatchesCaptaining"] = $source1["MatchesCaptaining"] + $source2["MatchesCaptaining"];
                $result["MatchesFielding"] = $source1["MatchesFielding"] + $source2["MatchesFielding"];
                $result["MatchesKeeping"] = $source1["MatchesKeeping"] + $source2["MatchesKeeping"];

                return $result;
            };

            $this->aggregate(
                "Matches",
                $source1_season,
                $source1_match_type,
                $source2_season,
                $source2_match_type,
                $result_season,
                $result_match_type,
                $combine
                );
        }

        // Batting
        private function aggregate_batting_summaries(
            $source1_season,
            $source1_match_type,
            $source2_season,
            $source2_match_type,
            $result_season,
            $result_match_type
            )
        {
            $combine = function($career_summary_base, $source1, $source2)
            {
                $result = array();
                $result["Innings"] = $source1["Innings"] + $source2["Innings"];
                $result["NotOuts"] = $source1["NotOuts"] + $source2["NotOuts"];
                $result["Runs"] = $source1["Runs"] + $source2["Runs"];

                $result["Average"] = get_batting_average(
                    $result["Runs"], $result["Innings"], $result["NotOuts"]
                    );

                // Set ball count to zero in source 1 or source 2 if it's currently missing. This can happen
                // if we have historical career data that doesn't include a ball count
                if (is_null($source1["Balls"]))
                    $source1["Balls"] = 0;
                if (is_null($source2["Balls"]))
                    $source2["Balls"] = 0;
                $result["Balls"] = $source1["Balls"] + $source2["Balls"];

                // Determine the number of runs from which to calculate the strike rate. If we don't have any
                // ball count data in the career base for this player, we only have balls that have been faced
                // in seasons since then => we must only include runs scored since then.
                if ($this->_result_period === PERIOD_CAREER && $career_summary_base && is_null($career_summary_base["Balls"]))
                    $runs_for_strike_rate = $result["Runs"] - $career_summary_base["Runs"];
                else
                    $runs_for_strike_rate = $result["Runs"];

                // Finally calculate the strike rate
                $result["StrikeRate"] = get_batting_strike_rate($runs_for_strike_rate, $result["Balls"]);

                if ($source1["HighScore"] > $source2["HighScore"] || is_null($source2["HighScore"]))
                {
                    $result["HighScore"] = $source1["HighScore"];
                    $result["HighScoreNotOut"] = $source1["HighScoreNotOut"];
                    $result["HighScoreMatchId"] = $source1["HighScoreMatchId"];
                }
                else if ($source2["HighScore"] > $source1["HighScore"] || is_null($source1["HighScore"]))
                {
                    $result["HighScore"] = $source2["HighScore"];
                    $result["HighScoreNotOut"] = $source2["HighScoreNotOut"];
                    $result["HighScoreMatchId"] = $source2["HighScoreMatchId"];
                }
                else
                {
                    $result["HighScore"] = $source1["HighScore"];
                    $result["HighScoreNotOut"] = max($source1["HighScoreNotOut"], $source2["HighScoreNotOut"]);
                    if ($source1["HighScoreNotOut"] && !$source2["HighScoreNotOut"])
                        $result["HighScoreMatchId"] = $source1["HighScoreMatchId"];
                    else
                        $result["HighScoreMatchId"] = $source2["HighScoreMatchId"];
                }

                $result["Fifties"] = $source1["Fifties"] + $source2["Fifties"];
                $result["Hundreds"] = $source1["Hundreds"] + $source2["Hundreds"];
                $result["Ducks"] = $source1["Ducks"] + $source2["Ducks"];
                $result["Fours"] = $source1["Fours"] + $source2["Fours"];
                $result["Sixes"] = $source1["Sixes"] + $source2["Sixes"];

                return $result;
            };

            $this->aggregate(
                "Batting",
                $source1_season,
                $source1_match_type,
                $source2_season,
                $source2_match_type,
                $result_season,
                $result_match_type,
                $combine
                );
        }

        // Bowling
        private function aggregate_bowling_summaries(
            $source1_season,
            $source1_match_type,
            $source2_season,
            $source2_match_type,
            $result_season,
            $result_match_type
            )
        {
            $combine = function($career_summary_base, $source1, $source2)
            {
                $result = array();
                $collapsed_overs = collapse_overs(
                    $source1["CompletedOvers"] + $source2["CompletedOvers"],
                    $source1["PartialBalls"] + $source2["PartialBalls"]
                    );
                $result["CompletedOvers"] = $collapsed_overs[0];
                $result["PartialBalls"] = $collapsed_overs[1];
                $result["Maidens"] = $source1["Maidens"] + $source2["Maidens"];
                $result["Runs"] = $source1["Runs"] + $source2["Runs"];
                $result["Wickets"] = $source1["Wickets"] + $source2["Wickets"];
                $result["Average"] = get_bowling_average($result["Runs"], $result["Wickets"]);
                $result["EconomyRate"] = get_bowling_economy_rate(
                    $result["Runs"], $result["CompletedOvers"], $result["PartialBalls"]
                    );
                $result["StrikeRate"] = get_bowling_strike_rate(
                    $result["CompletedOvers"], $result["PartialBalls"], $result["Wickets"]
                    );

                if ($source1["BestBowlingWickets"] > $source2["BestBowlingWickets"] || is_null($source2["BestBowlingWickets"]))
                {
                    $result["BestBowlingWickets"] = $source1["BestBowlingWickets"];
                    $result["BestBowlingRuns"] = $source1["BestBowlingRuns"];
                    $result["BestBowlingMatchId"] = $source1["BestBowlingMatchId"];
                }
                else if ($source2["BestBowlingWickets"] > $source1["BestBowlingWickets"] || is_null($source1["BestBowlingWickets"]))
                {
                    $result["BestBowlingWickets"] = $source2["BestBowlingWickets"];
                    $result["BestBowlingRuns"] = $source2["BestBowlingRuns"];
                    $result["BestBowlingMatchId"] = $source2["BestBowlingMatchId"];
                }
                else
                {
                    $result["BestBowlingWickets"] = $source1["BestBowlingWickets"];
                    if ($source1["BestBowlingRuns"] < $source2["BestBowlingRuns"])
                    {
                        $result["BestBowlingRuns"] = $source1["BestBowlingRuns"];
                        $result["BestBowlingMatchId"] = $source1["BestBowlingMatchId"];
                    }
                    else
                    {
                        $result["BestBowlingRuns"] = $source2["BestBowlingRuns"];
                        $result["BestBowlingMatchId"] = $source2["BestBowlingMatchId"];
                    }
                }

                $result["FiveFors"] = $source1["FiveFors"] + $source2["FiveFors"];
                $result["Wides"] = $source1["Wides"] + $source2["Wides"];
                $result["NoBalls"] = $source1["NoBalls"] + $source2["NoBalls"];

                return $result;
            };

            $this->aggregate(
                "Bowling",
                $source1_season,
                $source1_match_type,
                $source2_season,
                $source2_match_type,
                $result_season,
                $result_match_type,
                $combine
                );
        }

        // Fielding
        private function aggregate_fielding_summaries(
            $source1_season,
            $source1_match_type,
            $source2_season,
            $source2_match_type,
            $result_season,
            $result_match_type
            )
        {
            $combine = function($career_summary_base, $source1, $source2)
            {
                $result = array();
                $result["CatchesFielding"] = $source1["CatchesFielding"] + $source2["CatchesFielding"];
                $result["RunOuts"] = $source1["RunOuts"] + $source2["RunOuts"];
                $result["TotalFieldingWickets"] = $source1["TotalFieldingWickets"] + $source2["TotalFieldingWickets"];
                $result["CatchesKeeping"] = $source1["CatchesKeeping"] + $source2["CatchesKeeping"];
                $result["Stumpings"] = $source1["Stumpings"] + $source2["Stumpings"];
                $result["TotalKeepingWickets"] = $source1["TotalKeepingWickets"] + $source2["TotalKeepingWickets"];

                return $result;
            };

            $this->aggregate(
                "Fielding",
                $source1_season,
                $source1_match_type,
                $source2_season,
                $source2_match_type,
                $result_season,
                $result_match_type,
                $combine
                );
        }

        // Private functions
        private function aggregate(
            $summary_type,
            $source1_season,
            $source1_match_type,
            $source2_season,
            $source2_match_type,
            $result_season,
            $result_match_type,
            $combine
            )
        {
            $db = $this->_db;
            $players = get_players_by_name($db);

            $db->exec('BEGIN');

            foreach ($players as $player_name => $player)
            {
                $player_id = $player["PlayerId"];

                // Career summary base
                $statement = $db->prepare(
                    'SELECT
                        *
                     FROM Career' . $summary_type . 'SummaryBase
                     WHERE
                            PlayerId = :PlayerId
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $career_summary_base = $statement->execute()->fetchArray(SQLITE3_ASSOC);

                // Source 1
                $statement = $db->prepare(
                    'SELECT
                        *
                     FROM ' . get_period_name($this->_source1_period) . $summary_type . 'Summary
                     WHERE
                             PlayerId = :PlayerId
                        AND Season = :Season
                        AND MatchType = :MatchType
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $statement->bindValue(":Season", $source1_season);
                $statement->bindValue(":MatchType", $source1_match_type);
                $source1 = $statement->execute()->fetchArray(SQLITE3_ASSOC);

                // Source 2
                $statement = $db->prepare(
                    'SELECT
                        *
                     FROM ' . get_period_name($this->_source2_period) . $summary_type . 'Summary
                     WHERE
                             PlayerId = :PlayerId
                        AND Season = :Season
                        AND MatchType = :MatchType
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $statement->bindValue(":Season", $source2_season);
                $statement->bindValue(":MatchType", $source2_match_type);
                $source2 = $statement->execute()->fetchArray(SQLITE3_ASSOC);

                $result = null;
                if (!empty($source1))
                {
                    if (!empty($source2))
                    {
                        // Sum sources
                        $result = $combine($career_summary_base, $source1, $source2);
                        $result["PlayerId"] = $player_id;
                    }
                    else
                    {
                        // No source 2 - use source 1
                        $result = $source1;
                    }
                }
                else if (!empty($source2))
                {
                    // No source 1 - use source 2
                    $result = $source2;
                }

                if ($result)
                {
                    $result["Season"] = $result_season;
                    $result["MatchType"] = $result_match_type;
                    $insert_result_summary = $this->get_result_inserter($summary_type);
                    db_bind_values_from_row($insert_result_summary, $result);
                    $insert_result_summary->execute();
                }
            }

            $db->exec('COMMIT');
        }
    }
?>

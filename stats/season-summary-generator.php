<?php
    namespace plough\stats;
    use plough\log;

    require_once(__DIR__ . "/../logger.php");
    require_once("config.php");
    require_once("db.php");
    require_once("helpers.php");

    class SeasonSummaryGenerator
    {
        // Properties
        private $_db;

        // Public functions
        public function __construct($db)
        {
            $this->_db = $db;
        }

        public function clear_summary_tables()
        {
            $db = $this->_db;
            db_truncate_table($db, "SeasonMatchesSummary");
            db_truncate_table($db, "SeasonBattingSummary");
            db_truncate_table($db, "SeasonBowlingSummary");
            db_truncate_table($db, "SeasonFieldingSummary");
        }

        public function generate_matches_summary($season)
        {
            $db = $this->_db;
            $players = get_players_by_name($db);

            $insert_matches_summary = db_create_insert_season_matches_summary($db);
            $insert_matches_summary->bindValue(":Season", $season);

            $db->exec('BEGIN');

            foreach ($players as $player_name => $player)
            {
                $player_id = $player["PlayerId"];

                // Basic fields
                $statement = $db->prepare(
                    'SELECT
                         p.PlayerId
                        ,COUNT(pp.PlayerPerformanceId) AS Matches
                        ,SUM(CASE WHEN pp.Captain = 1 THEN 1 ELSE 0 END) as MatchesCaptaining
                        ,SUM(CASE WHEN pp.Wicketkeeper = 0 THEN 1 ELSE 0 END) as MatchesFielding
                        ,SUM(CASE WHEN pp.Wicketkeeper = 1 THEN 1 ELSE 0 END) as MatchesKeeping
                    FROM Player p
                    INNER JOIN PlayerPerformance pp on pp.PlayerId = p.PlayerId
                    INNER JOIN Match m on m.MatchId = pp.MatchId
                    WHERE
                            p.PlayerId = :PlayerId
                        and m.Season = :Season
                    GROUP BY p.PlayerId, p.Name
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $statement->bindValue(":Season", $season);
                $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                if (empty($result))
                    continue;

                db_bind_values_from_row($insert_matches_summary, $result);

                // Insert
                $insert_matches_summary->execute();
            }

            $db->exec('COMMIT');
        }

        public function generate_batting_summary($season)
        {
            $db = $this->_db;
            $players = get_players_by_name($db);

            $insert_batting_summary = db_create_insert_season_batting_summary($db);
            $insert_batting_summary->bindValue(":Season", $season);

            $db->exec('BEGIN');

            foreach ($players as $player_name => $player)
            {
                $player_id = $player["PlayerId"];

                // Basic fields
                $statement = $db->prepare(
                    'SELECT
                         p.PlayerId
                        ,COUNT(bp.BattingPerformanceId) AS Innings
                        ,SUM(CASE bp.HowOut
                            WHEN "not out" THEN 1
                            WHEN "retired hurt" THEN 1
                            WHEN "retired not out" THEN 1
                            ELSE 0 END) AS NotOuts
                        ,SUM(bp.Runs) AS Runs
                        ,(CAST(SUM(bp.Runs) AS FLOAT) / (COUNT(bp.BattingPerformanceId) - SUM(CASE bp.HowOut WHEN "not out" THEN 1 WHEN "retired hurt" THEN 1 WHEN "retired not out" THEN 1 ELSE 0 END))) AS Average
                        ,((CAST(SUM(bp.Runs) AS FLOAT) / SUM(bp.Balls)) * 100.0) AS StrikeRate
                        ,SUM(CASE WHEN bp.Runs >= 50 AND bp.Runs < 100 THEN 1 ELSE 0 END) AS Fifties
                        ,SUM(CASE WHEN bp.Runs >= 100 THEN 1 ELSE 0 END) AS Hundreds
                        ,SUM(CASE WHEN bp.Runs = 0 and bp.HowOut <> "not out" and bp.HowOut <> "retired hurt" and bp.HowOut <> "retired not out" THEN 1 ELSE 0 END) AS Ducks
                        ,SUM(bp.Balls) as Balls
                        ,SUM(bp.Fours) as Fours
                        ,SUM(bp.Sixes) as Sixes
                    FROM Player p
                    INNER JOIN PlayerPerformance pp on pp.PlayerId = p.PlayerId
                    INNER JOIN Match m on m.MatchId = pp.MatchId
                    LEFT JOIN BattingPerformance bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
                    WHERE
                            p.PlayerId = :PlayerId
                        and m.Season = :Season
                    GROUP BY p.PlayerId, p.Name
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $statement->bindValue(":Season", $season);
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
                            WHEN "not out" THEN 1
                            WHEN "retired hurt" THEN 1
                            WHEN "retired not out" THEN 1
                            ELSE 0 END) as HighScoreNotOut
                        ,m.MatchId as HighScoreMatchId
                    FROM Player p
                    INNER JOIN PlayerPerformance pp on pp.PlayerId = p.PlayerId
                    INNER JOIN Match m on m.MatchId = pp.MatchId
                    LEFT JOIN BattingPerformance bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
                    WHERE
                            p.PlayerId = :PlayerId
                        and m.Season = :Season
                    ORDER BY HighScore DESC, HighScoreNotOut DESC
                    LIMIT 1
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $statement->bindValue(":Season", $season);
                $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                db_bind_values_from_row($insert_batting_summary, $result);

                // Insert
                $insert_batting_summary->execute();
            }

            $db->exec('COMMIT');
        }

        public function generate_bowling_summary($season)
        {
            $db = $this->_db;
            $players = get_players_by_name($db);

            $insert_bowling_summary = db_create_insert_season_bowling_summary($db);
            $insert_bowling_summary->bindValue(":Season", $season);

            $db->exec('BEGIN');

            foreach ($players as $player_name => $player)
            {
                $player_id = $player["PlayerId"];

                // Basic fields
                $statement = $db->prepare(
                   'SELECT
                         p.PlayerId
                        ,SUM(bp.Maidens) as Maidens
                        ,SUM(bp.Runs) AS Runs
                        ,SUM(bp.Wickets) AS Wickets
                        ,(CAST(SUM(bp.Runs) AS FLOAT) / (SUM(bp.Wickets))) AS Average
                        ,SUM(CASE WHEN bp.Wickets >= 5 THEN 1 ELSE 0 END) AS FiveFors
                        ,SUM(bp.Wides) as Wides
                        ,SUM(bp.NoBalls) as NoBalls
                    FROM Player p
                    INNER JOIN PlayerPerformance pp on pp.PlayerId = p.PlayerId
                    INNER JOIN Match m on m.MatchId = pp.MatchId
                    LEFT JOIN BowlingPerformance bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
                    WHERE
                            p.PlayerId = :PlayerId
                        and m.Season = :Season
                    GROUP BY p.PlayerId, p.Name
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $statement->bindValue(":Season", $season);
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
                    INNER JOIN Match m on m.MatchId = pp.MatchId
                    LEFT JOIN BowlingPerformance bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
                    WHERE
                            p.PlayerId = :PlayerId
                        and m.Season = :Season
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $statement->bindValue(":Season", $season);
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
                        ,m.MatchId as BestBowlingMatchId
                    FROM Player p
                    INNER JOIN PlayerPerformance pp on pp.PlayerId = p.PlayerId
                    INNER JOIN Match m on m.MatchId = pp.MatchId
                    LEFT JOIN BowlingPerformance bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
                    WHERE
                            p.PlayerId = :PlayerId
                        and m.Season = :Season
                    ORDER BY BestBowlingWickets DESC, BestBowlingRuns ASC
                    LIMIT 1
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $statement->bindValue(":Season", $season);
                $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                db_bind_values_from_row($insert_bowling_summary, $result);

                // Insert
                $insert_bowling_summary->execute();
            }

            $db->exec('COMMIT');
        }

        public function generate_fielding_summary($season)
        {
            $db = $this->_db;
            $players = get_players_by_name($db);

            $insert_fielding_summary = db_create_insert_season_fielding_summary($db);
            $insert_fielding_summary->bindValue(":Season", $season);

            $db->exec('BEGIN');

            foreach ($players as $player)
            {
                $player_id = $player["PlayerId"];

                // Basic fields
                $statement = $db->prepare(
                   'SELECT
                         p.PlayerId as PlayerId
                        ,SUM(CASE WHEN pp.Wicketkeeper = 0 THEN fp.Catches ELSE 0 END) as CatchesFielding
                        ,SUM(fp.RunOuts) as RunOuts
                        ,SUM(CASE WHEN pp.Wicketkeeper = 1 THEN fp.Catches ELSE 0 END) as CatchesKeeping
                        ,SUM(fp.Stumpings) as Stumpings
                    FROM Player p
                    INNER JOIN PlayerPerformance pp on pp.PlayerId = p.PlayerId
                    INNER JOIN Match m on m.MatchId = pp.MatchId
                    LEFT JOIN FieldingPerformance fp on fp.PlayerPerformanceId = pp.PlayerPerformanceId
                    WHERE
                            p.PlayerId = :PlayerId
                        and m.Season = :Season
                    GROUP BY p.PlayerId, p.Name
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $statement->bindValue(":Season", $season);
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

            $db->exec('COMMIT');
        }
    }
?>

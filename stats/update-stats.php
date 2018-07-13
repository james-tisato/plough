<?php
	require_once("stats_db.php");

    // Constants
    const DB_PATH = "stats_db.sqlite";
    
    const URL_PREFIX = "http://play-cricket.com/api/v2/";
    const URL_SITE_ID = "site_id=8087";
    const URL_SEASON = "season=2018";
    const URL_API_TOKEN = "api_token=cd3d9f47cef70496b9b3bfbab5231214";
    
    const CLUB_NAME = "Ploughmans CC";
    const DELETED = "Deleted";
    
    // Modes of dismissal
    const DID_NOT_BAT = "did not bat";
	const NOT_OUT = "no";
    const CAUGHT = "ct";
    const RUN_OUT = "ro";
    const STUMPED = "st";
    
    // Functions
    function int_from_bool($bool)
    {
        if ($bool)
            return 1;
        else
            return 0;
    }
    
    function main()
    {
        // Delete previous database
        unlink(DB_PATH);
        
        // Open database and create schema if required
        $db = new SQLite3(DB_PATH);
        db_create_schema($db);
        
        // Prepare statements
        $insert_match = db_create_insert_match($db);
        $insert_player = db_create_insert_player($db);
        $insert_player_perf = db_create_insert_player_performance($db);
        $insert_batting_perf = db_create_insert_batting_performance($db);
        $insert_bowling_perf = db_create_insert_bowling_performance($db);
        $insert_fielding_perf = db_create_insert_fielding_performance($db);
		$insert_batting_summary = db_create_insert_batting_summary($db);
        
        // Set up match and player cache
        $match_cache = array();
        $player_cache = array();
        
        // Get match list
        echo "Fetching match list..." . PHP_EOL;
        $matches_from_date = "26/01/2018";
        $matches_url = URL_PREFIX . "matches.json?" . URL_SITE_ID . "&" . URL_SEASON . "&" . URL_API_TOKEN . "&from_entry_date=$matches_from_date";
        $matches = json_decode(file_get_contents($matches_url), true)["matches"];
        $num_matches = count($matches);
        echo "  $num_matches matches found" . PHP_EOL . PHP_EOL;
        
        echo "Fetching match details..." . PHP_EOL;
        foreach ($matches as $match_idx => $match)
        {
            // Player performance cache for this match
            $player_perf_cache = array();
            $pc_match_id = $match["id"];
            
            echo "  Processing match $match_idx (Play-Cricket id $pc_match_id)..." . PHP_EOL;
            
            // Get match detail
            $match_detail_url = URL_PREFIX . "match_detail.json?match_id=$pc_match_id&" . URL_API_TOKEN;
            $match_detail = json_decode(file_get_contents($match_detail_url), true)["match_details"][0];
            
            if ($match_detail["status"] == DELETED)
            {
                echo "    Skipping match because it was deleted..." . PHP_EOL;
            }
            else if (empty($match_detail["result"]))
            {
                echo "    Skipping match because it is a future fixture..." . PHP_EOL;
            }
            else
            {
                // Start transaction for whole of match
                $db->exec('BEGIN');
                
                // Determine home team and get team info
                if ($match_detail["home_club_name"] == CLUB_NAME)
                {
                    $home_match = 1;
                    $plough_team = $match_detail["home_team_name"];
                    $plough_team_id = $match_detail["home_team_id"];
                    $oppo_club = $match_detail["away_club_name"];
                    $oppo_team = $match_detail["away_team_name"];
                    $oppo_team_id = $match_detail["away_team_id"];
                    $players = $match_detail["players"][0]["home_team"];
                }
                else
                {
                    $home_match = 0;
                    $plough_team = $match_detail["away_team_name"];
                    $plough_team_id = $match_detail["away_team_id"];
                    $oppo_club = $match_detail["home_club_name"];
                    $oppo_team = $match_detail["home_team_name"];
                    $oppo_team_id = $match_detail["home_team_id"];
                    $players = $match_detail["players"][1]["away_team"];
                }
                
                // Insert match
                $insert_match->bindValue(":pc_match_id", $pc_match_id);
                $insert_match->bindValue(":status", $match_detail["status"]);
                $insert_match->bindValue(":match_date", $match_detail["match_date"]);
                $insert_match->bindValue(":plough_team", $plough_team);
                $insert_match->bindValue(":plough_team_id", $plough_team_id);
                $insert_match->bindValue(":oppo_club", $oppo_club);
                $insert_match->bindValue(":oppo_team", $oppo_team);
                $insert_match->bindValue(":oppo_team_id", $oppo_team_id);
                $insert_match->bindValue(":home", $home_match);
                $insert_match->bindValue(":result", $match_detail["result"]);
                $match_id = db_insert_and_return_id($db, $insert_match);
                $match_cache[$pc_match_id] = $match_id;
                
                foreach ($players as $player)
                {
                    $pc_player_id = $player["player_id"];
                    
                    // Insert player
                    if (!array_key_exists($pc_player_id, $player_cache))
                    {
                        $insert_player->bindValue(":pc_player_id", $pc_player_id);
                        $insert_player->bindValue(":name", $player["player_name"]);
                        $player_id = db_insert_and_return_id($db, $insert_player);
                        $player_cache[$pc_player_id] = $player_id;
                    }
                    
                    // Insert player performance
                    $player_id = $player_cache[$pc_player_id];
                    $insert_player_perf->bindValue(":match_id", $match_id);
                    $insert_player_perf->bindValue(":player_id", $player_id);
                    $insert_player_perf->bindValue(":captain", int_from_bool($player["captain"]));
                    $insert_player_perf->bindValue(":wicketkeeper", int_from_bool($player["wicket_keeper"]));
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
                                    $insert_batting_perf->bindValue(":player_perf_id", $player_perf_id);
                                    $insert_batting_perf->bindValue(":player_id", $player_id);
                                    $insert_batting_perf->bindValue(":position", $batting_perf["position"]);
                                    $insert_batting_perf->bindValue(":how_out", $how_out);
                                    $insert_batting_perf->bindValue(":runs", $batting_perf["runs"]);
                                    $insert_batting_perf->bindValue(":balls", $batting_perf["balls"]);
                                    $insert_batting_perf->bindValue(":fours", $batting_perf["fours"]);
                                    $insert_batting_perf->bindValue(":sixes", $batting_perf["sixes"]);
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
                            
                            $insert_bowling_perf->bindValue(":player_perf_id", $player_perf_id);
                            $insert_bowling_perf->bindValue(":player_id", $player_id);
                            $insert_bowling_perf->bindValue(":position", $bowling_perf_idx + 1);
                            $insert_bowling_perf->bindValue(":completed_overs", $completed_overs);
                            $insert_bowling_perf->bindValue(":partial_balls", $partial_balls);
                            $insert_bowling_perf->bindValue(":maidens", $bowling_perf["maidens"]);
                            $insert_bowling_perf->bindValue(":runs", $bowling_perf["runs"]);
                            $insert_bowling_perf->bindValue(":wickets", $bowling_perf["wickets"]);
                            $insert_bowling_perf->bindValue(":wides", $bowling_perf["wides"]);
                            $insert_bowling_perf->bindValue(":no_balls", $bowling_perf["no_balls"]);
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
                            $insert_fielding_perf->bindValue(":player_perf_id", $player_perf_id);
                            $insert_fielding_perf->bindValue(":player_id", $player_id);
                            $insert_fielding_perf->bindValue(":catches", $fielding["catches"]);
                            $insert_fielding_perf->bindValue(":run_outs", $fielding["run_outs"]);
                            $insert_fielding_perf->bindValue(":stumpings", $fielding["stumpings"]);
                            $insert_fielding_perf->execute();
                        }
                    }
                }
                
                // End transaction for whole of match
                $db->exec('COMMIT');
            }
        }
		
		// Build summaries
        // Get players
        $players = array();
        $statement = $db->prepare('SELECT PlayerId FROM "Player" ORDER BY PlayerId');
        $result = $statement->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC))
            array_push($players, $row["PlayerId"];
        
		// Batting
        foreach ($players as $player_id)
        {
            $statement = $db->prepare('
                ;WITH "HighestScore" AS (
                    SELECT
                         p.PlayerId
                        ,MAX(bp.Runs) as HighestScore
                    FROM "Player" p
                    INNER JOIN "BattingPerformance" bp on bp.PlayerId = p.PlayerId
                    GROUP BY p.PlayerId
                    )
                
                SELECT
                     p.PlayerId
                    ,p.Name
                    ,COUNT(pp.PlayerPerformanceId) AS Matches
                    ,COUNT(bp.BattingPerformanceId) AS Innings
                    ,SUM(CASE bp.HowOut WHEN "no" THEN 1 ELSE 0 END) AS NotOuts
                    ,SUM(bp.Runs) AS Runs
                    ,(CAST(SUM(bp.Runs) AS FLOAT) / (COUNT(bp.BattingPerformanceId) - SUM(CASE bp.HowOut WHEN "no" THEN 1 ELSE 0 END))) AS Average
                    ,((CAST(SUM(bp.Runs) AS FLOAT) / SUM(bp.Balls)) * 100.0) AS StrikeRate
                    ,SUM(CASE WHEN bp.Runs >= 50 AND bp.Runs < 100 THEN 1 ELSE 0 END) AS Fifties
                    ,SUM(CASE WHEN bp.Runs >= 100 THEN 1 ELSE 0 END) AS Hundreds
                    ,SUM(bp.Balls) as Balls
                    ,SUM(bp.Fours) as Fours
                    ,SUM(bp.Sixes) as Sixes
                FROM "Player" p
                INNER JOIN "PlayerPerformance" pp on pp.PlayerId = p.PlayerId
                LEFT JOIN "BattingPerformance" bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
                LEFT JOIN "HighestScore" hs on hs.PlayerId = p.PlayerId
                LEFT JOIN "BattingPerformance" bpMax on bpMax.PlayerId = p.PlayerId and bpMax.Runs = hs.HighestScore
                WHERE p.Name = \'Matt Bolshaw\'
                GROUP BY p.PlayerId, p.Name
                --ORDER BY Runs DESC
                '
                );
        }
        $result = $statement->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC))
        {
            //print_r($row);
        }
        
        $statement = $db->prepare('
            ;WITH "HighestScore" AS (
                SELECT
                     p.PlayerId
                    ,MAX(bp.Runs) as HighestScore
                FROM "Player" p
                INNER JOIN "BattingPerformance" bp on bp.PlayerId = p.PlayerId
                GROUP BY p.PlayerId
                )
        
            SELECT
				 p.*
                ,m.*
                ,pp.*
                ,bp.*
                ,hs.*
                ,bpMax.*
            FROM "Player" p
            INNER JOIN "PlayerPerformance" pp on pp.PlayerId = p.PlayerId
            INNER JOIN "Match" m on m.MatchId = pp.MatchId
            LEFT JOIN "BattingPerformance" bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
            LEFT JOIN "HighestScore" hs on hs.PlayerId = p.PlayerId
            LEFT JOIN "BattingPerformance" bpMax on bpMax.PlayerId = p.PlayerId and bpMax.Runs = hs.HighestScore
            WHERE p.Name = \'Matt Bolshaw\'
            ORDER BY m.MatchId
            '
            );
        $result = $statement->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC))
        {
            //print_r($row);
        }
        
        $statement = $db->prepare('
            SELECT
				 p.*
                ,m.*
                ,pp.*
                ,bp.*
            FROM "Player" p
            INNER JOIN "PlayerPerformance" pp on pp.PlayerId = p.PlayerId
            INNER JOIN "Match" m on m.MatchId = pp.MatchId
            LEFT JOIN "BattingPerformance" bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
            WHERE p.Name = \'Matt Bolshaw\'
            ORDER BY m.MatchId
            '
            );
        $result = $statement->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC))
        {
            //print_r($row);
        }
    }
    
    main();
?>
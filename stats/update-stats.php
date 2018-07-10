<?php
    // Constants
    const DB_PATH = "stats_db.sqlite";
    
    const URL_PREFIX = "http://play-cricket.com/api/v2/";
    const URL_SITE_ID = "site_id=8087";
    const URL_SEASON = "season=2018";
    const URL_API_TOKEN = "api_token=cd3d9f47cef70496b9b3bfbab5231214";
    
    const CLUB_NAME = "Ploughmans CC";
    const DID_NOT_BAT = "did not bat";
    
    // Functions
    function int_from_bool($bool)
    {
        if ($bool)
            return 1;
        else
            return 0;
    }
    
    function db_insert_and_return_id($db, $insert_statement)
    {
        $insert_statement->execute();
        return $db->querySingle("SELECT last_insert_rowid()");
    }
    
    function db_create_schema($db)
    {   
        $db->exec('PRAGMA foreign_keys = ON;');
    
        $db->query('CREATE TABLE "Match" (
            "MatchId" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            "PCMatchId" INTEGER,
            "Status" TEXT,
            "PloughTeam" TEXT,
            "PloughTeamId" INTEGER,
            "OppoClub" TEXT,
            "OppoTeam" TEXT,
            "OppoTeamId" INTEGER,
            "Home" INTEGER,
            "Result" TEXT
            )');
            
        $db->query('CREATE TABLE "Player" (
            "PlayerId" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            "PCPlayerId" INTEGER,
            "Name" TEXT
            )');
            
        $db->query('CREATE TABLE "PlayerPerformance" (
            "PlayerPerformanceId" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            "MatchId" INTEGER,
            "PlayerId" INTEGER,
            "Captain" INTEGER,
            "Wicketkeeper" INTEGER,
            FOREIGN KEY("MatchId") REFERENCES "Match"("MatchId"),
            FOREIGN KEY("PlayerId") REFERENCES "Player"("PlayerId")
            )');
            
        $db->query('CREATE TABLE "BattingPerformance" (
            "BattingPerformanceId" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            "PlayerPerformanceId" INTEGER,
            "Position" INTEGER,
            "HowOut" TEXT,
            "Runs" INTEGER,
            "Balls" INTEGER,
            "Fours" INTEGER,
            "Sixes" INTEGER,
            FOREIGN KEY("PlayerPerformanceId") REFERENCES "PlayerPerformance"("PlayerPerformanceId")
            )');
    }
    
    function db_create_insert_match($db)
    {
        return $db->prepare(
            'INSERT INTO "Match" ("PCMatchId", "Status", "PloughTeam", "PloughTeamId", "OppoClub", "OppoTeam", "OppoTeamId", "Home", "Result")
             VALUES (:pc_match_id, :status, :plough_team, :plough_team_id, :oppo_club, :oppo_team, :oppo_team_id, :home, :result)'
            );
    }
    
    function db_create_insert_player($db)
    {
        return $db->prepare(
            'INSERT INTO "Player" ("PCPlayerId", "Name")
             VALUES (:pc_player_id, :name)'
            );
    }
    
    function db_create_insert_player_performance($db)
    {
        return $db->prepare(
            'INSERT INTO "PlayerPerformance" ("MatchId", "PlayerId", "Captain", "Wicketkeeper")
             VALUES (:match_id, :player_id, :captain, :wicketkeeper)'
            );
    }
    
    function db_create_insert_batting_performance($db)
    {
        return $db->prepare(
            'INSERT INTO "BattingPerformance" ("PlayerPerformanceId", "Position", "HowOut", "Runs", "Balls", "Fours", "Sixes")
             VALUES (:player_perf_id, :position, :how_out, :runs, :balls, :fours, :sixes)'
            );
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
        
        // Set up match and player cache
        $match_cache = array();
        $player_cache = array();
        $player_perf_cache = array();
        
        // Get match list
        echo "Fetching match list..." . PHP_EOL;
        $matches_from_date = "26/06/2018";
        $matches_url = URL_PREFIX . "matches.json?" . URL_SITE_ID . "&" . URL_SEASON . "&" . URL_API_TOKEN . "&from_entry_date=$matches_from_date";
        $matches = json_decode(file_get_contents($matches_url), true)["matches"];
        $num_matches = count($matches);
        echo "  $num_matches matches found" . PHP_EOL . PHP_EOL;
        
        echo "Fetching match details..." . PHP_EOL;
        foreach ($matches as $match_idx => $match)
        {
            echo "  Match $match_idx..." . PHP_EOL;
            
            // Get match detail
            $pc_match_id = $match["id"];
            $match_detail_url = URL_PREFIX . "match_detail.json?match_id=$pc_match_id&" . URL_API_TOKEN;
            $match_detail = json_decode(file_get_contents($match_detail_url), true)["match_details"][0];
            
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
                    $batting_perfs = $inning["bat"];
                    foreach ($batting_perfs as $batting_perf_idx => $batting_perf)
                    {
                        $pc_player_id = $batting_perf["batsman_id"];
                        //$player_id = $player_cache[$pc_player_id];
                        $player_perf_id = $player_perf_cache[$pc_player_id];
                        
                        $how_out = $batting_perf["how_out"];
                        
                        if ($how_out != DID_NOT_BAT)
                        {
                            $insert_batting_perf->bindValue(":player_perf_id", $player_perf_id);
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
            
            // End transaction for whole of match
            $db->exec('COMMIT');
        }
        
        $statement = $db->prepare('
            SELECT
                 p.Name
                ,COUNT(pp.PlayerPerformanceId) AS Games
                ,COUNT(bp.BattingPerformanceId) AS Innings
                ,SUM(bp.Runs) AS Runs
                ,((SUM(CAST(bp.Runs AS FLOAT)) / SUM(bp.Balls)) * 100.0) as StrikeRate
            FROM "Player" p
            INNER JOIN "PlayerPerformance" pp on pp.PlayerId = p.PlayerId
            LEFT JOIN "BattingPerformance" bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
            GROUP BY p.Name
            ORDER BY p.Name
            '
            );
        $result = $statement->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC))
        {
            print_r($row);
        }
    }
    
    main();
?>
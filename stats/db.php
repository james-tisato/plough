<?php
    namespace plough\stats;
    function db_insert_and_return_id($db, $insert_statement)
    {
        $insert_statement->execute();
        return $db->querySingle('SELECT last_insert_rowid()');
    }

    function db_bind_values_from_row($insert_statement, $row)
    {
        foreach($row as $key => $value)
            $insert_statement->bindValue(":$key", $value);
    }

    function db_truncate_table($db, $table_name)
    {
        $statement = $db->prepare('DELETE FROM ' . $table_name);
        $statement->execute();
    }

    function db_delete_season_from_table($db, $table_name, $season)
    {
        $statement = $db->prepare(
            'DELETE FROM ' . $table_name .
            ' WHERE Season = ' . $season
            );
        $statement->execute();
    }

    function db_delete_player_from_table($db, $table_name, $player_id)
    {
        $statement = $db->prepare(
            'DELETE FROM ' . $table_name .
            ' WHERE PlayerId = ' . $player_id
            );
        $statement->execute();
    }

    function db_enable_foreign_keys($db)
    {
        $db->exec('PRAGMA foreign_keys = ON;');
    }

    function db_create_player_view($db, $table_name)
    {
        $statement = $db->prepare(
           'CREATE VIEW ' . $table_name . 'View AS
            SELECT
                 p.Name
                ,s.*
            FROM ' . $table_name . ' s
            INNER JOIN Player p on p.PlayerId = s.PlayerId
            ');
        $statement->execute();
    }

    function db_create_player_with_matches_view($db, $table_name, $matches_table_name)
    {
        $statement = $db->prepare(
           'CREATE VIEW ' . $table_name . 'View AS
            SELECT
                 p.Name
                ,ms.Matches
                ,s.*
            FROM ' . $table_name . ' s
            INNER JOIN Player p on p.PlayerId = s.PlayerId
            INNER JOIN ' . $matches_table_name . ' ms on ms.PlayerId = s.PlayerId
            WHERE
                s.Season = ms.Season
            ');
        $statement->execute();
    }

    const MATCHES_SUMMARY_COLS = '
        MatchType TEXT,
        Season INTEGER,
        Matches INTEGER,
        MatchesCaptaining INTEGER,
        MatchesFielding INTEGER,
        MatchesKeeping INTEGER,
        ';

    const MATCHES_SUMMARY_INSERT = '(
            PlayerId, MatchType, Season, Matches, MatchesCaptaining, MatchesFielding, MatchesKeeping
            )
        VALUES (
            :PlayerId, :MatchType, :Season, :Matches, :MatchesCaptaining, :MatchesFielding, :MatchesKeeping
        )';

    const BATTING_SUMMARY_COLS = '
        MatchType TEXT,
        Season INTEGER,
        Innings INTEGER,
        NotOuts INTEGER,
        Runs INTEGER,
        Average REAL,
        StrikeRate REAL,
        HighScore INTEGER,
        HighScoreNotOut INTEGER,
        HighScoreMatchId INTEGER,
        Fifties INTEGER,
        Hundreds INTEGER,
        Ducks INTEGER,
        Balls INTEGER,
        Fours INTEGER,
        Sixes INTEGER,
        ';

    CONST BATTING_SUMMARY_INSERT = '(
            PlayerId, MatchType, Season, Innings, NotOuts, Runs, Average, StrikeRate,
            HighScore, HighScoreNotOut, HighScoreMatchId,
            Fifties, Hundreds, Ducks, Balls, Fours, Sixes
            )
         VALUES (
             :PlayerId, :MatchType, :Season, :Innings, :NotOuts, :Runs, :Average, :StrikeRate,
             :HighScore, :HighScoreNotOut, :HighScoreMatchId,
             :Fifties, :Hundreds, :Ducks, :Balls, :Fours, :Sixes
             )';

    const BOWLING_SUMMARY_COLS = '
        MatchType TEXT,
        Season INTEGER,
        CompletedOvers INTEGER,
        PartialBalls INTEGER,
        Maidens INTEGER,
        Runs INTEGER,
        Wickets INTEGER,
        Average REAL,
        EconomyRate REAL,
        StrikeRate REAL,
        BestBowlingWickets INTEGER,
        BestBowlingRuns INTEGER,
        BestBowlingMatchId INTEGER,
        FiveFors INTEGER,
        Wides INTEGER,
        NoBalls INTEGER,
        ';

    const BOWLING_SUMMARY_INSERT = '(
            PlayerId, MatchType, Season, CompletedOvers, PartialBalls, Maidens, Runs, Wickets, Average,
            EconomyRate, StrikeRate, BestBowlingWickets, BestBowlingRuns, BestBowlingMatchId,
            FiveFors, Wides, NoBalls
            )
         VALUES (
             :PlayerId, :MatchType, :Season, :CompletedOvers, :PartialBalls, :Maidens, :Runs, :Wickets, :Average,
             :EconomyRate, :StrikeRate, :BestBowlingWickets, :BestBowlingRuns, :BestBowlingMatchId,
             :FiveFors, :Wides, :NoBalls
             )';

    const FIELDING_SUMMARY_COLS = '
        MatchType TEXT,
        Season INTEGER,
        CatchesFielding INTEGER,
        RunOuts INTEGER,
        TotalFieldingWickets INTEGER,
        CatchesKeeping INTEGER,
        Stumpings INTEGER,
        TotalKeepingWickets INTEGER,
        ';

    const FIELDING_SUMMARY_INSERT = '(
            PlayerId, MatchType, Season, CatchesFielding, RunOuts, TotalFieldingWickets,
            CatchesKeeping, Stumpings, TotalKeepingWickets
            )
        VALUES (
            :PlayerId, :MatchType, :Season, :CatchesFielding, :RunOuts, :TotalFieldingWickets,
            :CatchesKeeping, :Stumpings, :TotalKeepingWickets
            )';

    function db_create_schema($db)
    {
        // Tables
        $db->query('CREATE TABLE DbUpdate (
            UpdateId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            UpdateTime DATETIME
            )');

        $db->query('CREATE TABLE Match (
            MatchId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PcMatchId INTEGER,
            Status TEXT,
            Season INTEGER,
            MatchDate DATETIME,
            CompetitionType TEXT,
            HomeClubId INTEGER,
            HomeClubName TEXT,
            HomeTeamId INTEGER,
            HomeTeamName TEXT,
            AwayClubId INTEGER,
            AwayClubName TEXT,
            AwayTeamId INTEGER,
            AwayTeamName TEXT,
            PloughClubId INTEGER,
            PloughTeamId INTEGER,
            PloughTeamName TEXT,
            PloughMatch INTEGER,
            PloughHome INTEGER,
            PloughWonMatch INTEGER,
            PloughWonToss INTEGER,
            PloughBattedFirst INTEGER,
            OppoClubId INTEGER,
            OppoClubName TEXT,
            OppoTeamId INTEGER,
            OppoTeamName TEXT,
            Result TEXT,
            ResultAppliedToTeamId INTEGER,
            TossWonByTeamId INTEGER,
            BattedFirstTeamId INTEGER
            )');
        $db->query('CREATE INDEX MatchSeasonIndex ON Match (Season)');
        $db->query('CREATE INDEX MatchCompetitionTypeIndex ON Match (CompetitionType)');

        $db->query('CREATE TABLE Player (
            PlayerId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PcPlayerId INTEGER,
            Name TEXT,
            Active INTEGER
            )');

        $db->query('CREATE TABLE PlayerPerformance (
            PlayerPerformanceId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            MatchId INTEGER,
            PlayerId INTEGER,
            Captain INTEGER,
            Wicketkeeper INTEGER,
            FOREIGN KEY(MatchId) REFERENCES Match(MatchId) ON DELETE CASCADE,
            FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId) ON DELETE CASCADE
            )');
        $db->query('CREATE INDEX PlayerPerformanceMatchIdIndex ON PlayerPerformance (MatchId)');
        $db->query('CREATE INDEX PlayerPerformancePlayerIdIndex ON PlayerPerformance (PlayerId)');

        // Raw performances
        $db->query('CREATE TABLE BattingPerformance (
            BattingPerformanceId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PlayerPerformanceId INTEGER,
            PlayerId INTEGER,
            Position INTEGER,
            HowOut TEXT,
            Runs INTEGER,
            Balls INTEGER,
            Fours INTEGER,
            Sixes INTEGER,
            FOREIGN KEY(PlayerPerformanceId) REFERENCES PlayerPerformance(PlayerPerformanceId) ON DELETE CASCADE,
            FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId) ON DELETE CASCADE
            )');
        $db->query('CREATE INDEX BattingPerformancePlayerPerformanceIdIndex ON BattingPerformance (PlayerPerformanceId)');
        $db->query('CREATE INDEX BattingPerformancePlayerIdIndex ON BattingPerformance (PlayerId)');

        $db->query('CREATE TABLE BattingPartnership (
            BattingPartnershipId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            BattingPerformanceIdOut INTEGER,
            BattingPerformanceIdIn INTEGER,
            Wicket INTEGER,
            Runs INTEGER,
            NotOut INTEGER,
            FOREIGN KEY(BattingPerformanceIdOut) REFERENCES BattingPerformance(BattingPerformanceId) ON DELETE CASCADE,
            FOREIGN KEY(BattingPerformanceIdIn) REFERENCES BattingPerformance(BattingPerformanceId) ON DELETE CASCADE
            )');
        $db->query('CREATE INDEX BattingPartnershipBattingPerformanceIdOutIndex ON BattingPartnership (BattingPerformanceIdOut)');
        $db->query('CREATE INDEX BattingPartnershipBattingPerformanceIdInIndex ON BattingPartnership (BattingPerformanceIdIn)');

        $db->query('CREATE TABLE BowlingPerformance (
            BowlingPerformanceId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PlayerPerformanceId INTEGER,
            PlayerId INTEGER,
            Position INTEGER,
            CompletedOvers INTEGER,
            PartialBalls INTEGER,
            Maidens INTEGER,
            Runs INTEGER,
            Wickets INTEGER,
            Wides INTEGER,
            NoBalls INTEGER,
            FOREIGN KEY(PlayerPerformanceId) REFERENCES PlayerPerformance(PlayerPerformanceId) ON DELETE CASCADE,
            FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId) ON DELETE CASCADE
            )');
        $db->query('CREATE INDEX BowlingPerformancePlayerPerformanceIdIndex ON BowlingPerformance (PlayerPerformanceId)');
        $db->query('CREATE INDEX BowlingPerformancePlayerIdIndex ON BowlingPerformance (PlayerId)');

        $db->query('CREATE TABLE FieldingPerformance (
            FieldingPerformanceId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PlayerPerformanceId INTEGER,
            PlayerId INTEGER,
            Catches INTEGER,
            RunOuts INTEGER,
            Stumpings INTEGER,
            FOREIGN KEY(PlayerPerformanceId) REFERENCES PlayerPerformance(PlayerPerformanceId) ON DELETE CASCADE,
            FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId) ON DELETE CASCADE
            )');
        $db->query('CREATE INDEX FieldingPerformancePlayerPerformanceIdIndex ON FieldingPerformance (PlayerPerformanceId)');
        $db->query('CREATE INDEX FieldingPerformancePlayerIdIndex ON FieldingPerformance (PlayerId)');

        // Performance summaries
        $db->query('CREATE TABLE SeasonMatchesSummary (
            SeasonMatchesSummaryId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PlayerId INTEGER,'
            . MATCHES_SUMMARY_COLS .
            'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
            )');
        $db->query('CREATE INDEX SeasonMatchesSummaryPlayerIdIndex ON SeasonMatchesSummary (PlayerId)');
        $db->query('CREATE INDEX SeasonMatchesSummaryMatchTypeIndex ON SeasonMatchesSummary (MatchType)');

        $db->query('CREATE TABLE CareerMatchesSummaryBase (
            CareerMatchesSummaryBaseId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PlayerId INTEGER, '
            . MATCHES_SUMMARY_COLS .
            'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
            )');
        $db->query('CREATE INDEX CareerMatchesSummaryBasePlayerIdIndex ON CareerMatchesSummaryBase (PlayerId)');

        $db->query('CREATE TABLE CareerMatchesSummary (
            CareerMatchesSummaryId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PlayerId INTEGER, '
            . MATCHES_SUMMARY_COLS .
            'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
            )');
        $db->query('CREATE INDEX CareerMatchesSummaryPlayerIdIndex ON CareerMatchesSummary (PlayerId)');
        $db->query('CREATE INDEX CareerMatchesSummaryMatchTypeIndex ON CareerMatchesSummary (MatchType)');

        $db->query('CREATE TABLE SeasonBattingSummary (
            SeasonBattingSummaryId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PlayerId INTEGER,'
            . BATTING_SUMMARY_COLS .
            'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId),
            FOREIGN KEY(HighScoreMatchId) REFERENCES Match(MatchId) ON DELETE SET NULL
            )');
        $db->query('CREATE INDEX SeasonBattingSummaryPlayerIdIndex ON SeasonBattingSummary (PlayerId)');
        $db->query('CREATE INDEX SeasonBattingSummaryHighScoreMatchIdIndex ON SeasonBattingSummary (HighScoreMatchId)');
        $db->query('CREATE INDEX SeasonBattingSummaryMatchTypeIndex ON SeasonBattingSummary (MatchType)');

        $db->query('CREATE TABLE CareerBattingSummaryBase (
            CareerBattingSummaryBaseId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PlayerId INTEGER, '
            . BATTING_SUMMARY_COLS .
            'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId),
            FOREIGN KEY(HighScoreMatchId) REFERENCES Match(MatchId) ON DELETE SET NULL
            )');
        $db->query('CREATE INDEX CareerBattingSummaryBasePlayerIdIndex ON CareerBattingSummaryBase (PlayerId)');
        $db->query('CREATE INDEX CareerBattingSummaryBaseHighScoreMatchIdIndex ON CareerBattingSummaryBase (HighScoreMatchId)');

        $db->query('CREATE TABLE CareerBattingSummary (
            CareerBattingSummaryId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PlayerId INTEGER, '
            . BATTING_SUMMARY_COLS .
            'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId),
            FOREIGN KEY(HighScoreMatchId) REFERENCES Match(MatchId) ON DELETE SET NULL
            )');
        $db->query('CREATE INDEX CareerBattingSummaryPlayerIdIndex ON CareerBattingSummary (PlayerId)');
        $db->query('CREATE INDEX CareerBattingSummaryHighScoreMatchIdIndex ON CareerBattingSummary (HighScoreMatchId)');
        $db->query('CREATE INDEX CareerBattingSummaryMatchTypeIndex ON CareerBattingSummary (MatchType)');

        $db->query('CREATE TABLE SeasonBowlingSummary (
            SeasonBowlingSummaryId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PlayerId INTEGER,'
            . BOWLING_SUMMARY_COLS .
            'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId),
            FOREIGN KEY(BestBowlingMatchId) REFERENCES Match(MatchId) ON DELETE SET NULL
            )');
        $db->query('CREATE INDEX SeasonBowlingSummaryPlayerIdIndex ON SeasonBowlingSummary (PlayerId)');
        $db->query('CREATE INDEX SeasonBowlingSummaryBestBowlingMatchIdIndex ON SeasonBowlingSummary (BestBowlingMatchId)');
        $db->query('CREATE INDEX SeasonBowlingSummaryMatchTypeIndex ON SeasonBowlingSummary (MatchType)');

        $db->query('CREATE TABLE CareerBowlingSummaryBase (
            CareerBowlingSummaryBaseId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PlayerId INTEGER,'
            . BOWLING_SUMMARY_COLS .
            'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId),
            FOREIGN KEY(BestBowlingMatchId) REFERENCES Match(MatchId) ON DELETE SET NULL
            )');
        $db->query('CREATE INDEX CareerBowlingSummaryBasePlayerIdIndex ON CareerBowlingSummaryBase (PlayerId)');
        $db->query('CREATE INDEX CareerBowlingSummaryBaseBestBowlingMatchIdIndex ON CareerBowlingSummaryBase (BestBowlingMatchId)');

        $db->query('CREATE TABLE CareerBowlingSummary (
            CareerBowlingSummaryId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PlayerId INTEGER,'
            . BOWLING_SUMMARY_COLS .
            'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId),
            FOREIGN KEY(BestBowlingMatchId) REFERENCES Match(MatchId) ON DELETE SET NULL
            )');
        $db->query('CREATE INDEX CareerBowlingSummaryPlayerIdIndex ON CareerBowlingSummary (PlayerId)');
        $db->query('CREATE INDEX CareerBowlingSummaryHighScoreMatchIdIndex ON CareerBowlingSummary (BestBowlingMatchId)');
        $db->query('CREATE INDEX CareerBowlingSummaryMatchTypeIndex ON CareerBowlingSummary (MatchType)');

        $db->query('CREATE TABLE SeasonFieldingSummary (
            SeasonFieldingSummaryId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PlayerId INTEGER,'
            . FIELDING_SUMMARY_COLS .
            'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
            )');
        $db->query('CREATE INDEX SeasonFieldingSummaryPlayerIdIndex ON SeasonFieldingSummary (PlayerId)');
        $db->query('CREATE INDEX SeasonFieldingSummaryMatchTypeIndex ON SeasonFieldingSummary (MatchType)');

        $db->query('CREATE TABLE CareerFieldingSummaryBase (
            CareerFieldingSummaryBaseId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PlayerId INTEGER,'
            . FIELDING_SUMMARY_COLS .
            'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
            )');
        $db->query('CREATE INDEX CareerFieldingSummaryBasePlayerIdIndex ON CareerFieldingSummaryBase (PlayerId)');

        $db->query('CREATE TABLE CareerFieldingSummary (
            CareerFieldingSummaryId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PlayerId INTEGER,'
            . FIELDING_SUMMARY_COLS .
            'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
            )');
        $db->query('CREATE INDEX CareerFieldingSummaryPlayerIdIndex ON CareerFieldingSummary (PlayerId)');
        $db->query('CREATE INDEX CareerFieldingSummaryMatchTypeIndex ON CareerFieldingSummary (MatchType)');

        $db->query('CREATE TABLE Milestone (
            MilestoneId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PlayerId INTEGER,
            Season INTEGER,
            State TEXT,
            Type TEXT,
            Description TEXT,
            FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
            )');
        $db->query('CREATE INDEX MilestonePlayerIdIndex ON Milestone (PlayerId)');

        $db->query('CREATE TABLE LeagueTableEntry (
            LeagueTableEntryId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            Season INTEGER,
            Division TEXT,
            Position INTEGER,
            Club TEXT,
            Abandoned INTEGER,
            Played INTEGER,
            Won INTEGER,
            Lost INTEGER,
            Tied INTEGER,
            BonusPoints INTEGER,
            PenaltyPoints INTEGER,
            TotalPoints INTEGER,
            AveragePoints REAL
            )');

        // Views
        db_create_player_view($db, "PlayerPerformance");

        db_create_player_view($db, "SeasonMatchesSummary");
        db_create_player_view($db, "CareerMatchesSummary");
        db_create_player_view($db, "CareerMatchesSummaryBase");

        db_create_player_view($db, "BattingPerformance");
        db_create_player_with_matches_view($db, "SeasonBattingSummary", "SeasonMatchesSummary");
        db_create_player_with_matches_view($db, "CareerBattingSummary", "CareerMatchesSummary");
        db_create_player_with_matches_view($db, "CareerBattingSummaryBase", "CareerMatchesSummaryBase");

        db_create_player_view($db, "BowlingPerformance");
        db_create_player_with_matches_view($db, "SeasonBowlingSummary", "SeasonMatchesSummary");
        db_create_player_with_matches_view($db, "CareerBowlingSummary", "CareerMatchesSummary");
        db_create_player_with_matches_view($db, "CareerBowlingSummaryBase", "CareerMatchesSummaryBase");

        db_create_player_view($db, "FieldingPerformance");
        db_create_player_with_matches_view($db, "SeasonFieldingSummary", "SeasonMatchesSummary");
        db_create_player_with_matches_view($db, "CareerFieldingSummary", "CareerMatchesSummary");
        db_create_player_with_matches_view($db, "CareerFieldingSummaryBase", "CareerMatchesSummaryBase");

        db_create_player_view($db, "Milestone");
    }

    function db_create_insert_update($db)
    {
        return $db->prepare(
            'INSERT INTO DbUpdate (
                UpdateTime
                )
             VALUES (
                 :UpdateTime
                 )'
            );
    }

    function db_create_insert_match($db)
    {
        return $db->prepare(
            'INSERT INTO Match (
                PcMatchId, Status, Season, MatchDate, CompetitionType, HomeClubId, HomeClubName, HomeTeamId, HomeTeamName,
                AwayClubId, AwayClubName, AwayTeamId, AwayTeamName, PloughClubId, PloughTeamId, PloughTeamName,
                PloughMatch, PloughHome, PloughWonMatch, PloughWonToss, PloughBattedFirst,
                OppoClubId, OppoClubName, OppoTeamId, OppoTeamName,
                Result, ResultAppliedToTeamId, TossWonByTeamId, BattedFirstTeamId
                )
             VALUES (
                 :PcMatchId, :Status, :Season, :MatchDate, :CompetitionType, :HomeClubId, :HomeClubName, :HomeTeamId, :HomeTeamName,
                 :AwayClubId, :AwayClubName, :AwayTeamId, :AwayTeamName, :PloughClubId, :PloughTeamId, :PloughTeamName,
                 :PloughMatch, :PloughHome, :PloughWonMatch, :PloughWonToss, :PloughBattedFirst,
                 :OppoClubId, :OppoClubName, :OppoTeamId, :OppoTeamName,
                 :Result, :ResultAppliedToTeamId, :TossWonByTeamId, :BattedFirstTeamId
                 )'
            );
    }

    function db_create_delete_match($db)
    {
        return $db->prepare(
            'DELETE FROM Match WHERE PcMatchId = :PcMatchId'
            );
    }

    function db_create_insert_player($db)
    {
        return $db->prepare(
            'INSERT INTO Player (
                PcPlayerId, Name, Active
                )
             VALUES (
                 :PcPlayerId, :Name, :Active
                 )'
            );
    }

    function db_create_update_player_name($db)
    {
        return $db->prepare(
            'UPDATE Player SET Name = :Name WHERE PcPlayerId = :PcPlayerId'
            );
    }

    function db_create_update_player_active($db)
    {
        return $db->prepare(
            'UPDATE Player SET Active = :Active WHERE Name = :Name'
            );
    }

    function db_create_insert_player_performance($db)
    {
        return $db->prepare(
            'INSERT INTO PlayerPerformance (
                MatchId, PlayerId, Captain, Wicketkeeper
                )
             VALUES (
                 :MatchId, :PlayerId, :Captain, :Wicketkeeper
                 )'
            );
    }

    function db_create_insert_batting_performance($db)
    {
        return $db->prepare(
            'INSERT INTO BattingPerformance (
                PlayerPerformanceId, PlayerId, Position, HowOut, Runs, Balls, Fours, Sixes
                )
             VALUES (
                 :PlayerPerformanceId, :PlayerId, :Position, :HowOut, :Runs, :Balls, :Fours, :Sixes
                 )'
            );
    }

    function db_create_insert_batting_partnership($db)
    {
        return $db->prepare(
            'INSERT INTO BattingPartnership (
                BattingPerformanceIdOut, BattingPerformanceIdIn, Wicket, Runs, NotOut
                )
             VALUES (
                 :BattingPerformanceIdOut, :BattingPerformanceIdIn, :Wicket, :Runs, :NotOut
                )'
            );
    }

    function db_create_insert_bowling_performance($db)
    {
        return $db->prepare(
            'INSERT INTO BowlingPerformance (
                PlayerPerformanceId, PlayerId, Position, CompletedOvers, PartialBalls, Maidens, Runs, Wickets, Wides, NoBalls
                )
             VALUES (
                 :PlayerPerformanceId, :PlayerId, :Position, :CompletedOvers, :PartialBalls, :Maidens, :Runs, :Wickets, :Wides, :NoBalls
                 )'
            );
    }

    function db_create_insert_fielding_performance($db)
    {
        return $db->prepare(
            'INSERT INTO FieldingPerformance (
                PlayerPerformanceId, PlayerId, Catches, RunOuts, Stumpings
                )
             VALUES (
                 :PlayerPerformanceId, :PlayerId, :Catches, :RunOuts, :Stumpings
                 )'
            );
    }

    function db_create_insert_season_matches_summary($db)
    {
        return $db->prepare('INSERT INTO SeasonMatchesSummary ' . MATCHES_SUMMARY_INSERT);
    }

    function db_create_insert_career_matches_summary_base($db)
    {
        return $db->prepare('INSERT INTO CareerMatchesSummaryBase ' . MATCHES_SUMMARY_INSERT);
    }

    function db_create_insert_career_matches_summary($db)
    {
        return $db->prepare('INSERT INTO CareerMatchesSummary ' . MATCHES_SUMMARY_INSERT);
    }

    function db_create_insert_season_batting_summary($db)
    {
        return $db->prepare('INSERT INTO SeasonBattingSummary ' . BATTING_SUMMARY_INSERT);
    }

    function db_create_insert_career_batting_summary_base($db)
    {
        return $db->prepare('INSERT INTO CareerBattingSummaryBase ' . BATTING_SUMMARY_INSERT);
    }

    function db_create_insert_career_batting_summary($db)
    {
        return $db->prepare('INSERT INTO CareerBattingSummary ' . BATTING_SUMMARY_INSERT);
    }

    function db_create_insert_season_bowling_summary($db)
    {
        return $db->prepare('INSERT INTO SeasonBowlingSummary ' . BOWLING_SUMMARY_INSERT);
    }

    function db_create_insert_career_bowling_summary_base($db)
    {
        return $db->prepare('INSERT INTO CareerBowlingSummaryBase ' . BOWLING_SUMMARY_INSERT);
    }

    function db_create_insert_career_bowling_summary($db)
    {
        return $db->prepare('INSERT INTO CareerBowlingSummary ' . BOWLING_SUMMARY_INSERT);
    }

    function db_create_insert_season_fielding_summary($db)
    {
        return $db->prepare('INSERT INTO SeasonFieldingSummary ' . FIELDING_SUMMARY_INSERT);
    }

    function db_create_insert_career_fielding_summary_base($db)
    {
        return $db->prepare('INSERT INTO CareerFieldingSummaryBase ' . FIELDING_SUMMARY_INSERT);
    }

    function db_create_insert_career_fielding_summary($db)
    {
        return $db->prepare('INSERT INTO CareerFieldingSummary ' . FIELDING_SUMMARY_INSERT);
    }

    function db_create_insert_milestone($db)
    {
        return $db->prepare(
            'INSERT INTO Milestone (
                PlayerId, Season, State, Type, Description
                )
             VALUES (
                 :PlayerId, :Season, :State, :Type, :Description
                )'
            );
    }

    function db_create_insert_league_table_entry($db)
    {
        return $db->prepare(
            'INSERT INTO LeagueTableEntry (
                Season, Division, Position, Club, Abandoned, Played, Won, Lost, Tied,
                BonusPoints, PenaltyPoints, TotalPoints, AveragePoints
                )
             VALUES (
                 :Season, :Division, :Position, :Club, :Abandoned, :Played, :Won, :Lost, :Tied,
                 :BonusPoints, :PenaltyPoints, :TotalPoints, :AveragePoints
                )'
            );
    }
?>

<?php
    namespace plough\stats;

    require_once("db.php");

    // Constants
    const NO_PC_PLAYER_ID = -1;

    const DATE_FORMAT = "Y-m-d";
    const DATETIME_FORMAT = "Y-m-d H:i:s";
    const DATETIME_FRIENDLY_FORMAT = "h:i A, D j M Y";

    // Stats period types
    const PERIOD_CAREER = 1;
    const PERIOD_SEASON = 2;

    // DB helpers
    function get_last_update_datetime($db)
    {
        $statement = $db->prepare(
           'SELECT
                 UpdateTime
            FROM DbUpdate
            ORDER BY UpdateTime DESC
            LIMIT 1
            ');
        $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$result)
        {
            return null;
        }
        else
        {
            $last_update_str = $result["UpdateTime"];
            return date_create_from_format(DATETIME_FORMAT, $last_update_str);
        }
    }

    function get_players_by_name($db)
    {
        $players = array();
        $statement = $db->prepare(
            'SELECT * FROM Player ORDER BY Name'
            );
        $result = $statement->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC))
            $players[$row["Name"]] = $row;

        return $players;
    }

    // Batting helpers
    function get_batting_average($runs, $innings, $not_outs)
    {
        $num_outs = $innings - $not_outs;
        if ($num_outs > 0)
            return ($runs / $num_outs);
        else
            return null;
    }

    function get_batting_strike_rate($runs, $balls)
    {
        if ($balls)
            return ($runs / $balls) * 100.0;
        else
            return null;
    }

    // Bowling helpers
    function collapse_overs($completed_overs, $partial_balls)
    {
        $partial_overs = floor($partial_balls / 6);
        $partial_balls = $partial_balls % 6;
        $completed_overs += $partial_overs;
        return array($completed_overs, $partial_balls);
    }

    function get_bowling_average($runs, $wickets)
    {
        if ($wickets > 0)
            return ($runs / $wickets);
        else
            return null;
    }

    function get_total_balls($completed_overs, $partial_balls)
    {
        return $completed_overs * 6 + $partial_balls;
    }

    function get_bowling_economy_rate($runs, $completed_overs, $partial_balls)
    {
        $total_balls = get_total_balls($completed_overs, $partial_balls);
        if ($total_balls > 0)
            return ($runs / ($total_balls / 6.0));
        else
            return null;
    }

    function get_bowling_strike_rate($completed_overs, $partial_balls, $wickets)
    {
        $total_balls = get_total_balls($completed_overs, $partial_balls);
        if ($wickets > 0)
            return ($total_balls / $wickets);
        else
            return null;
    }
?>

<?php
    namespace plough\stats;
    
    require_once("db.php");

    // Date/time helpers
    function get_last_update_datetime($db)
    {
        $statement = $db->prepare(
           'SELECT
                 UpdateTime
            FROM DbUpdate
            ORDER BY UpdateTime DESC
            LIMIT 1
            ');
        $last_update_str = $statement->execute()->fetchArray(SQLITE3_ASSOC)["UpdateTime"];
        return date_create_from_format(DATETIME_FORMAT, $last_update_str);
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

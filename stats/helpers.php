<?php
    namespace plough\stats;
    use plough\log;

    require_once("db.php");
    require_once(__DIR__ . "/../logger.php");

    require_once(__DIR__ . '/../vendor/autoload.php');

    // Constants
    const NO_PC_PLAYER_ID = -1;
    const CLUB_NAME = "Ploughmans CC";

    const DATE_FORMAT = "Y-m-d";
    const DATETIME_FORMAT = "Y-m-d H:i:s";
    const DATETIME_FRIENDLY_FORMAT = "h:i A, D j M Y";
    const EXCEL_DATE_FORMAT = "d/m/y";
    const PC_DATE_FORMAT = "d/m/Y";

    // Stats period types
    const PERIOD_CAREER = 1;
    const PERIOD_SEASON = 2;

    function get_period_name($period)
    {
        if ($period === PERIOD_CAREER)
            return "Career";
        else if ($period === PERIOD_SEASON)
            return "Season";
    }

    function strip_link_html($item)
    {
        $matches = array();
        if (preg_match("/<a.*>(.*?)<\/a>/", $item, $matches))
            return $matches[1];
        else
            return $item;
    }

    function safe_file_get_contents(
        string $filename,
        bool $use_include_path = false,
        ?\resource $context = null,
        int $attempts = 4
        )
    {
        //log\debug($filename);
        while (true)
        {
            $result = file_get_contents($filename, $use_include_path, $context);
            if (!$result)
            {
                if ($attempts > 0)
                {
                    log\warning("************** file_get_contents failed - retrying **************");
                    $attempts--;
                    sleep(3);
                }
                else 
                {
                    log\critical("************** file_get_contents failed - all retry attempts exhausted **************");
                    throw new \Exception("file_get_contents failed - all retry attempts exhausted");
                }
            }
            else
            {
                return $result;
            }
        }
    }

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

    function get_league_divisions_for_season($season) {
        if ($season <= 2018)
            return array("2");
        else if ($season == 2019)
            return array("1");
        else if ($season == 2020 || $season == 2021)
            return array("prem");
        else if ($season == 2022)
            return array("prem", "2");
        else if ($season == 2023)
            return array("1", "2");
        else if ($season == 2024)
            return array("1", "2");
        else
            throw new \Exception("Unknown season {$season}");
    }
?>

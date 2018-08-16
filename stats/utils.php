<?php
    namespace plough\stats;

    function int_from_bool($bool)
    {
        if ($bool)
            return 1;
        else
            return 0;
    }

    function bool_from_str($str)
    {
        return (boolean) json_decode($str);
    }
    
    function get_wp_stats_root()
    {
        return WP_PLUGIN_DIR . "/plough/stats";
    }
?>
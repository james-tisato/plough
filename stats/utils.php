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
    
    function get_stats_root()
    {
        return WP_PLUGIN_DIR . "/plough/stats";
    }
    
    function fputcsv_eol($handle, $array, $delimiter = ',', $enclosure = '"', $eol = PHP_EOL)
    {
        $return = fputcsv($handle, $array, $delimiter, $enclosure);
        if($return !== FALSE && "\n" != $eol && 0 === fseek($handle, -1, SEEK_CUR))
        {
            fwrite($handle, $eol);
        }
        
        return $return;
    }
?>
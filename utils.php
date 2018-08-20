<?php
    namespace plough;

    const SEPARATOR_LINE = "---------------------------------------------------------------------------------------------------------";
    
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
    
    function get_plugin_root()
    {
        return WP_PLUGIN_DIR . "/plough";
    }
    
    function get_stats_root()
    {
        return get_plugin_root() . "/stats";
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
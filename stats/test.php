<?php
    namespace plough\stats;

    use plough\log;
    
    require_once("config.php");
    require_once("local-wp-mock.php");
    require_once("updater.php");
    require_once(__DIR__ . "/../logger.php");
    require_once(__DIR__ . "/../utils.php");
    
    const TEST_DIR = __DIR__ . "/test/test/";
    const BASELINE_ROOT_DIR = __DIR__ . "/test/baseline/";
    const RESULT_ROOT_DIR = __DIR__ . "/test/result/";
    
    log\init(true);
    log\info("Initialising test harness");
    
    $tests_passed = 0;
    $tests_failed = 0;
    
    foreach (glob(TEST_DIR . "*.xml") as $test_file)
    {
        $test_name = basename($test_file, ".xml");
        
        log\info("");
        log\info(\plough\SEPARATOR_LINE);
        log\info("Test [" . $test_name . "]");
        log\info("");
        
        $config = Config::fromXmlFile($test_file);
        
        // Backup DB
        $db_path = \plough\get_stats_db_path($config);
        $db_path_backup = $db_path . ".backup";
        if (file_exists($db_path))
            copy($db_path, $db_path_backup);
	
        $updater = new Updater($config);
        $updater->update_stats();
        
        $baseline_dir = BASELINE_ROOT_DIR . $test_name;
        $result_dir = RESULT_ROOT_DIR . $test_name;
        
        $test_passed = true;
        foreach (glob($result_dir . "/*.csv") as $result_path)
        {
            $output_filename = basename($result_path);
            $baseline_path = $baseline_dir . "/" . $output_filename;
            
            if (!file_exists($baseline_path))
            {
                log\warning("Test [" . $test_name . "] - baseline file not found for [" . $output_filname . "]");
                $test_passed = false;
            }
                
            $result_contents = file_get_contents($result_path);
            $baseline_contents = file_get_contents($baseline_path);
            
            if ($baseline_contents != $result_contents)
            {
                log\error("Test [" . $test_name . "] failed - difference found in [" . $output_filename . "]");
                $test_passed = false;
            }
        }
        
        if ($test_passed)
        {
            $tests_passed += 1;
            
            // Restore DB as test has passed
            if (file_exists($db_path_backup))
            {
                copy($db_path_backup, $db_path);
                unlink($db_path_backup);
            }
        }
        else
        {
            $tests_failed += 1;
        }
    }
    
    log\info("");
    log\info(\plough\SEPARATOR_LINE);
    log\info("");
    log\info("Tests completed");
    log\info("  Tests passed: " . $tests_passed);
    log\info("  Tests failed: " . $tests_failed);
?>
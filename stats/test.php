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

    const IGNORE_PHRASES = array("Last updated");

    log\init(true);
    log\info("");
    log\info("Initialising test harness");

    $tests_passed = 0;
    $tests_failed = 0;

    if ($argc == 1)
        $filter = "*";
    elseif ($argc == 2)
        $filter = $argv[1];

    foreach (glob(TEST_DIR . "{$filter}.xml") as $test_file)
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
        if (file_exists($db_path) && !$config->clearDb())
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
                log\warning("Test [" . $test_name . "] - baseline file not found for [" . $output_filename . "]");
                $test_passed = false;
            }

            $baseline_file = file($baseline_path);
            $result_file = file($result_path);

            if (count($baseline_file) != count($result_file))
            {
                log\error("Test [" . $test_name . "] failed - line count difference found in [" . $output_filename . "]");
                $test_passed = false;
            }
            else
            {
                foreach ($baseline_file as $idx => $base_content)
                {
                    $result_content = $result_file[$idx];

                    if ($base_content != $result_content)
                    {
                        // Check if difference should be ignored
                        $ignore_difference = false;
                        foreach (IGNORE_PHRASES as $ignore_phrase)
                        {
                            if (strpos($base_content, $ignore_phrase) !== false)
                            {
                                $ignore_difference = true;
                                break;
                            }
                        }

                        if (!$ignore_difference)
                        {
                            log\error("Test [" . $test_name . "] failed - difference found in [" . $output_filename . "]");
                            $test_passed = false;
                            break;
                        }
                    }
                }
            }
        }

        if ($test_passed)
        {
            $tests_passed += 1;

            // Restore DB as test has passed
            if (file_exists($db_path_backup) && !$config->clearDb())
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
    log\info("");
?>

<?php

/**
 * Debug dump
 * @param $raw
 */
function dump($raw)
{
    print_r($raw);
    echo "\n";
}

/**
 * get list subdirectories
 * @param $dir - parent directory
 * @return array of subdirectories
 */
function get_dirs($dir)
{
    if (!is_dir($dir))
        return;

    $dirs = array();
    $file_list = scandir($dir);
    foreach ($file_list as $file_name)
    {
        if (is_dir($dir . '/' . $file_name) == false)
            continue;

        if ($file_name == '.' || $file_name == '..')
            continue;

        $dirs[] = $file_name;
    }

    return $dirs;
}

/**
 * Error text message output
 * @param $error_text
 */
function print_error($error_text)
{
    echo $error_text . "\n";
}


/**
 * Run command in console and return output
 * @param $cmd - command
 * @return string
 */
function run_cmd($cmd)
{
    $fd = popen($cmd . ' 2>&1', 'r');

    $log = '';
    while($str = fgets($fd))
    {
        echo $str;
        $log .= $str;
    }

    fclose($fd);

    return $log;
}

function run_remote_cmd(array $ssh_settings, $cmd)
{
    $ssh = 'ssh -f ' . $ssh_settings['login'] .
            '@' . $ssh_settings['host'] .
            ' -p' . $ssh_settings['port'] . ' ';

    dump($ssh . '"' . $cmd . '"');
    return run_cmd($ssh . '"' . $cmd . '"'); // TODO:
}



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
 * @param $viewed - true or false. Display output yes or no
 * @return string
 */
function run_cmd($cmd, $viewed = true)
{
    $fd = popen($cmd . ' 2>&1', 'r');

    $log = '';
    while($str = fgets($fd))
    {
        if ($viewed)
            echo $str;

        $log .= $str;
    }

    $rc = pclose($fd);

    return array('log' => $log, 'rc' => $rc);
}

function run_remote_cmd(array $ssh_settings, $cmd, $fork = false)
{
    if ($fork == true)
    {
        $pid = pcntl_fork();
        if ($pid == -1)
            throw new Exception("can't fork");

        if ($pid) // Current process return
            return;
    }

    // New children process
    $ssh = 'ssh -f ' . $ssh_settings['login'] .
            '@' . $ssh_settings['host'] .
            ' -p' . $ssh_settings['port'] . ' ';

    dump($ssh . '"' . $cmd . '"');
    return run_cmd($ssh . '"' . $cmd . '"');
}

function get_dot_file_content($dot_file)
{
    $content = file_get_contents($dot_file);

    // split comments
    $content = preg_replace('/^#.*/', '', $content);

    return trim($content);
}

/**
 * get list of children PID
 * @param $parent_pid
 * @return array of children PID or false
 */
function get_child_pids($parent_pid)
{
    $ret = run_cmd("ps -ax --format '%P %p'", false);
    $rows = explode("\n", $ret['log']);
    $pid_list = array();

    foreach ($rows as $row)
    {
        preg_match('/([0-9]+)[ ]+([0-9]+)/s', $row, $matched);
        if (!$matched)
            continue;

        $ppid = $matched[1];
        $pid = $matched[2];
        $pid_list[$ppid][] = $pid;
    }

    if (!isset($pid_list[$parent_pid]))
        return false;

    return $pid_list[$parent_pid];
}

/**
 * Kill all proceses
 * @param $kill_pid
 */
function kill_all($kill_pid)
{
    $child_pids = get_child_pids($kill_pid);
    if ($child_pids)
        foreach ($child_pids as $child_pid)
            kill_all($child_pid);

    run_cmd('kill -9 ' . $kill_pid);
}
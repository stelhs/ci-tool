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
        return false;

    $dirs = array();
    $file_list = scandir($dir);
    foreach ($file_list as $file_name)
    {
        if (is_dir($dir . '/' . $file_name) == false)
            continue;

        if ($file_name[0] == '.')
            continue;

        $dirs[] = $file_name;
    }

    return $dirs;
}

/**
 * Logging function
 * @param $msg_level LOG_ERR or LOG_WARNING or LOG_NOTICE
 * @param $text - error description
 */

function msg_log($msg_level, $text)
{
    global $_CONFIG, $utility_name;

    $enable = false;
    foreach ($_CONFIG['debug_level'] as $level)
        if ($level == $msg_level)
            $enable = true;

    if (!$enable)
        return;

    syslog($msg_level, $utility_name . ': ' . $text);
    switch ($msg_level)
    {
        case LOG_ERR:
            echo 'CI-tool: ' . $utility_name . ': Error: ' . $text . "\n";
            break;
    }
}

/**
 * Run command in console and return output
 * @param $cmd - command
 * @param bool $fork - true - run in new thread (not receive results), false - run in current thread
 * @param $stdin_data - optional data direct to stdin
 * @param $print_stdout - optional flag indicates that all output from the process should be printed
 * @return array with keys: rc and log
 */
function run_cmd($cmd, $fork = false, $stdin_data = '', $print_stdout = false)
{
    msg_log(LOG_NOTICE, 'run cmd: ' . $cmd);

    if ($fork == true)
    {
        $pid = pcntl_fork();
        if ($pid == -1)
            throw new Exception("can't fork() in run_cmd()");

        if ($pid) // Current process return
            return;

        // new process continue
        fclose(STDERR);
        fclose(STDIN);
        fclose(STDOUT);
    }

    $descriptorspec = array(
        0 => array("pipe", "r"),
        1 => array("pipe", "w"),
    );

    $fd = proc_open( "bash 2>&1 << EOF\n" . $cmd . "\nEOF\n", $descriptorspec, $pipes);
    if ($fd == false)
        throw new Exception("proc_open() error in run_cmd()");

    $fd_write = $pipes[0];
    $fd_read = $pipes[1];

    if ($stdin_data)
        fwrite($fd_write, $stdin_data);

    fclose($fd_write);

    $log = '';
    while($str = fgets($fd_read))
    {
        $log .= $str;
        if ($print_stdout)
            echo $str;
    }

    fclose($fd_read);
    $rc = proc_close($fd);
    if ($rc == -1)
        throw new Exception("proc_close() error in run_cmd()");

    if ($fork == true)
        exit;

    return array('log' => trim($log), 'rc' => $rc);
}

/**
 * Run command on remote server
 * @param array $server - settings of server (from $_CONFIG['ci_servers'])
 * @param $cmd - command for run
 * @param bool $fork - true - run in new thread (not receive results), false - run in current thread
 * @param $stdin_data - optional data direct to stdin
 *
 * @return array - return result array
 */
function run_remote_cmd(array $server, $cmd, $fork = false, $stdin_data = '')
{
    $cmd = str_replace('$', '\$', $cmd);

    msg_log(LOG_NOTICE, 'run command on remote server "' . $server['hostname'] . '": ' . $cmd);

    // New children process
    $ssh = 'ssh ' . $server['login'] .
            '@' . $server['addr'] .
            ' -p' . $server['port'] . ' ';

    $rc = run_cmd($ssh . '"' . $cmd . '" 2>&1 > /dev/null');

    if ($fork == true)
        exit;

    return $rc;
}

/**
 * Get cleaned content form dot file
 * @param $dot_file - file name
 * @return string - content
 */
function get_dot_file_content($dot_file)
{
    @$content = file_get_contents($dot_file);
    if ($content == false)
        throw new Exception("can't open file: " . $dot_file);

    // strip comments
    $content = preg_replace('/^#.*/', '', $content);

    return trim($content);
}

/**
 * get cleaned strings from file
 * @param $file - file name
 * @return array of strings
 */
function get_strings_from_file($file)
{
    $list = array();
    $content = get_dot_file_content($file);
    $rows = explode("\n", $content);
    if (!$rows)
        return false;

    foreach ($rows as $row)
    {
        $clean_row = trim($row);
        if (!$clean_row)
            continue;

        $list[] = $clean_row;
    }

    return $list;
}

/**
 * Split string on words
 * @param $str - string
 * @return array of words
 */
function split_string($str)
{
    $cleaned_words = array();
    $words = split("[ \t,]", $str);
    if (!$words)
        return false;

    foreach ($words as $word)
        $cleaned_words[] = trim($word);

    return $cleaned_words;
}


/**
 * Detect current ci server and return his config
 * @return - ci server config or false
 */
function get_current_ci_server()
{
    global $_CONFIG;

    $rc = run_cmd('hostname', false);
    $hostname = trim($rc['log']);

    foreach ($_CONFIG['ci_servers'] as $ci_server)
        if ($ci_server['hostname'] == $hostname)
            return $ci_server;

    msg_log(LOG_WARNING, "Server '" . $hostname . "' was not found in the ci servers list.");
    return false;
}

/**
 * match branch name with branch mask
 * @param $branch - branch name
 * @param $branch_mask - $branch mask
 * @return bool - true if successfully matched
 */
function match_branch_with_mask($branch, $branch_mask)
{
    $branch_mask = str_replace('refs/', '', $branch_mask);
    $branch = str_replace('refs/', '', $branch);
    return fnmatch($branch_mask, $branch);
}


/**
 * get list of children PID
 * @param $parent_pid
 * @return array of children PID or false
 */
function get_child_pids($parent_pid)
{
    $ret = run_cmd("ps -ax --format '%P %p'");
    $rows = explode("\n", $ret['log']);
    if (!$rows)
        throw new Exception("incorrect output from command: ps -ax --format '%P %p'");

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
    msg_log(LOG_NOTICE, "killed PID: " . $kill_pid);
}

function create_dir($dir)
{
    $rc = mkdir($dir);
    if ($rc === false)
        throw new Exception("can't create dir: " . $dir);

    msg_log(LOG_NOTICE, "created directory: " . $dir);
}

function delete_dir($dir)
{
    $rc = run_cmd('rm -rf ' . $dir);
    if ($rc['rc'])
        throw new Exception("can't remove dir: " . $dir);

    msg_log(LOG_NOTICE, "deleted directory: " . $dir);
}

function create_file($file_name, $content = '')
{
    $rc = file_put_contents($file_name, $content);
    if ($rc === false)
        throw new Exception("can't create file: " . $file_name);

    msg_log(LOG_NOTICE, "created file: " . $file_name);
}

function add_to_file($file_name, $content = '')
{
    $fd = fopen($file_name, "a+");
    if ($fd === false)
        throw new Exception("can't open file: " . $file_name);

    $rc = fwrite($fd, $content);
    if ($rc === false)
        throw new Exception("can't write to file: " . $file_name);

    $rc = fclose($fd);
    if ($rc === false)
        throw new Exception("can't close file: " . $file_name);

    msg_log(LOG_NOTICE, "append to file: " . $file_name);
}

function delete_file($file_name)
{
    $rc = unlink($file_name);
    if ($rc === false)
        throw new Exception("can't remove file: " . $file_name);

    msg_log(LOG_NOTICE, "deleted file: " . $file_name);
}

function strip_duplicate_slashes($str)
{
    return preg_replace('/\/+/', '/', $str);
}

/**
 * Return current http url path prefix to list projects
 * @return string
 */
function get_http_url_projects()
{
    global $this_server;
    return 'http://' . $this_server['addr'] .
        ':80/';
}


/**
 * Run command on CI server
 * @param $ci_server - config CI server
 * @param $cmd - command
 * @param $fork - true - run in new thread (not receive results), false - run in current thread
 * @param $stdin_data - optional data direct to stdin
 * @return return result array
 */
function ci_run_cmd($ci_server, $cmd, $fork = false, $stdin_data = '')
{
    global $this_server;

    if ($ci_server['hostname'] == $this_server['hostname'])
        $rc = run_cmd($cmd, $fork, $stdin_data);
    else
        $rc = run_remote_cmd($ci_server, $cmd, $fork, $stdin_data);

    return $rc;
}

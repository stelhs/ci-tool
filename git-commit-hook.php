#!/usr/bin/php
<?php

$Ci_dir = '/opt/ci-tool/';
require_once($Ci_dir . 'config.php');

require_once($_CONFIG['ci_dir'] . 'lib.php');
require_once($_CONFIG['ci_dir'] . 'tpl.php');
require_once($_CONFIG['ci_dir'] . 'List_projects.php');
require_once($_CONFIG['ci_dir'] . 'CiDateTime.php');

$utility_name = 'git-commit-hook';
$this_server = array();




/**
 * Formated help output
 * @param $cmd - full command (text string)
 * @param $description - help description about command
 * @param array $sub_commands - list of sub commands
 */
function print_help_commands($cmd, $description, $sub_commands = array())
{
    echo "Usage: git-commit-hook " . $cmd . ' ' . ($sub_commands ? '<command>' : '') . "\n";
    echo $description . "\n";
    if (!$sub_commands)
        return;

    foreach ($sub_commands as $command => $description)
    {
        echo "\t" . $command . " - " . $description . "\n";
    }
}

function error_exception($exception)
{
    msg_log(LOG_ERR, $exception->getMessage());
    exit;
}


function match_branch_with_mask($branch, $branch_mask)
{
    return fnmatch('refs/' . $branch_mask, $branch);
}


/**
 * Run command on CI server
 * @param $ci_server - config CI server
 * @param $cmd - command
 * @param $fork - true - run in new thread (not receive results), false - run in current thread
 * @return return result array
 */
function ci_run_cmd($ci_server, $cmd, $fork = false)
{
    global $this_server;

    if ($ci_server['hostname'] == $this_server['hostname'])
        $rc = run_cmd($cmd, $fork);
    else
        $rc = run_remote_cmd($ci_server, $cmd, $fork);

    return $rc;
}

function get_ci_free_build_slots($ci_server)
{
    $rc = ci_run_cmd($ci_server, 'ci get free_build_slots');

    if ($rc['rc'])
        throw new Exception('"ci get free_build_slots" - return error');

    $build_slots = (int)$rc['log'];
    msg_log(LOG_NOTICE, 'detect ' . $build_slots . ' free build slots on server: ' . $ci_server['hostname']);
    return $build_slots;
}


/**
 * Get free ci server
 * @return ci_server settings array
 */
function get_appropriate_ci_server()
{
    global $_CONFIG;

    // get list of load overage ci servers
    $ci_servers = array();
    foreach ($_CONFIG['ci_servers'] as $ci_server)
    {
        $build_slots = get_ci_free_build_slots($ci_server);
        if ($build_slots === false)
            continue;

        if (!isset($ci_servers[$build_slots]))
            $ci_servers[$build_slots] = $ci_server;
    }

    if (!$ci_servers)
        throw new Exception('CI servers not found');

    krsort($ci_servers);
    foreach ($ci_servers as $first_ci_server)break;

    return $first_ci_server;
}


function main()
{
    global $argv, $_CONFIG, $this_server;
    $rc = NULL;

    set_exception_handler('error_exception');

    $this_server = get_current_ci_server();
    if (!$this_server)
        throw new Exception("Can't detect current server");

    $git_repository = isset($argv[1]) ? $argv[1] : NULL;
    $git_branch = isset($argv[2]) ? $argv[2] : NULL;
    $git_commit = isset($argv[3]) ? $argv[3] : NULL;
    $git_base_commit = isset($argv[4]) ? $argv[4] : NULL;

    // Detect print help mode
    $print_help = false;
    foreach ($argv as $arg)
        if ($arg == '--help' || $arg == '-h')
            $print_help = true;

    if (!$git_repository)
    {
        msg_log(LOG_ERR, 'incorrect argument 1');
        $print_help = true;
    }

    if (!$git_branch)
    {
        msg_log(LOG_ERR, 'incorrect argument 2');
        $print_help = true;
    }

    if (!$git_commit)
    {
        msg_log(LOG_ERR, 'incorrect argument 3');
        $print_help = true;
    }

    if ($print_help)
    {
        print_help_commands('[repo name] [branch name] [commit] <base_commit>',
            'git commit hook receiver, run by GIT');
        return 1;
    }

    // update CI sources
    if ($git_repository == $_CONFIG['ci_repo'])
    {
        msg_log(LOG_NOTICE, 'updating CI-tool sources');
        foreach ($_CONFIG['ci_servers'] as $ci_server)
            run_remote_cmd($ci_server, 'cd ' . $_CONFIG['ci_dir'] . ';' .
                'git pull origin master');

        msg_log(LOG_NOTICE, 'CI-tool sources update successfully');
        return 0;
    }

    // update projects configurations
    if ($git_repository == $_CONFIG['ci_projects_repo'])
    {
        msg_log(LOG_NOTICE, 'updating projects configurations');
        foreach ($_CONFIG['ci_servers'] as $ci_server)
            run_remote_cmd($ci_server, 'cd ' . $_CONFIG['project_dir'] . ';' .
                'git pull origin master');

        msg_log(LOG_NOTICE, 'projects configurations update successfully');
        return 0;
    }

    // create list all projects
    $projects = new List_projects($_CONFIG['project_dir']);

    // find targets for executable
    $execute_targets = array();
    foreach ($projects->get_list() as $project)
    {
        if (!$project->get_targets_list())
            continue;

        foreach ($project->get_targets_list() as $target)
        {
            $list_branches = $target->get_repo_branches($git_repository);
            if (!$list_branches)
                continue;

            foreach ($list_branches as $branch)
            {
                if (!match_branch_with_mask($git_branch, $branch))
                    continue;

                $execute_targets[] = $target;
            }
        }
    }


    if (!$execute_targets)
    {
        msg_log(LOG_WARNING, 'not found build targets for ' . $git_repository . ' ' . $git_branch);
        return false;
    }

    // create command and run his on CI servers
    foreach ($execute_targets as $target)
    {
        msg_log(LOG_NOTICE, 'found target: ' . $target->get_info());

        $ci_server = get_appropriate_ci_server();
        msg_log(LOG_NOTICE, 'server ' . $ci_server['hostname'] . ' was selected for the build');

        $rc = ci_run_cmd($ci_server,
            'cd ' . $target->get_dir() . ';' .
            'ci create session git');

        $session_name = $rc['log'];

        msg_log(LOG_NOTICE, 'run build on session: ' . $ci_server['hostname'] .
            '@' . $target->get_info() . '/' . $session_name);

        // run build
        ci_run_cmd($ci_server,
            'cd ' . $target->get_dir() . '/' . $session_name . ';' .
            'ci all ' . $git_repository . ' ' . $git_branch . ' ' . $git_commit . ' ' . $git_base_commit, true);

        echo "Run target " . $target->get_info() .
            ", create session: " . $ci_server['addr'] . ":" . $target->get_dir() . '/' . $session_name . "\n";
    }

    return 0;
}

return main();

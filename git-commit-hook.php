#!/usr/bin/php
<?php

$Ci_dir = '/opt/ci-tool/';
require_once($Ci_dir . 'config.php');

require_once($_CONFIG['ci_dir'] . 'lib.php');
require_once($_CONFIG['ci_dir'] . 'List_projects.php');
require_once($_CONFIG['ci_dir'] . 'CiDateTime.php');

$this_server = get_current_ci_server();




function print_help()
{
    // TODO:
    // Нужно нарисовать красивый хэлп
}


function match_branch_with_mask($branch, $branch_mask)
{
    /*preg_match('/' . $branch_mask . '/', $branch, $matched);
    return $matched ? true : false;**/

    return fnmatch($branch_mask, $branch);
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
        $rc = run_cmd($cmd);
    else
        $rc = run_remote_cmd($ci_server, $cmd, $fork);

    return $rc;
}

function get_ci_free_build_slots($ci_server)
{
    $rc = ci_run_cmd($ci_server, 'ci get free_build_slots');

    // if server not responce
    if ($rc['rc'])
        return false;

    $build_slots = (int)$rc['log'];
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
    global $argv, $_CONFIG;
    $rc = NULL;

    $git_repository = isset($argv[1]) ? $argv[1] : NULL;
    $git_branch = isset($argv[2]) ? $argv[2] : NULL;
    $git_commit = isset($argv[3]) ? $argv[3] : NULL;

    if (!$git_repository)
    {
        print_error('incorrect argument 1');
        print_help();
        return 1;
    }

    if (!$git_branch)
    {
        print_error('incorrect argument 2');
        print_help();
        return 1;
    }

    if (!$git_commit)
    {
        print_error('incorrect argument 3');
        print_help();
        return 1;
    }

    // update CI sources
    if ($git_repository == $_CONFIG['ci_repo'])
    {
        foreach ($_CONFIG['ci_servers'] as $ci_server)
            run_remote_cmd($ci_server, 'cd ' . $_CONFIG['ci_dir'] . ';' .
                'git pull origin master');

        return 0;
    }

    // update projects configurations
    if ($git_repository == $_CONFIG['ci_projects_repo'])
    {
        foreach ($_CONFIG['ci_servers'] as $ci_server)
            run_remote_cmd($ci_server, 'cd ' . $_CONFIG['project_dir'] . ';' .
                'git pull origin master');

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

    dump($execute_targets);

    if (!$execute_targets)
        return false;

    // create command and run his on CI servers
    foreach ($execute_targets as $target)
    {
        $ci_server = get_appropriate_ci_server();
        $rc = ci_run_cmd($ci_server,
            'cd ' . $target->get_dir() . ';' .
            'ci create session git');

        // TODO: return session name
        $session_name = $rc['log'];

        // run build
        ci_run_cmd($ci_server,
            'cd ' . $target->get_dir() . '/' . $session_name . ';' .
            'ci all ' . $git_repository . ' ' . $git_branch . ' ' . $git_commit, true);

        echo "Run target " . $target->get_name() . ", create session: " . $ci_server['hostname'] . "@" . $target->get_dir() . '/' . $session_name . "\n";
    }

    return 0;
}

return main();

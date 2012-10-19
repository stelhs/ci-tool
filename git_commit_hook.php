#!/usr/bin/php
<?php

$Ci_dir = '/home/stelhs/projects/ci/ci/';
require_once($Ci_dir . 'config.php');

require_once($_CONFIG['ci_dir'] . 'lib.php');
require_once($_CONFIG['ci_dir'] . 'List_projects.php');
require_once($_CONFIG['ci_dir'] . 'CiDateTime.php');


function print_help()
{
    // TODO:
    // Нужно нарисовать красивый хэлп
}


function match_branch_with_mask($branch, $branch_mask)
{
    preg_match('/' . $branch_mask . '/', $branch, $matched);
    return $matched ? true : false;
}


/**
 * Get free ci server
 * @return ci_server settings array
 */
function get_free_ci_server()
{
    global $_CONFIG;
    // get list of load overage ci servers
    $ci_servers = array();
    foreach ($_CONFIG['ci_servers'] as $ci_server)
    {
        $build_slots = (int)run_remote_cmd($ci_server, 'ci get free_build_slots');
        $ci_servers[$build_slots] = $ci_server;
    }
    krsort($ci_servers);

    foreach ($ci_servers as $first_ci_server) break;
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
            if ($target->get_repo_name() != $git_repository)
                continue;

            $list_branches = $target->get_list_branches();
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
        print_error('no suitable targets to execute');
        return false;
    }

    // create command for run on CI servers
    foreach ($execute_targets as $target)
    {
        $cmd = 'cd ' . $target->get_dir() . '; ' .
            'cd $(ci create session git); ' .
            'ci checkout ' . $git_commit . '; ' .
            'ci build; ci test; ci report '
            . $git_repository . ' ' . $git_branch . ' ' . $git_commit;

        $ci_server = get_free_ci_server();
        run_remote_cmd($ci_server, $cmd, true);
    }

    return 0;
}

return main();

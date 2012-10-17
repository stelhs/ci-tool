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
                'git pull');

        return;
    }


    //
    //

    // find lettle loaded CI server
    $min_load_average = 100; //100%
    $little_loaded_ci_server = array();
    foreach ($_CONFIG['ci_servers'] as $ci_server)
    {
        $load_average = (int)run_remote_cmd($ci_server, 'ci get load_average');
        if (!$load_average)
            continue;

        if ($load_average < $min_load_average)
        {
            $min_load_average = $load_average;
            $little_loaded_ci_server = $ci_server;
        }
    }

    if (!$little_loaded_ci_server)
    {
        print_error('No found free CI server');
        return;
    }

    // create list all projects
    $projects = new List_projects($_CONFIG['project_dir']);

    // find targets for executable
    $execute_targets = array();
    foreach ($projects->get_list() as $project)
    {
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

    // create command for run on CI servers
    $cmd = '( ';
    foreach ($execute_targets as $target)
        $cmd .= 'cd ' . $target->get_dir() . ' && ' .
            'session=$(ci create session) && ' .
            'cd $session && ' .
            'ci checkout ' . $git_commit . '&& ' .
            'ci build && ci test; ci report '
            . $git_repository . ' ' . $git_branch . ' ' . $git_commit;
    $cmd .= ' )';

    run_remote_cmd($little_loaded_ci_server, $cmd);

}

main();

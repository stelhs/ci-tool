#!/usr/bin/php
<?php

$Ci_dir = '/home/stelhs/projects/ci/ci/';
require_once($Ci_dir . 'config.php');

require_once($_CONFIG['ci_dir'] . 'lib.php');
require_once($_CONFIG['ci_dir'] . 'xml.php');
require_once($_CONFIG['ci_dir'] . 'List_projects.php');
require_once($_CONFIG['ci_dir'] . 'CiDateTime.php');



function print_help()
{
    // TODO:
    // Нужно нарисовать красивый хэлп
}

function get_load_overage(List_projects $projects)
{
    // - calculate count sessions in running mode
    $build_processes = 0;
    foreach ($projects->get_list() as $project)
        foreach ($project->get_targets_list() as $target)
            foreach ($target->get_list_sessions() as $session)
                if ($session->get_state() == 'running')
                    $build_processes++;

    $max_build_processes = (int)get_dot_file_content('.max_build_processes');
    return $max_build_processes - $build_processes;
}


function main()
{
    global $argv, $_CONFIG;
    $rc = NULL;

    // create list all projects
    $projects = new List_projects($_CONFIG['project_dir']);

    // Check operation object
    $obj_type = '';

    if (file_exists('.projects_list'))
        $obj_type = 'projects_list';

    if (file_exists('.project_desc'))
        $obj_type = 'project';

    if (file_exists('.target_desc'))
        $obj_type = 'target';

    if (file_exists('.session_desc'))
        $obj_type = 'session';

    // analysis general operation
    $op = isset($argv[1]) ? $argv[1] : NULL;
    switch ($op)
    {
        case 'test':
            {
                $ret = run_cmd('cp a hh');
                dump($ret);
                exit;

                $project = $projects->find_project('Promsvyaz');
                $target = $project->find_target('full_firmware');
                $session = $target->add_new_session();
                dump($session->get_name());
                $session->checkout_src();
            }

        case 'get':
            {
                $param = isset($argv[2]) ? $argv[2] : NULL;
                switch ($param)
                {
                    case 'load_average':
                        echo get_load_overage($projects) . "\n";
                        break;

                    default:
                        print_error('No parameter');
                        print_help();
                }
            }
            break;
    }

    if (!$obj_type)
    {
        print_error('incorrect current directory');
        return;
    }

    // Parse path and detect $project_name and $target_name and $session_name
    $target_name = '';
    $project_name = '';
    $dirs = explode('/', getcwd());
    $dirs = array_reverse($dirs);
    switch ($obj_type)
    {
        case 'project_list':
            break;

        case 'project':
            // Find project name
            $project_name = $dirs[0];
            break;

        case 'target':
            // Find target name and project name
            $target_name = $dirs[0];
            $project_name = $dirs[1];
            break;

        case 'session':
            // Find session name target name and project name
            $session_name = $dirs[0];
            $target_name = $dirs[1];
            $project_name = $dirs[2];
            break;
    }

    // get project object
    if ($project_name)
    {
        $project = $projects->find_project($project_name);
        if (!$project)
        {
            print_error('project not found');
            return;
        }
    }

    // get target object
    if ($target_name)
    {
        $target = $project->find_target($target_name);
        if (!$target)
        {
            print_error('target not found');
            return;
        }
    }

    // get session object
    if ($session_name)
    {
        $session = $target->find_session($session_name);
        if (!$session)
        {
            print_error('session not found');
            return;
        }
    }

    // analysis private operation
    switch ($op)
    {
        case 'create':
            {
                $type = isset($argv[2]) ? $argv[2] : NULL;
                $param1 = isset($argv[3]) ? $argv[3] : NULL;

                if (!$type)
                {
                    print_error('2 argument is empty');
                    break;
                }

                if (!$param1)
                {
                    print_error('3 argument is empty');
                    break;
                }

                switch ($type)
                {
                    case 'project':
                        if ($obj_type != 'projects_list')
                        {
                            print_error('This operation permited only from projects list dir');
                            break;
                        }

                        $rc = $projects->add_new_project($param1);
                        break;

                    case 'target':
                        if ($obj_type != 'project')
                        {
                            print_error('This operation permited only from project dir');
                            break;
                        }

                        $rc = $project->add_new_target($param1);
                        break;

                    case 'session':
                        if ($obj_type != 'target')
                        {
                            print_error('This operation permited only from project dir');
                            break;
                        }

                        $session = $target->add_new_session($param1);
                        echo $session->get_name();
                        break;

                    default:
                        print_error('No type of create');
                        print_help();
                }
            }
            break;

        // TODO: checkout, build, test, report must be work only from session dir
        case 'checkout':
            if ($obj_type != 'session')
            {
                print_error('This operation permited only from session dir');
                break;
            }

            $commit = isset($argv[2]) ? $argv[2] : NULL;
            if (!$commit)
            {
                print_error('2 argument is empty');
                break;
            }

            $rc = $session->checkout_src($commit);
            break;

        case 'build':
            if ($obj_type != 'session')
            {
                print_error('This operation permited only from session dir');
                break;
            }

            $rc = $session->build_src();
            break;
            
        case 'test':
            if ($obj_type != 'session')
            {
                print_error('This operation permited only from session dir');
                break;
            }

            $rc = $session->test_src();
            break;

        case 'report':
            if ($obj_type != 'session')
            {
                print_error('This operation permited only from session dir');
                break;
            }

            $rc = $session->make_report();
            break;

        default:
            print_error('No operation');
            print_help();
    }
    
    return $rc;
}

main();

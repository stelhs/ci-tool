#!/usr/bin/php
<?php

$Ci_dir = '/opt/ci-tool/';
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


function get_free_build_slots(List_projects $projects)
{
    // calculate count sessions in running mode
    $build_processes = 0;
    foreach ($projects->get_list() as $project)
        if ($project->get_targets_list())
            foreach ($project->get_targets_list() as $target)
                if ($target->get_list_sessions())
                    foreach ($target->get_list_sessions() as $session)
                        if ($session)
                            if ($session->get_state() == 'running')
                                $build_processes++;

    $max_build_processes = (int)get_dot_file_content($projects->get_dir() . '/.max_build_processes');
    $free_build_slots = $max_build_processes - $build_processes;
    if ($free_build_slots < 0)
        $free_build_slots = 0;

    return $free_build_slots;
}


function main()
{
    global $argv, $_CONFIG;
    $rc = 0;

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
        case 'get':
            {
                $param = isset($argv[2]) ? $argv[2] : NULL;
                switch ($param)
                {
                    case 'free_build_slots':
                        echo get_free_build_slots($projects) . "\n";
                        return 0;

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
        return 1;
    }

    // Parse path and detect $project_name and $target_name and $session_name
    $target_name = '';
    $project_name = '';
    $session_name = '';
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
            return 1;
        }
    }

    // get target object
    if ($target_name)
    {
        $target = $project->find_target($target_name);
        if (!$target)
        {
            print_error('target not found');
            return 1;
        }
    }

    // get session object
    if ($session_name)
    {
        $session = $target->find_session($session_name);
        if (!$session)
        {
            print_error('session not found');
            return 1;
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
                    return 1;
                }

                if (!$param1)
                {
                    print_error('3 argument is empty');
                    return 1;
                }

                switch ($type)
                {
                    case 'project':
                        if ($obj_type != 'projects_list')
                        {
                            print_error('This operation permited only from projects list dir');
                            return 1;
                        }

                        $rc = $projects->add_new_project($param1);
                        break;

                    case 'target':
                        if ($obj_type != 'project')
                        {
                            print_error('This operation permited only from project dir');
                            return 1;
                        }

                        $rc = $project->add_new_target($param1);
                        break;

                    case 'session':
                        if ($obj_type != 'target')
                        {
                            print_error('This operation permited only from project dir');
                            return 1;
                        }

                        $session = $target->add_new_session($param1);
                        echo $session->get_name() . "\n";
                        return 0;

                    default:
                        print_error('No type of create');
                        print_help();
                }
            }
            break;

        case 'checkout':
            if ($obj_type != 'session')
            {
                print_error('This operation permited only from session dir');
                return 1;
            }

            $commit = isset($argv[2]) ? $argv[2] : NULL;
            if (!$commit)
            {
                print_error('2 argument is empty');
                return 1;
            }

            $rc = $session->checkout_src($commit);
            break;

        case 'build':
            if ($obj_type != 'session')
            {
                print_error('This operation permited only from session dir');
                return 1;
            }

            $rc = $session->build_src();
            break;
            
        case 'test':
            if ($obj_type != 'session')
            {
                print_error('This operation permited only from session dir');
                return 1;
            }

            $rc = $session->test_src();
            break;

        case 'report':
            if ($obj_type != 'session')
            {
                print_error('This operation permited only from session dir');
                return 1;
            }

            $rc = $session->make_report();
            break;

        default:
            print_error('No operation');
            print_help();
            return 1;
    }
    
    return $rc;
}

return main();

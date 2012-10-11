#!/usr/bin/php
<?php

$Ci_dir = '/home/stelhs/projects/ci/ci/';
require_once($Ci_dir . 'config.php');

require_once($_CONFIG['ci_dir'] . 'lib.php');
require_once($_CONFIG['ci_dir'] . 'List_projects.php');
require_once($_CONFIG['ci_dir'] . 'CiDateTime.php');

/*
 * TODO:
 * Нужно продумать формат команд, для добавления проектов и таргетов
 * Продумать разпаралеливание
 */



function print_help()
{
    // TODO:
    // Нужно нарисовать красивый хэлп
}

function get_load_overage()
{
    // - View count sesiions in running mode
    // - cat .max_build_processes

    // return .max_build_processes - View count sesiions


    return rand(1, 100);
}


function main()
{
    global $argv, $_CONFIG;
    $rc = NULL;

    // Check operation object
    $obj_type = '';

    if (file_exists('.projects_list'))
        $obj_type = 'projects_list';

    if (file_exists('.project_desc'))
        $obj_type = 'project';

    if (file_exists('.target_desc'))
        $obj_type = 'target';

/*    if (!$obj_type)
    {
        print_error('run from project or target dir');
        return;
    }*/

    // Parse path and detect $project_name and $target_name
    $target_name = '';
    $project_name = '';
    switch ($obj_type)
    {
        case 'project_list':
            break;

        case 'project':
            // Find project name and project name
            $dirs = explode('/', getcwd());
            $dirs = array_reverse($dirs);
            $project_name = $dirs[0];
            break;

        case 'target':
            // Find target name and project name
            $dirs = explode('/', getcwd());
            $dirs = array_reverse($dirs);
            $target_name = $dirs[0];
            $project_name = $dirs[1];
            break;
    }

    // create list all projects
    $projects = new List_projects($_CONFIG['project_dir']);

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

    // analysis operation
    $op = isset($argv[1]) ? $argv[1] : NULL;
    switch ($op)
    {
        case 'test':
            $sessions = $target->get_list_sessions();
            $session = $sessions['build_session_0_10102012_1051'];
            dump($session->get_state());
            break;

        case 'get':
            {
                $param = isset($argv[2]) ? $argv[2] : NULL;
                switch ($param)
                {
                    case 'load_average':
                        echo get_load_overage() . "\n";
                        break;

                    default:
                        print_error('No parameter');
                        print_help();
                }
            }
            break;

        case 'create':
            {
            $type = isset($argv[2]) ? $argv[2] : NULL;
            $name = isset($argv[3]) ? $argv[3] : NULL;

            if (!$type)
            {
                print_error('2 argument is empty');
                break;
            }

            if (!$name)
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

                    $projects->add_new_project($name);
                    break;

                case 'target':
                    if ($obj_type != 'project')
                    {
                        print_error('This operation permited only from project dir');
                        break;
                    }

                    $project->add_new_target($name);
                    break;

                default:
                    print_error('No type of create');
                    print_help();
            }
            }
            break;

        case 'checkout':
            if ($obj_type != 'target')
            {
                print_error('This operation permited only from target dir');
                break;
            }

            $session = $target->add_new_session();
            $rc = $session->checkout_src();
            break;
            
        case 'build':
            if ($obj_type != 'target')
            {
                print_error('This operation permited only from target dir');
                break;
            }

            $session = $target->add_new_session();
            $rc = $session->build_src();
            break;
            
        case 'test':
            if ($obj_type != 'target')
            {
                print_error('This operation permited only from target dir');
                break;
            }

            $session = $target->add_new_session();
            $rc = $session->test_src();
            break;

        case 'report':
            // TODO:
            // $1 - repo
            // $2 - branch
            // $3 - commit
            // *$4 - job description

            // XML

            //check $status:
            //print:
            //  checkout ok/failed
            //  build ok/failed
            //  test ok/failed

            //print:
            //  url to build results
            //
            //
            //
            //
            //
            //

            break;

        default:
            print_error('No operation');
            print_help();
    }
    
    return $rc;
}

main();

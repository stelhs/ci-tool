#!/usr/bin/php
<?php

$Ci_dir = '/opt/ci-tool/';
require_once($Ci_dir . 'config.php');

require_once($_CONFIG['ci_dir'] . 'lib.php');
require_once($_CONFIG['ci_dir'] . 'xml.php');
require_once($_CONFIG['ci_dir'] . 'List_projects.php');
require_once($_CONFIG['ci_dir'] . 'CiDateTime.php');

$this_server = get_current_ci_server();


/**
 * ci get bla (--help)
 * Usage: ci get bla
 * CI tool purpose ...
 *     get   various CI parameters
 *     create
 *     delete
 */

/**
 * ci get --help
 *
 *
 */


function print_help_command($description)
{

}


function get_free_build_slots(List_projects $projects)
{
    global $this_server;

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

    if (!$this_server)
        throw new Exception("Can't detect current server");

    $free_build_slots = $this_server['max_build_slots'] - $build_processes;
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

    if (file_exists('.project_desc'))
        $obj_type = 'project';

    if (file_exists('.target_desc'))
        $obj_type = 'target';

    if (file_exists('.session_desc'))
        $obj_type = 'session';

    /*    if (!$obj_type)
    {
        print_error('incorrect current directory');
        return 1;
    }*/

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

    // analysis operation
    $op = isset($argv[1]) ? $argv[1] : NULL;
    switch ($op)
    {
        case 'get':
            {
/*                if ($print_help)
                {

                    return 0;
                }*/

                $param = isset($argv[2]) ? $argv[2] : NULL;
                switch ($param)
                {
                    case 'free_build_slots':
                        echo get_free_build_slots($projects) . "\n";
                        return 0;

                    case 'session_status':
                        if ($obj_type != 'session')
                        {
                            print_error('This operation permited only from session dir');
                            return 1;
                        }

                        echo $session->get_state() . "\n";
                        return 0;

                    default:
                        print_error('No parameter');
                        print_help();
                }
            }
            break;

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

        case 'all':
            if ($obj_type != 'session')
            {
                print_error('This operation permited only from session dir');
                return 1;
            }

            $repo = isset($argv[2]) ? $argv[2] : NULL;
            $branch = isset($argv[3]) ? $argv[3] : NULL;
            $commit = isset($argv[4]) ? $argv[4] : NULL;

            if (!$repo)
            {
                print_error('"repo name" 2 argument is empty');
                return 1;
            }

            if (!$branch)
            {
                print_error('"branch name" 3 argument is empty');
                return 1;
            }

            if (!$commit)
            {
                print_error('"commit" 4 argument is empty');
                return 1;
            }

            $build_slots = get_free_build_slots($projects);
            while ($build_slots <= 0)
            {
                echo "wait free build slot\n";
                $session->set_status('pending');
                $build_slots = get_free_build_slots($projects);
                sleep(10);
            }

            $rc = $session->checkout_src($commit);
            if ($rc['rc'])
            {
                print_error('checkout fail');
                return 1;
            }

            $rc = $session->build_src();
            if ($rc['rc'])
            {
                print_error('build fail');
                return 1;
            }

            $rc = $session->test_src();
            if ($rc['rc'])
            {
                print_error('test fail');
                return 1;
            }

            $rc = $session->make_report();
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

        case 'pending':
            dump($argv);
            break;

        default:
            print_error('No operation');
            print_help();
            return 1;
    }
    
    return $rc;
}

return main();

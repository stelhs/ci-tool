<?php

require_once('Project.php');

/**
 * Created by Mikhail Kurachkin.
 * Date: 09.10.12
 * Time: 10:13
 * Info: project list container
 */
class List_projects
{
    private $projects = array();
    private $dir; // project directory

    function __construct($dir)
    {
        $this->dir = $dir;

        $list_dirs = get_dirs($dir);
        if (!$list_dirs)
            return;

        foreach ($list_dirs as $dir_name)
        {
            $project = new Project($this->dir . '/' . $dir_name, $dir_name);
            $this->add_project($project);
        }
    }

    /**
     * return projects directory
     * @return path
     */
    function get_dir()
    {
        return $this->dir;
    }

    /**
     * Get array with all projects
     * @return array projects
     */
    function get_list()
    {
        return $this->projects;
    }

    /**
     * add project to internal list projects
     * @param Project $project
     */
    private function add_project(Project $project)
    {
        $this->projects[$project->get_name()] = $project;
    }

    /**
     * Create new project and add to internal list projects
     * @param $project_name
     * @return bool
     */
    function add_new_project($project_name)
    {
        $project_dir = $this->dir . '/' . $project_name;
        if (is_dir($project_dir))
        {
            print_error('project already exist');
            return false;
        }
        mkdir($project_dir);
        file_put_contents($project_dir . '/.project_desc', $project_name);
        run_cmd('git add ' . $this->dir .
            ' && git commit -m "add new project ' . $project_name . '" && git push origin master');

        $project = new Project($project_dir, $project_name);
        $this->add_project($project);
    }

    /**
     * Find project object by project name
     * @param $project_name
     * @return project object of false
     */
    function find_project($project_name)
    {
        if (isset($this->projects[$project_name]))
            return $this->projects[$project_name];

        return false;
    }
}

<?php

require_once('Target.php');

/**
 * Created by Mikhail Kurachkin.
 * Date: 09.10.12
 * Time: 10:13
 * Info: project container
 */
class Project
{
    private $name;
    private $targets; // target in project
    private $dir; // project directory

    function __construct($project_dir, $project_name)
    {
        $this->name = $project_name;
        $this->dir = $project_dir;
        $this->targets = array();
        $this->scan_targets();
    }

    /**
     * Scan directory and add targets objects
     */
    private function scan_targets()
    {
        $list_dirs = get_dirs($this->dir);
        if (!$list_dirs)
            return;

        foreach ($list_dirs as $dir_name)
        {
            //TODO: added ckeck .description
            $target = new Target($this, $this->dir . '/' . $dir_name, $dir_name);
            $this->add_target($target);
        }
    }

    /**
     * Get project name
     * @return string
     */
    function get_name()
    {
        return $this->name;
    }

    /**
     * Get array with all targets
     * @return array
     */
    function get_targets_list()
    {
        return $this->targets;
    }

    /**
     * add target to internal list targets
     * @param Target $target
     */
    private function add_target(Target $target)
    {
        $this->targets[$target->get_name()] = $target;
    }

    /**
     * Create new target and add to internal list targets
     * @param $target_name
     * @return bool
     */
    function add_new_target($target_name)
    {
        global $_CONFIG;

        $target_dir = $this->dir . '/' . $target_name;
        if (is_dir($target_dir))
        {
            print_error('target already exist');
            return false;
        }

        mkdir($target_dir);
        system('cp ' . $_CONFIG['ci_dir'] . '/default_configs/target/.* ' . $target_dir);

        $target = new Target($this, $target_dir, $target_name);
        $this->add_target($target);
    }

    /**
     * Find target object by target name
     * @param $target_name
     * @return target object of false
     */
    function find_target($target_name)
    {
        if (isset($this->targets[$target_name]))
            return $this->targets[$target_name];

        return false;
    }
};

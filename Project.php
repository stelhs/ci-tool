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
     * Check for consistent target directory
     * and out warning messages.
     * @param $target_dir
     * @return bool true - if target directory is correct
     */
    private function check_target_dir($target_dir)
    {
        $unexist_files = array();

        $list_of_necessary_files = array('.target_desc',
                                         '.repos',
                                         '.recipe_checkout',
                                         '.recipe_build',
                                        );

        foreach ($list_of_necessary_files as $file)
            if(!file_exists($target_dir . '/' . $file))
                $unexist_files[] = $file;

        if (!$unexist_files)
            return true;

        $msg = 'incorrect target directory: ' . $target_dir . ". ";
        $msg .= 'Not enought next mandatory files: ';
        foreach ($unexist_files as $file)
            $msg .= $file . ' ';

        msg_log(LOG_WARNING, $msg);
        return false;
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
            if (!$this->check_target_dir($this->dir . '/' . $dir_name))
            {
                msg_log(LOG_WARNING, 'ignoring target dir:' . $this->dir . '/' . $dir_name);
                continue;
            }

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
    function add_new_target($target_name, $target_description = '')
    {
        global $_CONFIG;

        $target_dir = $this->dir . '/' . $target_name;
        if (is_dir($target_dir))
        {
            msg_log(LOG_ERR, 'can\'t created target: ' . $target_name . ', target already exist');
            return false;
        }

        create_dir($target_dir);
        // copy default configs
        run_cmd('find ' . $_CONFIG['ci_dir'] . '/default_configs/target/ ' .
        ' -name ".*" -type f -exec cp {} ' . $target_dir . ' \;');

        $target = new Target($this, $target_dir, $target_name);
        $this->add_target($target);

        create_file($target->get_dir() . '/.target_desc', $target_description);

        // commit added target
        $rc = run_cmd('cd ' . $target_dir . '; git add ' . $this->dir .
            ' && git commit -m "add new target ' . $target->get_info() . '" && git push origin master');
        if ($rc['rc'])
        {
            delete_dir($target_dir);
            msg_log(LOG_ERR, 'can\'t created target, can\'t commit new target:' . $target->get_info());
            return false;
        }

        msg_log(LOG_NOTICE, "added new target: " . $target->get_info());
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

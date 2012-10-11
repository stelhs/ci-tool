<?php
require_once('Session.php');

/**
 * Created by Mikhail Kurachkin.
 * Date: 09.10.12
 * Time: 10:13
 * Info: target container
 */
class Target
{
    private $project;
    private $name;
    private $sessions;
    private $dir; // target directory

    function __construct(Project $project, $target_dir, $target_name)
    {
        $this->project = $project;
        $this->dir = $target_dir;
        $this->name = $target_name;
        $this->scan_sessions();
    }

    /**
     * return target directory
     * @return path
     */
    function get_dir()
    {
        return $this->dir;
    }

    /**
     * return target name
     * @return string
     */
    function get_name()
    {
        return $this->name;
    }

    /**
     * Get array with all sessions
     * @return array sessions
     */
    function get_list_sessions()
    {
        return $this->sessions;
    }

    /**
     * Get name of repository for current target
     * @return repository name
     */
    function get_repo_name()
    {
        return file_get_contents($this->dir . '/.repo_name');
    }

    /**
     * Get list branches for current target
     * @return list of branches
     */
    function get_list_branches()
    {
        $list_branches = array();
        $content = file_get_contents($this->dir . '/.branches');
        $rows = explode("\n", $content);
        foreach ($rows as $row)
        {
            $clean_row = trim($row);
            if (!$clean_row)
                continue;

            $list_branches[] = $clean_row;
        }

        return $list_branches;
    }

    /**
     * Scan directory and add session objects
     */
    private function scan_sessions()
    {
        $list_dirs = get_dirs($this->dir);
        foreach ($list_dirs as $dir_name)
        {
            preg_match('/build_session_([0-9]+)_(.+)/s', $dir_name, $matches);
            if (!$matches)
                continue;

            $session_date = new CiDateTime();
            $session_date->from_string($matches[2]);

            $session = new Session($this, $this->dir . '/' . $dir_name, $session_date, $matches[1]);
            $this->add_session($session);
        }
    }

    /**
     * add session to internal list sessions
     * @param Session $session
     */
    private function add_session(Session $session)
    {
        $this->sessions[$session->get_name()] = $session;
    }

    /**
     * Create new session and add to internal list sessions
     * @return bool
     */
    function add_new_session()
    {
        $curr_date = new CiDateTime();

        $session_date = $curr_date->to_string();

        $index = 0;
        while (is_dir($this->dir . '/build_session_' . $index . '_' . $session_date))
            $index++;

        $dir_name = 'build_session_' . $index . '_' . $session_date;
        mkdir($this->dir . '/' . $dir_name);

        $session = new Session($this, $this->dir . '/' . $dir_name, $curr_date, $index);
        $this->add_session($session);
        return $session;
    }

    /**
     * Remove session by session object
     * @param $remove_session - session object
     */
    function remove_session($remove_session)
    {
        $remove_session_name = $remove_session->get_name();
        foreach ($this->sessions as $id => $session)
        {
            if ($remove_session_name == $session->get_name())
            {
                rmdir($this->dir . '/' . $remove_session_name);
                unset($this->sessions[$id]);
            }
        }
    }
}

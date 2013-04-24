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
        return strip_duplicate_slashes($this->dir);
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
     * Get info about target
     */
    function get_info()
    {
        $project = $this->get_project();
        return $project->get_name() . '/' . $this->get_name();
    }

    /**
     * Get target url
     * @return CiDateTime
     */
    function get_url()
    {
        $project = $this->get_project();
        return get_http_url_projects() . '/' . $project->get_name() . '/' . $this->get_name() . '/';
    }

    /**
     * Get email addresses
     */
    function get_email_list()
    {
        if (!file_exists($this->dir . '/.mail'))
            return false;

        return get_strings_from_file($this->dir . '/.mail');
    }

    /**
     * Get expiration sesisions time in days
     */
    function get_expiration_sessions_time()
    {
        if (!file_exists($this->dir . '/.expiration_time'))
            return false;

        return get_strings_from_file($this->dir . '/.expiration_time');
    }



    /**
     * Get project object
     * @return string
     */
    function get_project()
    {
        return $this->project;
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
     * Get branches linked with repository for current target
     * @return list of branches
     */
    function get_repo_branches($repo_name)
    {
        $rows = get_strings_from_file($this->dir . '/.repos');
        if (!$rows)
            return false;

        // featch list repos
        foreach ($rows as $row)
        {
            $words = split_string($row);
            if (!$words)
                continue;

            // if find needed repo
            if ($words[0] == $repo_name)
            {
                unset($words[0]);
                return $words;
            }
        }
    }

    /**
     * Scan directory and add session objects
     */
    private function scan_sessions()
    {
        $list_dirs = get_dirs($this->dir);
        foreach ($list_dirs as $dir_name)
        {
            preg_match('/build_session_(.+)_([0-9]+)/s', $dir_name, $matches);
            if (!$matches)
                continue;

            $session_date = new CiDateTime();
            $session_date->from_string($matches[1]);

            $session = new Session($this, $this->dir . '/' . $dir_name, $session_date, $matches[2]);
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
    function add_new_session($description = '')
    {
        $curr_date = new CiDateTime();
        $session_date = $curr_date->to_string();

        $index = 0;
        while (is_dir($this->dir . '/build_session_' . $session_date . '_' . $index))
            $index++;

        $dir_name = 'build_session_' . $session_date . '_' . $index;
        create_dir($this->dir . '/' . $dir_name);

        $session = new Session($this, $this->dir . '/' . $dir_name, $curr_date, $index);
        $this->add_session($session);
        $session->set_status('created');

        create_file($session->get_dir() . '/.session_desc', $description);

        msg_log(LOG_NOTICE, "added new session: " . $session->get_name());
        return $session;
    }

    /**
     * Find session object by session name
     * @param $session_name
     * @return session object of false
     */
    function find_session($session_name)
    {
        if (isset($this->sessions[$session_name]))
            return $this->sessions[$session_name];

        return false;
    }

    /**
     * Remove session by session object
     * @param $remove_session - session object
     */
    function remove_session($remove_session)
    {
        $remove_session_name = $remove_session->get_name();
        if (!$this->sessions)
            return false;

        foreach ($this->sessions as $id => $session)
        {
            if ($remove_session_name == $session->get_name())
            {
                delete_dir($session->get_dir());
                unset($this->sessions[$id]);
                msg_log(LOG_NOTICE, "removed session: " . $remove_session_name);
            }
        }

        return true;
    }
}

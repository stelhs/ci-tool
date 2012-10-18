<?php
$_CONFIG['ci_dir'] = '/home/stelhs/projects/ci/ci/';
$_CONFIG['project_dir'] = '/home/stelhs/projects/ci/projects/';
$_CONFIG['web'] = '/home/stelhs/projects/ci/web/';
$_CONFIG['ci_repo'] = 'ci-tool.git';
$_CONFIG['ci_projects_repo'] = 'ci-projects.git';
$_CONFIG['ci_servers'] = array(
                                array('host' => '192.168.10.244',
                                      'port' => 22,
                                      'login' => 'stelhs',
                                      'role' => 'web',
                                      'description' => 'xz',
                                ),

                                array('host' => 'localhost',
                                      'port' => 22,
                                      'login' => 'stelhs',
                                      'role' => 'build',
                                      'description' => 'xz',
                                ),
                              );


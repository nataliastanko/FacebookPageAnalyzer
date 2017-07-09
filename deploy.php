<?php
namespace Deployer;
require 'recipe/common.php';

// Configuration

/**
 * Deploy commands:
 *
 * dep deploy prod
 * dep rollback prod
 *
 * without option : VERBOSITY_NORMAL
 * -v : VERBOSITY_VERBOSE
 * -vv : VERBOSITY_VERY_VERBOSE
 * -vvv : VERBOSITY_DEBUG
 * -q : VERBOSITY_QUIET (in quiet mode, deployer will use your default answer for ask and askConfirmation function_exists(function_name))

 * getenv - get env variable on local machine
 */
set('ssh_type', 'native');
set('ssh_multiplexing', true);

server('production', 'anithaly.com', 8383)
    ->user(getenv('fb_page_analyzer_prod_server_user'))
    ->identityFile(
        getenv('fb_page_analyzer_prod_server_ssh_identity_file_pub'),
        getenv('fb_page_analyzer_prod_server_ssh_identity_file_priv'),
        getenv('fb_page_analyzer_prod_server_ssh_password')
    )
    ->stage('prod')
    ->set('branch', 'master')
    ->forwardAgent()
    ->set('deploy_path', '/var/www/FacebookPageAnalyzer/prod')
    // ->set('http_user', 'www-data')
    ;

set('repository', 'git@github.com:nataliastanko/FacebookPageAnalyzer.git');

set('keep_releases', 3);
set('shared_files', [
    '.env'
    ]
);

desc('Deploy your project');
task('deploy', [
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:shared',
    'deploy:writable',
    'deploy:vendors',
    'deploy:clear_paths',
    'deploy:symlink',
    'deploy:unlock',
    'cleanup',
    'success'
]);

after('deploy', 'success');

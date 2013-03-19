<?php

/**
 * @file A simple script to force re-generation of the lsyncd config file because of a change
 * in the original Lua template/app configuration.
 */

require 'config.php';
require 'utilities.php';
require 'vendor/autoload.php';

// As we use the longopts parameter of getopt() which was only introduced in
// PHP 5.3.0, lets make sure the environment is okay first.
if (version_compare(phpversion(), '5.3.0') < 0) {
  die('You need to have at least PHP 5.3.0 to run this script.');
}

reloadConfig($APP_CONF, $LSYNCD_CONF, $AWS_CONF);
echo "New configuration file generated at " . $APP_CONF['data_dir'] . "lsyncd.conf.lua\n";

$options = getopt('', array('restart'));
if (isset($options['restart'])) {
  echo "Restart Lsyncd\n";
  restartLsyncd($APP_CONF);
}
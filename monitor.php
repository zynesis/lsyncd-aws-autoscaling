#!/usr/bin/env php
<?php
/**
 * 1. Monitors auto scaled instances in a group
 * 2. Generates lsyncd.conf.lua.
 * 3. Restart existing lsyncd
 */
require 'config.php';
require 'vendor/autoload.php';


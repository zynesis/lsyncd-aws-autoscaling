<?php
/**
 * lsyncd-aws-autoscaling
 *
 * Lsyncd auto configuration that works with Amazon Web Services (AWS) Auto Scaling
 *
 * @author       U-Zyn Chua <uzyn@zynesis.com>
 * @copyright    Copyright © 2013 U-Zyn Chua & Zynesis Pte Ltd
 * @link         http://zynesis.com
 * @license      MIT License
 */
 
use Liip\ProcessManager\ProcessManager;

/**
 * Check if slaves fingerprint has changed
 *
 * @param array $slaves Array of slaves with EC2 instance ID as array key
 * @param string $fileLocation Location of file storing slaves configuration
 * @return boolean
 */
function hasSlavesChanged($slaves, $fileLocation) 
{
    $old = array();
    if (file_exists($fileLocation)) {
        $old = unserialize(file_get_contents($fileLocation));
        
        if (!is_writable($fileLocation)) {
            trigger_error($fileLocation . ' is not writable.', E_USER_ERROR);
        }
    }
    
    sort($old);
    sort($slaves);
    
    if ($old == $slaves) {
        return false;
    }

    file_put_contents($fileLocation, serialize($slaves));
    return true;
}


/**
 * Check if Lsyncd is still alive
 * If it is not, start it
 *
 * @param array $APP_CONF Application configuration
 * @return void
 */
function keepLsyncdAlive($APP_CONF)
{
    $processManager = new ProcessManager();
    $pidFile = $APP_CONF['data_dir'] . 'lsyncd.pid';
    
    echo "Checking if Lsyncd is still running.\n";
    
    if (file_exists($pidFile)) {
        $pid = file_get_contents($pidFile);
        
        if ($processManager->isProcessRunning($pid)) {
            echo "Lsyncd is still running fine.\n";
            return;
        }
    }

    echo "Lsyncd is not active.\n";
    echo "Starting Lsyncd.\n";
    startLsyncd($APP_CONF);
}

function restartLsyncd($APP_CONF)
{
    $processManager = new ProcessManager();
    $pidFile = $APP_CONF['data_dir'] . 'lsyncd.pid';
    
    if (file_exists($pidFile)) {
        $pid = file_get_contents($pidFile);
        
        if ($processManager->isProcessRunning($pid)) {
            echo "Stopping existing Lsyncd.\n";
            $processManager->killProcess($pid);
        }
    }
    
    echo "Starting Lsyncd.\n";
    startLsyncd($APP_CONF);
}

function startLsyncd($APP_CONF)
{
    $processManager = new ProcessManager();
    $pidFile = $APP_CONF['data_dir'] . 'lsyncd.pid';
    
    $command = $APP_CONF['path_to_lsyncd'] . ' ' . $APP_CONF['data_dir'] . 'lsyncd.conf.lua';
    if (isset($APP_CONF['dry_run']) && $APP_CONF['dry_run']) {
        $command = 'sleep 60';
    }
    
    $pid = $processManager->execProcess($command);
    file_put_contents($pidFile, $pid);
    echo "Lsyncd started. Pid: $pid.\n";

    return;
}
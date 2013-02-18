<?php

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
 */
function keepLsyndAlive($APP_CONF)
{
    $processManager = new ProcessManager();
    $pidFile = $APP_CONF['data_dir'] . 'lsyncd.pid';
    
    if (file_exists($pidFile)) {
        $pid = file_get_contents($pidFile);
        
        if ($processManager->isProcessRunning($pid)) {
            echo "Lsyncd is still running fine.\n";
            return;
        }
    }

    echo "Lsyncd is not active.\n";
    echo "Starting Lsyncd.\n";
    
    //$pid = $lock->execProcess($APP_CONF['path_to_lsyncd'] . ' ' . $APP_CONF['data_dir'] . 'lsyncd.conf.lua');
    $pid = $processManager->execProcess('sleep 60');
    file_put_contents($pidFile, $pid);
    
    echo "Lsyncd started. Pid: $pid.\n";

    return;
}
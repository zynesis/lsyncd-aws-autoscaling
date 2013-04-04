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
 * Check if the slaves fingerprint has changed.
 *
 * @param array $slaves Array of slaves with EC2 instance ID as array key
 * @param string $fileLocation Location of file storing slaves fingerprint
 * @return boolean
 */
function hasSlavesChanged($slaves, $fileLocation)
{
    $old = getSavedSlaves($fileLocation);
    sort($old);
    sort($slaves);

    if ($old == $slaves) {
        return false;
    }

    return true;
}

/**
 * Save the latest slaves fingerprint
 *
 * @param array $slaves Array of slaves with EC2 instance ID as array key
 * @param string $fileLocation Location of file storing slaves fingerprint
 * @return boolean
 */
function saveSlaves($slaves, $fileLocation)
{
    if (file_exists($fileLocation) && !is_writable($fileLocation)) {
        trigger_error($fileLocation . ' is not writable.', E_USER_ERROR);
    }

    return file_put_contents($fileLocation, serialize($slaves));
}

/**
 * Read and return last saved slaves fingerprint from $fileLocation
 *
 * @param string $fileLocation Location of file storing slaves fingerprint
 * @return array Array containing slave IDs
 */
function getSavedSlaves($fileLocation)
{
    $old = array();
    if (file_exists($fileLocation)) {
        $old = unserialize(file_get_contents($fileLocation));
    }

    return $old;
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

/**
 * Regenerates the lsyncd config file based on the template and app config.
 *
 * @param array $APP_CONF Associative array used for configuring the app
 * @param array $LSYNCD_CONF Associative array used for configuring the lsync daemon
 * @param array $AWS_CONF Associative array having AWS config.
 * @param mixed $slaveIDs A list of slave identifiers. Defaults to NULL.
 *
 * @throws Exception when instance information cannot be fetched from AWS.
 *
 * @todo Move this API method elsewhere. utilities is probably not the best place for
 * it considering its strong dependency on the application itself.
 */
function reloadConfig($APP_CONF, $LSYNCD_CONF, $AWS_CONF, $slaveIDs = NULL)
{
  $slaves = array();
  if (!isset($slaveIDs)) {
    $slaveIDs = getSlaves($APP_CONF['data_dir'] . 'slaves');
  }

  require_once 'vendor/autoload.php';

  // Initialize the AWS client and query for meta information about the
  // slave identifiers at hand.
  $aws = Aws\Common\Aws::factory(array(
    'key' => $AWS_CONF['key'],
    'secret' => $AWS_CONF['secret'],
    'region' => $AWS_CONF['region']
  ));
  $ec2Client = $aws->get('Ec2');
  $ec2Instances = $ec2Client->describeInstances(array('InstanceIds' => $slaveIDs));
  if (empty($ec2Instances)) {
    throw new Exception('Unable to obtain description of slave EC2 instances.');
  }
  foreach ($ec2Instances['Reservations'] as $reservation) {
    $instances = $reservation['Instances'];

    foreach ($instances as $instance) {
      $slaves[] = array(
        'instance_id' => $instance['InstanceId'],
        'private_ip_address' => $instance['PrivateIpAddress']
      );
    }
  }

  $mustache = new Mustache_Engine;
  $data = array(
    'app' => array(
      'generation_time' => date('r')
    ),
    'lsyncd' => $LSYNCD_CONF,
    'slaves' => $slaves
  );

  $lsyncdConf = $mustache->render(file_get_contents($APP_CONF['lsyncd_conf_template']), $data);
  file_put_contents($APP_CONF['data_dir'] . 'lsyncd.conf.lua', $lsyncdConf);
}
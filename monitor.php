#!/usr/bin/env php
<?php
/**
 * lsyncd-aws-autoscaling
 *
 * Lsyncd auto configuration that works with Amazon Web Services (AWS) Auto Scaling
 *
 * It does the following:
 * 1. Monitors auto scaled instances that are attached to a load balancer.
 * 2. Automatically configures Lsyncd to sync across all attached instances to a load balancer.
 * 3. Monitors Lsyncd and make sure Lsyncd is always up and running, while Lsyncd does the
 *    syncing of files from master to auto-scaled slaves.
 *
 * @author       U-Zyn Chua <uzyn@zynesis.com>
 * @copyright    Copyright © 2013 U-Zyn Chua & Zynesis Pte Ltd
 * @link         http://zynesis.com
 * @license      MIT License
 */

require 'config.php';
require 'utilities.php';
require 'vendor/autoload.php';

use Aws\Common\Aws;

$aws = Aws::factory(array(
    'key' => $AWS_CONF['key'],
    'secret' => $AWS_CONF['secret'],
    'region' => $AWS_CONF['region']
));

$elbClient = $aws->get('ElasticLoadBalancing');

$balancers = $elbClient->describeLoadBalancers(array(
    'LoadBalancerNames' => array($AWS_CONF['load_balancer_name'])
));

if (empty($balancers)) {
    trigger_error('Failed to obtain description of load balancer.', E_USER_ERROR);
}
if (empty($balancers['LoadBalancerDescriptions'][0]['Instances'])) {
    trigger_error('No EC2 instances found.', E_USER_ERROR);
}

$elbEc2instances = $balancers['LoadBalancerDescriptions'][0]['Instances'];

$slavesIDs = array();
foreach ($elbEc2instances as $instance) {
    $instanceID = $instance['InstanceId'];
    if ($instanceID != $AWS_CONF['master_ec2_instance_id']) {
        $slavesIDs[] = $instanceID;
    }
}

if (empty($slavesIDs)) {
    trigger_error('No slave instances found.', E_USER_ERROR);
}

if (!hasSlavesChanged($slavesIDs, $APP_CONF['data_dir'] . 'slaves')) {
    echo "No changes in slaves.\n";
    keepLsyncdAlive($APP_CONF);
    exit();
}

echo "There are changes in slaves.\n";

$toRun = null;
if (isset($APP_CONF['remote_script']['enabled']) && $APP_CONF['remote_script']['enabled']) {
    $scriptConfig = $APP_CONF['remote_script'];
    echo "Remote script execution is enabled.\n";

    if (!is_readable($scriptConfig['local_path'])) {
        trigger_error('Remote script is not present or readable at ' . $scriptConfig['local_path']);
    }

    $toRun = $slavesIDs;
    if ($APP_CONF['remote_script']['run_script_on_all_slaves'] === false) {
        $oldSlaves = getSavedSlaves($APP_CONF['data_dir'] . 'slaves');
        $toRun = array_diff($slavesIDs, $oldSlaves);
    }

}

saveSlaves($slavesIDs, $APP_CONF['data_dir'] . 'slaves');

$ec2Client = $aws->get('Ec2');

$ec2Instances = $ec2Client->describeInstances(array('InstanceIds' => $slavesIDs));

if (empty($ec2Instances)) {
    trigger_error('Unable to obtain description of slave EC2 instances.', E_USER_ERROR);
}

$slaves = array();

foreach ($ec2Instances['Reservations'] as $reservation) {
    $instances = $reservation['Instances'];

    foreach ($instances as $instance) {
        $slaves[] = array(
            'instance_id' => $instance['InstanceId'],
            'private_ip_address' => $instance['PrivateIpAddress']
        );
    }
}

/**
 * Run remote script on slaves
 */
if (!empty($toRun)) {
    foreach ($slaves as $slave) {
        if (in_array($slave['instance_id'], $toRun)) {
            echo "Executing remote script at instance $slave[instance_id] ($slave[private_ip_address]) ...\n";
            $command = 'cat ' . $APP_CONF['remote_script']['local_path'] . ' | ssh -i ' . $LSYNCD_CONF['ssh_private_key'] . ' ' . $LSYNCD_CONF['ssh_user'] . '@' . $slave['private_ip_address'];
            passthru($command);
        }
    }
}

/**
 * Generate lsyncd.conf.lua
 */
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

echo "New configuration file generated at " . $APP_CONF['data_dir'] . "lsyncd.conf.lua\n";
echo "Restart Lsyncd\n";
restartLsyncd($APP_CONF);
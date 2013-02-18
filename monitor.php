#!/usr/bin/env php
<?php
/**
 * 1. Monitors auto scaled instances in a group
 * 2. Generates lsyncd.conf.lua.
 * 3. Restart existing lsyncd
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
    keepLsyncdAlive();
    exit();
}

echo "There are changes in slaves.\n";

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
            'private_dns' => $instance['PrivateDnsName']
        );
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

// Restart Lsyncd

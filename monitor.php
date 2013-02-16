#!/usr/bin/env php
<?php
/**
 * 1. Monitors auto scaled instances in a group
 * 2. Generates lsyncd.conf.lua.
 * 3. Restart existing lsyncd
 */
require 'config.php';
require 'vendor/autoload.php';

use Aws\ElasticLoadBalancing\ElasticLoadBalancingClient;

$client = ElasticLoadBalancingClient::factory(array(
    'key' => $AWS_CONF['key'],
    'secret' => $AWS_CONF['secret'],
    'region' => $AWS_CONF['region']
));

$balancers = $client->describeLoadBalancers(array(
    'LoadBalancerNames' => array($AWS_CONF['load_balancer_name'])
));

if (empty($balancers)) {
    trigger_error('Failed to obtain description of load balancer.', E_USER_ERROR);
}
if (empty($balancers['LoadBalancerDescriptions'][0]['Instances'])) {
    trigger_error('No EC2 instances found.', E_USER_ERROR);
}

$instances = $balancers['LoadBalancerDescriptions'][0]['Instances'];

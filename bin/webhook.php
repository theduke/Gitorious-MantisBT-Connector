<?php 

require '../lib/MantisBeanstalk.php';
require '../lib/vendor/mantisconnect-php/lib/MantisConnector.php';

$mantisBeanstalk = new MantisBeanstalk();

/**
 * here you specify the basic settings for your mantis environment
 * 
 *  first arugment is the url to your mantisconnect.php, dont forget the 
 *  ?wsdl though!
 *  
 *  second and third are your mantis username and password
 *  
 *  you should create a new mantis user especially for beanstalk
 *  give it all appropriate rights for the relevant project(s), and pick a good
 *  password
 */
$connector = new MantisConnector(
  'http://your-mantis-url.com/api/soap/mantisconnect.php?wsdl', 
  'your-mantis-user', 
  'mantis-password', 
  // custom soap options here 
  array()
);
  
// set the mantis client
$mantisBeanstalk->setMantisClient($connector);

// set your custom log path
$logPath = realpath(__DIR__ . '/../log');
$mantisBeanstalk->setLogPath($logPath);  

$mantisBeanstalk->run();

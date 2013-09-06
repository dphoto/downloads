<?php


error_reporting(E_ALL);
ini_set('display_errors', 1);

date_default_timezone_set('Australia/Victoria');


require_once 'aws.phar';


use Aws\Common\Aws;
use Aws\Common\Enum\Region;
use Aws\ElasticTranscoder\Exception;
use Aws\S3;
use Aws\S3\S3Client;



$aws = Aws::factory(array(
	  'key'    => '16ZNP93R6M44KJEJ2M02',
	  'secret' => 'T1xwJfmpufOP46TQh+dOfCMKGq7g/APzZqgToCFS',
	  'region' => Region::US_EAST_1
)); 

$s3 = $aws->get('s3');


// $bucket = 'ap.files.dphoto.com';
// $key = '1/original/5862864-bc6c5x-99a51s.jpg';

// 				$args = array(	'ResponseContentType' => 'imge/jpeg', 
// 								'ResponseContentDisposition' => "attachment; filename=hello.jpg",
// 								'Scheme' => 'http');


// $link = $s3->getObjectUrl( $bucket, $key, '+2 days', $args );

// var_dump($link);

// $link = str_replace('s3.amazonaws.com/', '', $link);


$bucket = 'us.files.dphoto.com';
$key = '1/medium/18151115-a28d3j.mp4';

$result = $s3->copyObject(array(
	'ACL' => 'public-read',
	'ContentType' => 'video/mp4',
	'CopySource' => "$bucket/$key",
	'CacheControl' => 'max-age=315360000',
	'Expires' => gmdate('D, d M Y H:i:s T', strtotime('+10 years')),
	'Metadata' => array(
	    			'Cache-Control' => 'max-age=315360000',
	    		 	'Expires' => gmdate('D, d M Y H:i:s T', strtotime('+10 years'))
	    		),    	
	'MetadataDirective'=> 'REPLACE',
	'StorageClass' => 'REDUCED_REDUNDANCY',
    'Bucket' => $bucket,
    'Key' => $key
));




var_dump($result);


?>

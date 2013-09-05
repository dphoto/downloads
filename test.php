<?php


error_reporting(E_ALL);
ini_set('display_errors', 1);



require_once 'aws.phar';


use Aws\Common\Aws;
use Aws\Common\Enum\Region;
use Aws\ElasticTranscoder\Exception;
use Aws\S3;
use Aws\S3\S3Client;

$bucket = 'ap.files.dphoto.com';
$key = '1/original/5862864-bc6c5x-99a51s.jpg';

$aws = Aws::factory(array(
	  'key'    => '16ZNP93R6M44KJEJ2M02',
	  'secret' => 'T1xwJfmpufOP46TQh+dOfCMKGq7g/APzZqgToCFS',
	  'region' => Region::US_EAST_1
)); 

$s3 = $aws->get('s3');

				$args = array(	'ResponseContentType' => 'imge/jpeg', 
								'ResponseContentDisposition' => "attachment; filename=hello.jpg",
								'SaveAs' => "hello.jpg",
								'Scheme' => 'http');


$link = $s3->getObjectUrl( $bucket, $key, '+2 days', $args );

$link = str_replace('s3.amazonaws.com/', '', $link);

//var_dump($link);

echo "$link";


?>

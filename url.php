<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'aws.phar';

// S3
use Aws\S3;
use Aws\S3\S3Client;
use Aws\S3\Exception\Parser;
use Aws\S3\Exception\S3Exception;


$s3 = S3Client::factory(array(
    'region'  => 'us-east-1',
));


$key = '7/original/39413356-73ce3r-59e74u.jpg';
$filename = 'test.jpg';
$headers = array(
	'ResponseContentType' => 'image/jpeg',
	'ResponseContentDisposition' => "inline; filename=$filename",
	'Scheme' => 'https'
);

try{


$command = $s3->getCommand('GetObject', array(
    'Bucket' => 'us.files.dphoto.com',
    'Key' => $key,
    'ResponseContentType' => 'image/jpeg',
    'Scheme' => 'https',
    'ResponseContentDisposition' => 'attachment; filename="test.jpg'
));

// Create a signed URL from the command object that will last for
// 10 minutes from the current time
$signedUrl = $command->createPresignedUrl('+2 days');

	//$url = $s3->getObjectUrl( 'us.files.dphoto.com', $key, '+2 days', $headers );
	echo "signed :: $signedUrl";
}  catch( Exception $e ){
	echo $e->getMessage();
}



?>
<?php

error_reporting( E_ALL );
ini_set( 'display_errors', 'on' );

require_once( 'aws.phar' );

echo "hello";

use Aws\Common\Aws;
use Aws\Common\Enum\Region;
use Aws\ElasticTranscoder\Exception;

$aws = Aws::factory(array(
  'key'    => '16ZNP93R6M44KJEJ2M02',
  'secret' => 'T1xwJfmpufOP46TQh+dOfCMKGq7g/APzZqgToCFS',
  'region' => Region::US_EAST_1
));

$client = $aws->get('elastictranscoder');

//http://uploads.dphoto.com.s3.amazonaws.com/P3230424.MOV







$w = 1920;
$h = 1080;
$key = "new9.mp4";

// Medium - 1368847087163-19x5qq
// Large - 1368847172546-ck7n6l
// Huge - 1368847228679-ylapdz
// Huge Auto  - 1368851900054-u59rh9
// HD - 1368847265577-lia9ad
// HD AUTO 1368851763136-h5jnzx

$medium = array(
	'Key' => 'medium/' . $key,
	'Rotate' => 'auto',
	'ThumbnailPattern' => $key . "-{count}",
	'PresetId' => '1368847087163-19x5qq'
);

$large = array(
	'Key' => 'large/' . $key,
	'Rotate' => 'auto',
	'ThumbnailPattern' => "",
	'PresetId' => '1368847172546-ck7n6l'
);

$huge = array(
	'Key' => 'huge/' . $key,
	'Rotate' => 'auto',
	'ThumbnailPattern' => "",
	'PresetId' => '1368851900054-u59rh9'
);

$hd = array(
	'Key' => 'hd/' . $key,
	'Rotate' => 'auto',
	'ThumbnailPattern' => "",
	'PresetId' => '1368851763136-h5jnzx'
);


//CREATE JOB
try {
	echo "Try";
    $result = $client->createJob(array(
        'PipelineId' => '1367835769791-f2e651',
        'Input' => array(
        	'Key' => 'walk.mov',
        	'FrameRate' => 'auto',
        	'Resolution' => 'auto',
        	'AspectRatio' => 'auto',
        	'Interlaced' => 'auto',
        	'Container' => 'auto'
        	),
        'Outputs' => array( $medium),
        'OutputKeyPrefix' => '0/video/'
    ));

    print_r($result['Job']);
} catch (Exception $e) {
	echo "Fail";
    echo 'The item could not be retrieved.' .$e->message;
}



// try {
// 	echo "Try";
//     $result = $client->listJobsByPipeline(array(
//         'PipelineId' => '1367835769791-f2e651',
//         'Ascending' => 'false',
//     ));

//     print_r($result['Jobs']);
// } catch (Exception $e) {
// 	echo "Fail";
//     echo 'The item could not be retrieved.' .$e->message;
// }

// try {
// 	echo "Try";
//     $result = $client->listPipelines();

//     print_r($result['Pipelines']);
// } catch (Exception $e) {
// 	echo "Fail";
//     echo 'The item could not be retrieved.' .$e->message;
// }

// try {
// 	echo "Try";
//     $result = $client->updatePipeline(array(
//         'Id' => '1367835769791-f2e651',
//         'ContentConfig' => array(
//         	'Bucket' => 'us.files.dphoto.com',
//         	'StorageClass' => 'ReducedRedundancy',
//         	'Permissions' => array(array(
//         		"GranteeType" => "Group",
//         		'Grantee' => "AllUsers",
//         		'Access' => array('Read')
//         	)
//         	)
//         ),
//         'ThumbnailConfig' => array(
//         	'Bucket' => 'us.files.dphoto.com',
//         	'StorageClass' => 'ReducedRedundancy',
//         	'Permissions' => array(array(
//         		'Grantee' => "AllUsers",
//         		"GranteeType" => "Group",
//         		'Access' => array('Read')
//         	)
//         	)
//         ),        
//     ));

//     print_r($result['Pipeline']);
// } catch (Exception $e) {
// 	echo "Fail";
//     echo 'The item could not be retrieved.' .$e->message;
// }

// try {
// 	echo "Try";
//     $result = $client->updatePipelineNotifications(array(
//         'Id' => '1367835769791-f2e651',
//         'Notifications' => array(
//         	'Progressing' => 'arn:aws:sns:us-east-1:155286382920:VideoAlert:0fcf6dd3-1fb7-413c-a904-ade54ce65978',
//         	'Completed' => 'arn:aws:sns:us-east-1:155286382920:VideoAlert:0fcf6dd3-1fb7-413c-a904-ade54ce65978',
//         	'Warning' => 'arn:aws:sns:us-east-1:155286382920:VideoAlert:0fcf6dd3-1fb7-413c-a904-ade54ce65978',
//         	'Error' => 'arn:aws:sns:us-east-1:155286382920:VideoAlert:0fcf6dd3-1fb7-413c-a904-ade54ce65978')
//     ));

//     print_r($result['Pipeline']);
// } catch (Exception $e) {
// 	echo "Fail";
//     echo 'The item could not be retrieved.' .$e->message;
// }



// // READ PIPLINE
// try {
// 	echo "Try";
//     $result = $client->readPipeline(array(
//         'Id' => '1367835769791-f2e651'
//     ));

//     print_r($result['Pipeline']);
// } catch (Exception $e) {
// 	echo "Fail";
//     echo 'The item could not be retrieved.';
// }



?>

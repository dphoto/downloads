<?php



// External files
require_once 'services.php';

error_reporting(E_ALL ^ E_NOTICE);
putenv("MAGICK_TMPDIR=/var/tmp");



class Tasks extends Services{

	public $image = '';
	public $exif = array();
	public $info = array();
	public $sizes = array();
	public $tags = array();
	public $processing = false;
	
	// Resize variations that are to be created
	public $variations = array(	'thumb'		=> array('width' => 60,		'height' => 60 ,	'aspect' => 'crop',		'quality' => 84),
								'square'	=> array('width' => 130,	'height' => 130 ,	'aspect' => 'crop',		'quality' => 84),
								'tiny'		=> array('width' => 80,		'height' => 80 ,	'aspect' => 'scale',	'quality' => 84),
								'small'		=> array('width' => 160,	'height' => 160 ,	'aspect' => 'scale',	'quality' => 84),
								'blog'		=> array('width' => 400,	'height' => 400 ,	'aspect' => 'scale',	'quality' => 85),
								'medium'	=> array('width' => 640,	'height' => 480 ,	'aspect' => 'scale',	'quality' => 85),							
								'large'		=> array('width' => 960,	'height' => 720 ,	'aspect' => 'scale',	'quality' => 87),
								'huge'		=> array('width' => 1280,	'height' => 960 ,	'aspect' => 'scale',	'quality' => 87),	
								'hd'		=> array('width' => 1920, 	'height' => 1440 , 	'aspect' => 'scale', 	'quality' => 88));

	
  	// public array to store video targets
  	public $videos = array(		'medium'	=> array('width' => 640,		'height' => 640,		'video_bitrate' => 1000,		'audio_bitrate' => 128),
								'large'		=> array('width' => 960,		'height' => 960,		'video_bitrate' => 1500,		'audio_bitrate' => 128),
								'huge'		=> array('width' => 1280,		'height' => 1280,		'video_bitrate' => 3000,		'audio_bitrate' => 128),
								'hd'		=> array('width' => 1920,		'height' => 1920,		'video_bitrate' => 6000, 		'audio_bitrate' => 192));	
	

	// Maps exif orientation value to degrees 
	public $orientationMap = array(	3	=> 180,
									6	=> 90,
									8	=> 270,
									13	=> 180,
									16	=> 90,
									18	=> 270);


    # private array to temporarily store information about the original video
    private $original = array();
  
  
    # debug flag
    const DEBUG = true;  
  


	// Array or prefered make names
	public $makes = array( 	'Konica', 'Sony', 'Casio', 'Pentax', 'Kyocera', 'Konica', 'Samsung', 'Canon', 
							'Ricoh', 'Olympus', 'Fuji', 'Leica', 'Kodak', 'Nikon', 'Minolta', 'Motorola', 
							'Noritsu', 'Hitachi', 'Panasonic', 'KDDI', 'Toshiba', 'Leica', 'Nokia', 'Microtek', 
							'Sharp', 'Sanyo', 'Xerox', 'Vivitar', 'Yakumo', 'Mercury', 'Medion', 'Polaroid','Apple');
	
	
	/* ---------------------------------------
	
	TASK MANAGER
	
	--------------------------------------- */	
	
	function Tasks() {

		
		// Init services
		parent::__construct('Process');

		echo "Init";

		// Number of seconds to run
		$duration = $this->getDuration();

		// Timestamp to retire script
		$expires = time() + $duration;

		// Allow to run a bit overtime
		set_time_limit($duration);
		
		// Space out timing
		sleep(rand(1, 15));

		// Add guid to id
		$this->id = $this->server . "-".$this->getGuid(6);
		
		// Bump up memory
		ini_set('memory_limit', '512M');
		
		// If a task has hung the script, error
		register_shutdown_function(array($this, 'onTimeout'));

		// Set global exception handler
		set_exception_handler(array($this, 'onException'));

		// Start the backup process
		while(time() < $expires) $this->getTasks();
		
	}


	
	/* ---------------------------------------
	
	GET DURATION
	
	--------------------------------------- */

	function getDuration(){
		
		$min = 120;
		$max = 240;
		
		$queue = $this->db->select("SELECT COUNT(*) FROM tasks WHERE task_status = 'W' AND task_type IN ('V', 'P', 'R')", 'value');
		
		$time = max($queue * 30, $min);
		$time = min($time, $max);
		
		return $time;
		
	}




	/* ---------------------------------------
	
	GET TASKS
	
	--------------------------------------- */
	
	function getTasks(){

		// Check num of videos being processed
		//exec("pgrep -l ffmpeg", $out);


		//$this->error('Process', "$this->server Checking video count ". count($out), null, true);		
		$count = $this->db->select("SELECT COUNT(*) FROM tasks WHERE task_status LIKE '{$this->server}%' AND task_type = 'V'", 'value');
		// Limit videos
	//	$this->error('Test', "SELECT COUNT(*) FROM tasks WHERE task_status LIKE '{$this->id}%' AND task_type = 'V'");
		
		//if($this->server == '184.73.146.3'){

			$where = " AND user_id = 1 ";

		//} else {

		//	$where = " AND user_id <> 1 ";

		//}


		if($count < 1){

			$this->db->update('tasks', array('task_status' => $this->id), "task_status='W' AND task_type IN ('V', 'P', 'R', 'RE', 'RV') $where ORDER BY task_id LIMIT 1");
			
		} else {
				
			// Only get photos
			$this->db->update('tasks', array('task_status' => $this->id), "task_status='W' AND task_type IN ('P', 'R') $where ORDER BY task_id LIMIT 1");				
				
		}
	
		// If task was reserved
		if(mysql_affected_rows() == 0){

			// No task ready, just wait
			sleep(rand(20, 40));
			
			return;
		
		} else {
			
			// Set flag for cleanup check
			$this->processing = true;
			$this->exif = array();
		 	$this->info = array();
			$this->sizes = array();
			$this->original = array();
			$this->tags = array();			
			
			// Get the task
			$query = "	SELECT t.*, 
						u.user_auto_enhance, u.user_auto_rotate, u.user_region, u.user_video_duration, f.watermark_id,
						f.file_key, f.file_code, f.file_upext, f.file_ext, f.file_title, f.file_description, f.file_backup, f.file_tags 
						FROM tasks t LEFT JOIN users u ON t.user_id = u.user_id LEFT JOIN files f ON t.file_id = f.file_id 
						WHERE t.task_status = '$this->id'
						LIMIT 1";
		
			$this->task = $this->db->select($query, 'row');

			// Store task in class vars for easy access
			foreach($this->task as $key => $value) $this->{$key} = $value;

			if($this->user_id == 1) $this->error('New Process', "Task 0");

			// If existing tags, populate array
			if($this->file_tags != '') $this->tags = explode(',', $this->file_tags);	
			
			// Check file data exists
			if($this->file_key == ''){
				
				$this->task_type = '?';
				$this->task_comments = "Unable to retrieve task data";
				$result = false;					

			}			

	

			if($this->user_id == 1) $this->error('New Process', "Task 1");

			$this->task_start = microtime(true);
			$this->task_attempt++;

			// Determine task type
			switch($this->task_type){
				
				case 'P':
				
					if($this->user_id == 1) $this->error('New Process', "Task 2");

					set_time_limit(600);
				
					$result = $this->processPhoto();
					break;
				
				case 'R':
				
					set_time_limit(600);
				
					$result = $this->processRotate();
					break;
					
				case 'RE':
				
					set_time_limit(600);
				
					$result = $this->reprocessPhoto();
					break;					
				
				case 'V':
				
					set_time_limit(6000);
				
					$result = $this->processVideo();
					break;
				
				case 'RV':
				
					set_time_limit(6000);
				
					$result = $this->reprocessVideo();
					break;				
				
			}

			// Determine resulting status
			if($result == true) $this->task_status = 'C';
			if($result == false) $this->task_status = ($this->task_attempt < 3) ? 'W' : 'E';
			
			// Update tasks table
			$update = array('task_status' => $this->task_status,
							'task_serviced'	=> $this->id,
							'task_comments'	=> $this->task_comments,
							'task_attempt'	=> $this->task_attempt,
							'xx_task_completed' => 'CURRENT_TIMESTAMP' );
			
			$this->db->update('tasks', $update, "task_id = $this->task_id");
			
			// Set flag for cleanup check
			$this->processing = false;

		}		
		
	
	}
	
	
	function reprocessPhoto(){

		// Get S3 details of original
		$bucket = $this->getBucket($this->file_backup);
		$key = $this->getKey($this->task, 'original');

		$new_bucket = $this->getBucket($this->user_region);

		// Get uploaded files from S3
		if($original = $this->getPhoto($bucket, $key)){

			// Create a new key to prevent cache
			$this->new_key = $this->getGuid(6);
			$this->old_key = $this->task['file_key'];
			$this->task['file_key'] = $this->new_key;

			// Create Resized images
			if($this->resizePhoto($original)){

				// Update key with new file_key
				$key = $this->getKey($this->task, 'original');

				// Send rotated original to S3
				$this->putPhoto($original, $new_bucket, $key);

				// Update sizes table
				$this->setSizes();

				// Insert the new key into the db
				$this->db->update('files', array('file_key' => $this->new_key, 'file_backup' => $this->user_region, 'file_resize' => $this->file_resize), "file_id = $this->file_id");

				// Delete local copy
				unlink($original);

				// Set key back to old value
				$this->task['file_key'] = $this->old_key;

				// Loop through and delete the old files from S3
				foreach($this->variations as $size => $properties) {

					$this->deletePhoto($bucket, $this->getKey($this->task, $size));

				}

				// Delete the old original
				$this->deletePhoto($bucket, $this->getKey($this->task, 'original'));

				// Success
				return true;


			}	
			
		}
		
		return false;

	}
	
	function reprocessVideo(){

		// Get S3 details of original
		$bucket = $this->getBucket($this->file_backup);
		$key = $this->getKey($this->task, 'original');
		$new_bucket = $this->getBucket($this->user_region);

		// Get uploaded files from S3
		if($filepath = $this->getPhoto($bucket, $key)){

			// Create a new key to prevent cache
			$this->new_key = $this->getGuid(6);
			$this->old_key = $this->task['file_key'];
			$this->task['file_key'] = $this->new_key;

			# extract original about video
	  		$this->getVideoInfo($filepath);

			// Create resized images
			if($this->resizeVideo($filepath)){

			// Sizes in db
	  			$this->getVideoFrame($filepath);

				// Update key with new file_key
				$key = $this->getKey($this->task, 'original');

				// Send rotated original to S3
				$this->putPhoto($filepath, $new_bucket, $key);

				$this->setSizes();

				// Update the files table
				$a = array( 'file_key' => $this->new_key, 'file_resize' => $this->file_resize, 'file_backup' => $this->user_region);

				$this->db->update('files', $a, "file_id = $this->file_id");

				// Delete local copy
				unlink($filepath);

				// Set key back to old value
				$this->task['file_key'] = $this->old_key;

				// Loop through and delete the old files from S3
				foreach($this->variations as $size => $properties) {

					$this->deletePhoto($bucket, $this->getKey($this->task, $size));

				}

				// Delete the old original
				$this->deletePhoto($bucket, $this->getKey($this->task, 'original'));


				// Success
				return true;				
				
			}			
			
		}
		
		return false;

	}	
	


	
	
	
	function processPhoto(){

		if($this->user_id == 1) $this->error('New Process', "Stage 1");

		// Get uploaded files from S3
		if($original = $this->getPhoto('uploads.dphoto.com', $this->task_parameter)){

			if($this->user_id == 1) $this->error('New Process', "Stage 2");

			// Get S3 details of original
			$bucket = $this->getBucket($this->user_region);
			$key = $this->getKey($this->task, 'original');
		
			// Extract EXIF data
			$this->getExif($original);
			$this->getIptc($original);

			if($this->user_id == 1) $this->error('New Process', "Stage 3");

			//if($this->user_id == 1) $this->error('Auto Rotate Image', "Angle: ".$this->exif['Orientation'], 0, true);

			// Check if file should be auto rotated
			// Rotated photos are different from original, for DNG and ther files that can't be rotated
			if($this->user_auto_rotate && isset($this->exif['Orientation']) && $this->exif['Orientation'] > 1 && $this->file_upext != 'cr2' && $this->file_upext != 'nef'){
				
				if($this->user_id == 1) $this->error('New Process', "Stage 4");

				$angle = $this->orientationMap[$this->exif['Orientation']];

				//if($this->user_id == 1) $this->error('Auto Rotate Image', "Angle: $angle", 0, true);

				$rotated = $this->rotatePhoto($original, $angle);
		
			} else {
				
				$rotated = $original;
				
			}
		
			if($this->user_id == 1) $this->error('New Process', "Stage 5");

			// Create resized images
			if($this->resizePhoto($rotated)){
				
				if($this->user_id == 1) $this->error('New Process', "Stage 6");

				// Insert sizes into db
				$this->setSizes();
		
				// Send original to S3
				if($this->putPhoto($original, $bucket, $key)){

					if($this->user_id == 1) $this->error('New Process', "Stage 7");

					// Update the files table
					$a = array( 'file_backup' 		=> $this->user_region,
								'file_title' 		=> $this->file_title,
								'file_resize' 		=> $this->file_resize,
								'file_created' 		=> $this->file_created,
								'file_description' 	=> $this->file_description,
								'file_type'			=> 'P',
								'file_ext'			=> 'jpg',
								'file_size'			=> filesize($original),
								'file_tags'			=> implode(',', array_unique($this->tags) )
								);

					$this->db->update('files', $a, "file_id = $this->file_id");
		
					$this->addFileToAlbum($this->file_id, $this->album_id);


					// Delete the S3 file from uploads
					$this->deletePhoto('uploads.dphoto.com', $this->task_parameter);

					// Delete local copy
					unlink($original);
					if ($rotated != $original) unlink($rotated);

					// Success
					return true;	
				
				}			
				
			}			
			
		} else {
			
			$this->task_comments = "Could not retrieve file";
			
		}
		
		return false;

	}
	
	function processRotate(){
	
		$bucket = $this->getBucket($this->file_backup);
		$key = $this->getKey($this->task, 'original');
	
		// Get uploaded files from S3
		if($original = $this->getPhoto($bucket, $key)){
			
			// Rotate the original
			// For DNG images, a JPG copy is created and the path to that returned.
			// For other images, the original is rotated and the path is the same as $original
			
			$rotated = $this->rotatePhoto($original, $this->task_parameter);
		
			// Create a new key to prevent cache
			$this->new_key = $this->getGuid(6);
			$this->old_key = $this->task['file_key'];
			$this->task['file_key'] = $this->new_key;
		
			// Create Resized images
			if($this->resizePhoto($rotated)){
				
				// Update key with new file_key
				$key = $this->getKey($this->task, 'original');
				
				// Send rotated original to S3
				if($this->putPhoto($original, $bucket, $key)){
		
					// Update sizes table
					$this->setSizes();
		
					// Insert the new key into the db
					$this->db->update('files', array('file_key' => $this->new_key, 'file_resize' => $this->file_resize), "file_id = $this->file_id");
			
					// Delete local copy
					unlink($original);
					if ($rotated != $original) unlink($rotated);
			
					// Set key back to old value
					$this->task['file_key'] = $this->old_key;
		
					// Loop through and delete the old files from S3
					foreach($this->variations as $size => $properties) {

						$this->deletePhoto($bucket, $this->getKey($this->task, $size));

					}
				
					// Delete the old original
					$this->deletePhoto($bucket, $this->getKey($this->task, 'original'));
			
					// Success
					return true;
				
				}
				
				
			}
						
			
		} else {
			
			$this->task_comments = "Could not retrieve file";
			
		}

		return false;
				
	}


	function addFileToAlbum($file_id, $album_id){
		
		// Append the file_id to the album
		$this->db->append('albums', array('album_photos' => $file_id), "album_id = $album_id");
						
		//$album_parent = $album_id;
		
		while($album_id != ''){
	
			// Set as album cover if first in album	
			$this->db->update('albums', array('xx_album_icon' => "(CASE WHEN album_icon IS NULL THEN $file_id ELSE album_icon END)"), "album_id = $album_id");			

			$album_id = $this->db->select("SELECT album_parent FROM albums WHERE album_id = $album_id", 'value');


		}				

	}


	function processVideo(){
		
		if($this->user_id == 1) $this->error('New Process', "Video 1");

		// Get uploaded files from S3
		if($filepath = $this->getPhoto('uploads.dphoto.com', $this->task_parameter)){

			if($this->user_id == 1) $this->error('New Process', "Video 2");

			// Get S3 details of original
			$bucket = $this->getBucket($this->user_region);
			$key = $this->getKey($this->task, 'original');



			# extract original about video
	  		$this->getVideoInfo($filepath);	

	  		if($this->user_id == 1) $this->error('New Process', "Video 3");
			
			// Check that video isn't too long
			if($this->info['video_duration'] > $this->user_video_duration){
			
				$this->error('Process Video', 'Video was longer than allowed '.$this->info['video_duration'] , null, true);
				$this->task_attempt = 3;
				$this->task_comments = "Video is too long";
			
				return false;
			
			}
		
	
	  		// Create resized images
	  		if($this->resizeVideo($filepath)){
			
	  			if($this->user_id == 1) $this->error('New Process', "Video 4");

	  			// Sizes in db
	  			$this->getVideoFrame($filepath);
				
	  			if($this->user_id == 1) $this->error('New Process', "Video 5");
				
				$this->setSizes();
			
				//echo "Sending original video to S3 \n";
			
	  			// Send original to S3
	        	if($this->putPhoto($filepath, $bucket, $key)){

					//echo "Updating files table \n";

		  			// Update the files table
		        	$a = array(	'file_backup'    	=> $this->user_region,
				                'file_title'        => $this->file_title,
				                'file_description'  => $this->file_description,
				             	'file_type'         => 'V',
				                'file_ext'          => 'mp4',
				                'file_size'         => filesize($filepath),
								'file_resize'       => $this->file_resize,
				                'file_tags'         => implode(',',array_unique($this->tags)),
								'xx_file_created' 	=> 'CURRENT_TIMESTAMP',
				                );
	        
		            $this->db->update('files', $a, "file_id = $this->file_id");
	
		  			// Append the file_	id to the album
		       		$this->addFileToAlbum($this->file_id, $this->album_id);

					//echo "Removing uploaded file from S3 \n";

		  			// Delete the S3 file from uploads
		        	$this->deletePhoto('uploads.dphoto.com', $this->task_parameter);

		  			// Delete local copy
		        	unlink($filepath);

					//echo "Finished ProcessVideo \n";
				
				
				
					//$this->error('Process Video', "Completed processing video $this->file_id", null, true);

		  			// Success
		  			return true;				
			
		  		}	
			
			}
	
		} else {
			
			$this->task_comments = "Count not retrieve file";			
			
		}		
		
		
		
  		return false;

  	}



	function getVideoFrame($filepath, $time = 5){
		
		$width = $this->sizes['size_original_w'];
		$height = $this->sizes['size_original_h'];

		if($this->info['video_duration'] < 6){
			
			$time = ($this->info['video_duration']/2);
			
		}

		$frame = '/tmp/frame.jpg';

		$cmd =	"/usr/local/bin/ffmpeg -y -i $filepath -f mjpeg -ss $time -vframes 1 ".
				"-s {$width}x{$height} -an $frame 2>&1";		
				
		exec( $cmd, $out, $ret );
		
		// Set ext back to jpg temporarily
		$this->task['file_ext'] = 'jpg';
		
		// Resize the poster frame
		$this->resizePhoto($frame);

		// Return extension back to 
		$this->task['file_ext'] = 'mp4';
		
		// Delete local copy
	    unlink($frame);
			
	}
	
	function resizeVideo($filepath){
		
		echo "Resizing Video $filepath";

		// Determine largest filesize
		$this->file_resize = $this->getLargestSize($this->sizes['size_original_w']);
		

		foreach($this->videos as $size => $properties) {



			# enum target width
			$width = $properties['width'];

            # enum scaled dimensions
			if ($this->sizes['size_original_w'] > $width) {
				
				# enum aspect ratio based on width / height
				$aspect = $this->sizes['size_original_w'] / $this->sizes['size_original_h'];
				
				# scaled height must be divisible by sixteen so get rid of decimals
				$height = ((int) (($width / $aspect) / 16) * 16);
			
			} else { # preserve original dimensions if original width < target width
				
				// Use original dimensions
				$width  = $this->sizes['size_original_w'];
				$height = $this->sizes['size_original_h'];
				
				if($width % 2 != 0) $width++;
				if($height % 2 != 0) $height++;		
			
			}

            # set target bitrates
			$vb = $properties['video_bitrate'];  // should use current bitrate if lower than properties
			$ab = $this->info['video_abitrate'] > $properties['audio_bitrate'] ? "-ab {$properties['audio_bitrate']}k" : "";
			$fps = $this->info['video_fps'] > 25 ? 25 : $this->info['video_fps'];
			$g  = 15; # approx 1 keyframe/sec if framerate = 30 frames/sec  
			$profile = $size == 'medium' ? 'baseline' : 'main';
			

	
			$bucket = $this->getBucket($this->task['user_region']);
			$key = $this->getKey($this->task, $size);

			
			$local_temp = "/tmp/temp_".str_replace('/','-',$key);
			$local_final = "/tmp/final_".str_replace('/','-',$key);

			# add sizes to the array
			$this->sizes['size_'.$size."_w"] = $width;
  			$this->sizes['size_'.$size."_h"] = $height;
			


			$attempt = 0;
			$complete = false;

			while($attempt < 4 && $complete == false){

				// 2 PASS
				/*
				$out = null; 

				$cmd1 = "/usr/local/bin/ffmpeg -y -i $filepath -an -pass 1 -s {$width}x{$height} -vcodec libx264 -b {$vb}k -bt {$vb}k -r $fps -threads 0 -g $g -profile $profile -f mp4 $local_temp 2>&1";	// /dev/null

				$cmd2 = "/usr/local/bin/ffmpeg -y -i $filepath -pass 2 -ar 44000 -acodec libfaac $ab -ac 2 -async 1 -s {$width}x{$height} -vcodec libx264 -preset slow -b {$vb}k -bt {$vb}k -r $fps -threads 0 -g $g -profile $profile -f mp4 $local_temp 2>&1";	

				exec( $cmd1, $out, $ret);

				exec( $cmd2, $out, $ret );
				
				*/
				// 1 Pass video processing
				$cmd = "/usr/local/bin/ffmpeg -y -i $filepath -ar 44000 -acodec libfaac $ab -ac 2 -async 1 -s {$width}x{$height} -vcodec libx264 -preset fast -b {$vb}k -bt {$vb}k -r $fps -threads 0 -g $g -profile $profile -f mp4 $local_temp 2>&1";	
				exec( $cmd, $out, $ret );

				// Move meta data to front of file for flash
				$cmd = "timelimit -t 60 /usr/local/bin/qt-faststart $local_temp $local_final";
				exec( $cmd, $out, $ret);
				
				$is_file = is_file($local_final);
				$is_readable = is_readable($local_final);	
				$file_size = filesize($local_final);				

				// Check is processing completed
				if($is_file && $is_readable && $file_size > 0){

					// Move photo to S3
					if($this->putPhoto($local_final, $bucket, $key)){		
						
						$this->task_comments = "";
						if($attempt > 0) $this->error('Resize Video', "Single Pass $size completed on attempt $attempt", null, true);

						$complete = true;

					}

				} 

				// File failed
				else {

					$this->task_comments = "Resize $size failed";
					$this->error('Resize Video', "Sinlge Pass failed on attempt $attempt\n\nhttp://uploads.dphoto.com/$this->task_parameter \n\ntask_id : $this->task_id\n\nFirst Pass : $cmd\n\nSize : $file_size\n\nFree Memory $free_mem\n\n" . implode(',',$out), null, true);	

					$attempt++;

					sleep(3);
				
				}

			}

			// Failed after 3 attempts
			unlink($local_temp); 
			unlink($local_final);	
			
			//Return false on fail
			if($complete == false) return false;

			if($this->file_resize == $size) return true;
		
  		}

  		// All sizes complete
  		return true;

	}


	function setVideoQuickstart($input, $output){
	
		$attempt = 1;

		// Have 4 tries at saving
		while($attempt <= 4){

			// Move meta data to front of file for flash
			$cmd = "timelimit -t 60 /usr/local/bin/qt-faststart $input $output";
			exec( $cmd, $out, $ret);		

			$is_file = is_file($output);
			$is_readable = is_readable($output);	

			if($is_file && $is_readable){

			//	$this->error('Quickstart', "Quickstart succedded on attempt $attempt", null, true);			
				
				// Success
				return true;
				
			} else {
				
				// Fail
				$attempt++;
				sleep(1);
				
			}
			
		}
	
		$this->error('Quickstart', "Quickstart FAILED on attempt $attempt, ret $ret, out ".implode($out), null, true);			
		
		return false;
		
		
	}


	function getVideoInfo($filepath) {
    
    	//$this->getVideoSize($filepath);






	    $command = '/usr/local/bin/ffmpeg -i ' . escapeshellarg($file) . ' 2>&1';
	    $dimensions = array();

	    exec($command,$output,$status);

	    $info = implode(' ', $output);

	    if (!preg_match('/Stream #(?:[0-9\.]+)(?:.*)\: Video: (?P<videocodec>.*) (?P<width>[0-9]*)x(?P<height>[0-9]*)/', $info, $matches))
	    {
	        preg_match('/Could not find codec parameters \(Video: (?P<videocodec>.*) (?P<width>[0-9]*)x(?P<height>[0-9]*)\)/', $info, $matches);
	    }
	    if(!empty($matches['width']) && !empty($matches['height']))
	    {
	        $dimensions['width'] = $matches['width'];
	        $dimensions['height'] = $matches['height'];
	    }


		$par_regex = '/PAR ([0-9]+)\:([0-9]+)/';
		$dar_regex = '/DAR ([0-9]+)\:([0-9]+)/';

		$has_par = preg_match( $par_regex, $info, $par_matches );
		$has_dar = preg_match( $dar_regex, $info, $dar_matches );

		if($has_par) $par = $par_matches[1] . ':' . $par_matches[2];
		if($has_dar) $dar = $dar_matches[1] . ':' . $dar_matches[2];
		

		if ( $has_par || $has_dar || $has_sar){

			$sar = $dimensions['width'] . ':' . $dimensions['height'];

			$this->error('Video Size', "Found Aspect DAR $dar, PAR $par, SAR $sar, \n\n $info", null, true);

		} 

		// Store raw info in DB
		$this->info['video_info'] = $info;

	    $this->sizes['size_original_w'] = $dimensions['width'];
	    $this->sizes['size_original_h']= $dimensions['height'];

	    echo "Got video width : ". $dimensions['width'];
	    echo "Got video height : ". $dimensions['height'];


    	// ---------------------------


		$video = new ffmpeg_movie($filepath);

		$this->info['user_id'] = $this->user_id;
		$this->info['file_id'] = $this->file_id;

		$this->info['video_duration'] = $video->getDuration();
		$this->info['video_fps'] = $video->getFrameRate();
		$this->info['video_codec'] = $video->getVideoCodec();;
		$this->info['video_bitrate'] = $video->getVideoBitRate();

		$this->info['video_achannel'] = $video->getAudioChannels();
		$this->info['video_acodec'] = $video->getAudioCodec();
		$this->info['video_afreq'] = $video->getAudioSampleRate();
		$this->info['video_abitrate'] = $video->getAudioBitRate();

	    $this->sizes['size_original_w'] = $video->getFrameWidth();
	    $this->sizes['size_original_h']= $video->getFrameHeight();

		// Getting weird numbber from some videos.
		if($this->sizes['size_original_h'] == 1088){
			
			$this->error('Video Size', "Changing size form 1088 to 1080", null, false);
			
			$this->sizes['size_original_h'] = 1080;
			
		} 

		// Insert video info to db
		$this->db->insert('videos', $this->info);

    	// Success  
    	return true;
  	
    }



	function getVideoSize($file)
	{
	    $command = '/usr/local/bin/ffmpeg -i ' . escapeshellarg($file) . ' 2>&1';
	    $dimensions = array();

	    exec($command,$output,$status);

	    $info = implode(' ', $output);

	    if (!preg_match('/Stream #(?:[0-9\.]+)(?:.*)\: Video: (?P<videocodec>.*) (?P<width>[0-9]*)x(?P<height>[0-9]*)/', $info, $matches))
	    {
	        preg_match('/Could not find codec parameters \(Video: (?P<videocodec>.*) (?P<width>[0-9]*)x(?P<height>[0-9]*)\)/', $info, $matches);
	    }
	    if(!empty($matches['width']) && !empty($matches['height']))
	    {
	        $dimensions['width'] = $matches['width'];
	        $dimensions['height'] = $matches['height'];
	    }

	    

		$par_regex = '/PAR ([0-9]+)\:([0-9]+)/';
		$dar_regex = '/DAR ([0-9]+)\:([0-9]+)/';

		$has_par = preg_match( $par_regex, $info, $par_matches );
		$has_dar = preg_match( $dar_regex, $info, $dar_matches );

		if($has_par) $par = $par_matches[1] . ':' . $par_matches[2];
		if($has_dar) $dar = $dar_matches[1] . ':' . $dar_matches[2];
		

		if ( $has_par || $has_dar || $has_sar){

			$sar = $dimensions['width'] . ':' . $dimensions['height'];

			$this->error('Video Size', "Found Aspect DAR $dar, PAR $par, SAR $sar, \n\n $info", null, true);

		} 



	    return $dimensions;

	}





	/* ---------------------------------------
	
	GET EXIF
	
	--------------------------------------- */	
	
	function getExif($filepath){
		
		$model = '';
		$date = '';
		$exposure = '';
		$aperture = '';
		$focal = '';
		$iso = '';
		$bias = '';
		$flash = '';
		
		// Exit if no EXIF data is found
		if($this->exif = @exif_read_data($filepath, 'IFD0')){ 
			
			$make = @$this->exif['Make'];
			$model = @$this->exif['Model'];
			$date = @$this->exif['DateTimeOriginal'];
			$exposure = @$this->exif['ExposureTime'];
			$aperture = @$this->exif['COMPUTED']['ApertureFNumber'];
			$focal = @$this->exif['FocalLength'];
			$bias = @$this->exif['ExposureBiasValue'];
			$flash = @$this->exif['Flash'];
			$iso = @$this->exif['ISOSpeedRatings'];
		
			// Try and get a nicer Make string
			foreach($this->makes as $m){
			
				if(stripos($make, $m) !== false) $make = $m;
			
			}
		
			// Remove f/ from the aperture
			if(stripos($aperture, 'f/') === 0){
			
				 $aperture = substr($aperture, 2);
		
			}
		
			// Convert focal length to decimal
			if(stripos($focal, '/') !== false){
			
				$focal = explode('/', $focal);
				$focal = round($focal[0] / $focal[1], 1);
			
			} 
		
			// Check for poorly formatted 0
			if(stripos($bias, '0/') === 0){
			
				$bias = 0;

			} 			

		}
		
		// Store date for files table
		$this->file_created = $date;
		
		// Insert into the photos table
		$a = array(	'file_id'		=> $this->file_id,
					'user_id'		=> $this->user_id,
					'photo_make'	=> $make,
					'photo_model'	=> $model,
					'photo_date'	=> $date,
					'photo_exposure'=> $exposure,
					'photo_aperture'=> $aperture,
					'photo_focal'	=> $focal,
					'photo_iso'		=> $iso,
					'photo_bias'	=> $bias,
					'photo_flash'	=> $flash
					);
					

		$this->db->insert('photos', $a);

		// Geo co-ordinates
		if(isset($this->exif['GPSLatitude'])) $this->getGeo();
		
		
	}




	/* ---------------------------------------
	
	GET ITPC
	
	--------------------------------------- */	
	
	
	function getIptc($filepath){


		getimagesize($filepath, $info);        


		if( is_array($info) && isset($info["APP13"]) ) {    

			$iptc = iptcparse($info["APP13"]);

			if(is_array($iptc)){

				// Check for the headline
				if(isset($iptc["2#105"])){

					$this->file_title = $iptc["2#105"][0]; 
					
				}
				
				// Check for description
				if(isset($iptc["2#120"])){

					$this->file_description = $iptc["2#120"][0]; 
				
				}
				
				// Check for keywords
				if(isset($iptc["2#025"])){

					foreach($iptc["2#025"] as $tag) $this->tags[] = $tag;          				
					
				}
				
			}
             
		} 

	}


	

	/* ---------------------------------------
	
	GET GEO
	
	--------------------------------------- */	

	function getGeo(){
	
		// Get decimal latitude
		$r = ($this->exif['GPSLatitudeRef'] === "N") ? 1 : -1 ;
		$d = explode('/', $this->exif['GPSLatitude'][0]);
		$m = explode('/', $this->exif['GPSLatitude'][1]);
		$s = explode('/', $this->exif['GPSLatitude'][2]);
		$lat = $r * ( ( $d[0] / $d[1] ) + ( ( $m[0] / $m[1] ) / 60 ) + ( ($s[0] / $s[1] ) / 3600 ) );
		
		// Get decimal latitude
		$r = ($this->exif['GPSLongitudeRef'] === "E") ? 1 : -1 ;
		$d = explode('/', $this->exif['GPSLongitude'][0]);
		$m = explode('/', $this->exif['GPSLongitude'][1]);
		$s = explode('/', $this->exif['GPSLongitude'][2]);
		$long = $r * ( ( $d[0] / $d[1] ) + ( ( $m[0] / $m[1] ) / 60 ) + ( ($s[0] / $s[1] ) / 3600 ) );		
		
		// Get decimal Altitude
		$m = explode('/', $this->exif['GPSAltitude']);
		$alt = ($m[1] > 0) ? $m[0] / $m[1] : $m[0];
		
		
		$a = array();
		$a['file_id'] = $this->file_id;
		$a['user_id'] = $this->user_id;			
		$a['geo_latitude'] = $lat; 
		$a['geo_longitude'] = $long; 
		$a['geo_altitude'] = $alt; 
		$a['geo_latitude_ref'] = $this->exif['GPSLatitudeRef'];
		$a['geo_longitude_ref'] = $this->exif['GPSLongitudeRef']; 
		$a['geo_altitude_ref'] = $this->exif['GPSAltitudeRef'];
		
		
		// Build Google request
		$key = "ABQIAAAA_yqq1Vy6DR82OpNDOO5tMRRFwz7iXxPI3gVv5pom0-3bMBBFqhSeJzz2IfrRqvy0Rw2pxJBIZWJW_A";
		$url = "http://maps.google.com/maps/geo?q=$lat,$long&output=xml&key=$key";
 
		// Init cURL
		$curl = curl_init(); 
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_HEADER,0);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		
		// Send request and close connection
		$result = curl_exec($curl);
		curl_close($curl);

		// Parse XML result
		$xml = simplexml_load_string($result);

		// Check that a valid response came back
		if($xml->Response->Status->code == 200){
			
			$a['geo_accuracy'] = (int) $xml->Response->Placemark->AddressDetails['Accuracy'];
			$a['geo_address'] = (string) $xml->Response->Placemark->address;
			$a['geo_country'] = (string) $xml->Response->Placemark->AddressDetails->Country->CountryName;
			$a['geo_state'] = (string) $xml->Response->Placemark->AddressDetails->Country->AdministrativeArea->AdministrativeAreaName;
			$a['geo_city'] = (string) $xml->Response->Placemark->AddressDetails->Country->AdministrativeArea->Locality->LocalityName;		
			
		}

		/*
		// Auto tagging with location
		if(true) $this->tags[] = $a['geo_country'];
		if(true) $this->tags[] = $a['geo_state'];
		if(true) $this->tags[] = $a['geo_city'];
		*/
		// Insert into geos table
		$this->db->insert('geos', $a);



	}


	
	
	
	
	
	
	/* ---------------------------------------
	
	GET SIZES
	
	--------------------------------------- */	
	
	function setSizes(){
		
		// Update sizes table
		$this->sizes['user_id'] = $this->task['user_id'];
		$this->sizes['file_id'] = $this->task['file_id'];
		
		// Insert sizes for process
		if($this->task_type == 'P') $this->db->insert('sizes', $this->sizes);
		
		// Insert sizes for process
		if($this->task_type == 'V') $this->db->insert('sizes', $this->sizes);		
		
		// Update for rotate
		if($this->task_type == 'R' || $this->task_type == 'RE' || $this->task_type == 'RV'){ 
			
			// Check that a sizes row exists to update
			if(mysql_num_rows($this->db->select("SELECT * FROM sizes WHERE file_id = $this->file_id")) > 0){
			
				$this->db->update('sizes', $this->sizes, "file_id = $this->file_id");

			} else {

				$this->db->insert('sizes', $this->sizes);
				
			}

		}

		
	}
		
	
	
	
	


	/* ---------------------------------------
	
	RESIZE PHOTO
	
	--------------------------------------- */	
	

	function resizePhoto($file){
		
		try{

			if($this->user_id == 1) $this->error('New Process', "Resize 1");
			
			$srgb = file_get_contents('/usr/local/etc/ImageMagick/sRGB.icc');

			if($this->user_id == 1) $this->error('New Process', "Resize 2");

			// DNG files need to be cast
			if(stripos($file, '.dng')) $file = 'dng:'.$file;
			if(stripos($file, '.cr2')) $file = 'cr2:'.$file;
			if(stripos($file, '.pef')) $file = 'pef:'.$file;
			if(stripos($file, '.nef')) $file = 'nef:'.$file;
			if(stripos($file, '.srf')) $file = 'srf:'.$file;

			if($this->user_id == 1) $this->error('New Process', "Resize 3");

			// Open original file
			$original = new Imagick();

			if($this->user_id == 1) $this->error('New Process', "Resize 3.1");

			$original->readImage($file);
			if($this->user_id == 1) $this->error('New Process', "Resize 3.2");
			if($this->user_id == 1 ){ 
				//$profiles = $original->getImageProfiles("*", false);
				//$this->error('Profile Image', "GOT SRGB  : $srgb", 0, true);
				//$this->error('Profile Image', "BEFORE profiles : ". implode(',', $profiles), 0, true);
			}

			if($this->user_id == 1) $this->error('New Process', "Resize 4");

			$original->setImageBackgroundColor('white');
			$original->setImageResolution(72,72);	
			$success = $original->profileImage('icc', $srgb);	// Profile name should be icc	
			//if($this->user_id == 1 ) $original->setImageProfile('icc', $srgb);
			$original = $original->flattenImages();

			if($this->user_id == 1 ){ 
			//	$profiles = $original->getImageProfiles("*", false);
			//	$this->error('Profile Image', "After profiles success $success : ". implode(',', $profiles), 0, true);
			}
			//if($this->task['user_auto_enhance'] && $this->user_id == 1) $this->error('Profile Image', "auto enhance on", 0, true);

			// Add sizes to the array
			$w = $this->sizes['size_original_w'] = $original->getImageWidth();
			$h = $this->sizes['size_original_h'] = $original->getImageHeight();		
			
			// Find largest image to build
			$this->file_resize = $this->getLargestSize($w, $h);
		
			// Enhance if needed
			if($this->task['user_auto_enhance'] == 1) $original = $this->enhancePhoto($original);
			if($this->watermark_id > 0) $original = $this->watermarkPhoto($original, $this->watermark_id);

			// Check sizes incase watermarking resized images
			$ow = $original->getImageWidth();
			$oh = $original->getImageHeight();		

			if($this->user_id == 1) $this->error('New Process', "Resize 5");

			// Loop through different sizes
			foreach($this->variations as $size => $properties) {



				$bucket = $this->getBucket( ($this->task_type == 'R' ) ? $this->file_backup : $this->task['user_region']);
				$key = $this->getKey($this->task, $size);

			//	$ratio = min($properties['width'] / $w, $properties['height'] / $h);

			//	$width = intval($w * $ratio);
			//	$height = intval($w * $ratio);
				
				$width = $properties['width'];
				$height = $properties['height'];
				
				if($ow <= $width && $oh <= $height){
					
					$width = $ow;
					$height = $oh;
					
				}
				
				$quality = $properties['quality'];
				$aspect = $properties['aspect'];
				$local = "/tmp/".str_replace('/','-',$key);
		
				// Increase quality for screenshots
				if($this->file_upext == 'png') $quality += 5; 
		
			
		
				// Create copy of huge image
				$image = $original->clone();
		
		
				// Resize image
				if( $aspect == 'crop' ){
					$image->cropThumbnailImage( $width, $height ); 
					$image->unsharpMaskImage(0 , .5 , .02 , 0.05);
				} 
				
				if( $aspect == 'scale' ){
					
					// Don't resize if it's at the desired size
					if($width != $ow && $height != $oh){
					
						$image->resizeImage( $width, $height, Imagick::FILTER_LANCZOS, .98, true);
					
					}
					//$image->scaleImage( $width, $height);
					//$image->unsharpMaskImage(0 , .5 , .02 , 0.05);
				}  

				// Add sizes to the array
				$this->sizes['size_'.$size."_w"] = $image->getImageWidth();
				$this->sizes['size_'.$size."_h"] = $image->getImageHeight();

				if($this->user_id == 1) $this->error('New Process', "Resize 6");

				// Save file to local disk
				//if($this->user_id == 1 ) $image->setInterlaceScheme(Imagick::INTERLACE_PLANE);
				$image->setImageCompression(Imagick::COMPRESSION_JPEG);
				$image->setImageCompressionQuality($quality);
				$image->stripImage();
				$image->writeImage($local);
				$image->destroy();

				// Move photo to S3
				if(!$this->putPhoto($local, $bucket, $key)){
					
					// If file couldn't be saved
					unlink($local);	
					$original->destroy();
					return false;				
					
				}

				// delete local file
				unlink($local);		
				
				if($this->file_resize == $size){
					
					$original->destroy();
					return true;
					
				} 		
			
			}
			
			$original->destroy();
			
			return true;
			
		} catch(ImagickException $e){
			
			$original->destroy();
			
			$this->error('Resize Photo', "ImagickException ".$e->getMessage(), $e->getCode(), false);
			
			return false;
			
		}
		
	}
	
	
	
	
	/** 
	 * ------------------------------------------------------------------------------------------
	 * Rotate Photo
	 * ------------------------------------------------------------------------------------------
	 *
	 * Rotates a photo to the specified angle. For most photos it overwrites the existing
	 * file, but writing DNG files is not supported by ImageMagick, so a JPG copy has to 
	 * be created. Thus, the filepath returned will be the same as the file passed in, 
	 * except for DNG files.
	 *
	 * @param string $file Path the file to be rotated
	 * @param string $angle Angle the photo should be rotated
	 * @returns string The path to the rotated photo
	 *
	 **/
	
	function rotatePhoto($file, $angle){

		// Check for DNGs
		if( stripos($file, '.dng') ){
		
			// Create jpg path from dng path
			$jpg = str_replace('.dng', '.jpg', $file);
		
			// Use imagemagick for other files
			$image = new Imagick();
			$image->readImage('dng:'.$file);
			$image->rotateImage( new ImagickPixel(), $angle );
			$image->setImageCompression(Imagick::COMPRESSION_JPEG);
			$image->setImageCompressionQuality(98);
			$image->writeImage($jpg);	
			$image->destroy();		
			
			return $jpg;		

		} 
		
		
		// Check for RAW files
		else if( stripos($file, '.cr2') ){
		
			// Create jpg path from dng path
			$jpg = str_replace('.cr2', '.jpg', $file);
			
			$this->error('Rotate CR2', "$file $angle ", 0, true);

			// Use imagemagick for other files
			$image = new Imagick();
			$image->readImage('cr2:'.$file);
			$image->rotateImage( new ImagickPixel(), $angle );
			$image->setImageCompression(Imagick::COMPRESSION_JPEG);
			$image->setImageCompressionQuality(98);
			$image->writeImage($jpg);	
			$image->destroy();		
			
			return $jpg;		

		}	

		// Check for RAW files
		else if( stripos($file, '.pef') ){
		
			// Create jpg path from dng path
			$jpg = str_replace('.pef', '.jpg', $file);
		
			// Use imagemagick for other files
			$image = new Imagick();
			$image->readImage('pef:'.$file);
			$image->rotateImage( new ImagickPixel(), $angle );
			$image->setImageCompression(Imagick::COMPRESSION_JPEG);
			$image->setImageCompressionQuality(98);
			$image->writeImage($jpg);	
			$image->destroy();		
			
			return $jpg;		

		}

		// Check for RAW files
		else if( stripos($file, '.nef') ){
		
			// Create jpg path from dng path
			$jpg = str_replace('.nef', '.jpg', $file);
		
			$this->error('Rotate NEF', "$file $angle ", 0, true);

			// Use imagemagick for other files
			$image = new Imagick();
			$image->readImage('nef:'.$file);
			$image->rotateImage( new ImagickPixel(), $angle );
			$image->setImageCompression(Imagick::COMPRESSION_JPEG);
			$image->setImageCompressionQuality(98);
			$image->writeImage($jpg);	
			$image->destroy();		
			
			return $jpg;		

		}						
		

		// Check for JPGs
		else if( stripos($file, '.jpg') ){

			// Use JpegTran for loseless rotation
			$result = exec( "jpegtran -copy all -rotate $angle -trim -outfile $file $file" );

			// Reset orientation
			$result = exec( "jpegexiforient -1 $file" );
			
			return $file;

		} 
		
		// Other formats like TIF and PNG
		else {
			
			// Use imagemagick for other files
			$image = new Imagick();
			$image->readImage($file);
			$image->rotateImage( new ImagickPixel(), $angle );
			$image->writeImage();
			$image->destroy();
			
			return $file;
		
		}
		
	}
	
	
	
	
	
	
	function watermarkPhoto($image, $watermark_id){
		
		$result = $this->db->select("SELECT * FROM watermarks WHERE watermark_id = $watermark_id AND user_id = $this->user_id", 'row');

		$bucket = $result['watermark_bucket'];
		$key = $result['watermark_key'];
		$align = $result['watermark_align'];
		$alpha = $result['watermark_alpha'];

		// Retrieve watermark file
		$watermark = $this->getPhoto($bucket, $key);
		
		$overlay = new Imagick();
		$overlay->readImage($watermark);

		$ow =  $overlay->getImageWidth();
		$oh =  $overlay->getImageHeight();

		$iw =  $image->getImageWidth();
		$ih =  $image->getImageHeight();

		// Scale down to fit watermark
		if($iw > 1920 || $ih > 1920){
		
			$image->resizeImage( 1920, 1920, Imagick::FILTER_LANCZOS, .99, true);

			$iw =  $image->getImageWidth();
			$ih =  $image->getImageHeight();			
		
		}

		$x = 0;
		$y = 0;

		// Top Left
		if($align == 1){

			$x = 0;
			$y = 0;

		}

		// Top Centre
		if($align == 2){

			$x = ($iw - $ow) / 2;
			$y = 0;

		}	

		// Top Right
		if($align == 3){

			$x = $iw - $ow;
			$y = 0;

		}				

		// Centre Left
		if($align == 4){

			$x = 0;
			$y = ($ih - $oh) / 2;

		}

		// Centre
		if($align == 5){

			$x = ($iw - $ow) / 2;
			$y = ($ih - $oh) / 2;

		}


		// Middle Right
		if($align == 6){

			$x = $iw - $ow;
			$y = ($ih - $oh) / 2;

		}
		// Bottom Left
		if($align == 7){

			$x = 0;
			$y = $ih - $oh;

		}	

		// Bottom Centre
		if($align == 8){

			$x = ($iw - $ow) / 2;
			$y = $ih - $oh;

		}	

		// Bottom Right
		if($align == 9){

			$x = $iw - $ow;
			$y = $ih - $oh;

		}

					



		// Apply to image in position and at right alpha
		// TL, TR, BL, BR, C
		$image->compositeImage($overlay, Imagick::COMPOSITE_DEFAULT, $x, $y);
		
		$overlay->destroy();

		unlink($watermark);	
		
		// Send the image back 
		return $image;
		
	}
	
	
	function enhancePhoto($image){
					
		$clone = $image->clone();
		$clone->setImageOpacity(0.15);
		$image->compositeImage($clone, Imagick::COMPOSITE_OVERLAY, 0, 0);
		$image->modulateImage(100,110,100);

		$clone->destroy();

		return $image;
		
	}
	


	function getLargestSize($w = 0, $h = 0){
	
		// Go through all sizes
		foreach($this->variations as $size => $properties) {
		
			// Only include the scaled images
			if($properties['aspect'] == 'scale'){
				
				// 
				if($properties['width'] >= $w && $properties['height'] >= $h){
				
					// Make med the minimun size, easier for video
					if($size == 'blog') $size = 'medium';
				
					return $size;
				
				}			
				
			}
		
			

			
		}
		
		return $size;
		
	}
	
	
	function onTimeout(){
		
		if($this->processing){
			
			// Determine duration
			$this->task_status = $this->task_attempt < 3 ? 'W' : 'E';
			$this->task_duration = microtime(true) - $this->task_start;

			// Log this
			$this->error('Script Timeout', "Script timeout while processing task_id $this->task_id\n\nDuration: $this->task_duration", 0, true);
		
			// Update tasks table
			$update = array('task_status' => $this->task_status,
							'task_serviced'	=> $this->id,
							'task_attempt'	=> $this->task_attempt,
							'task_comments' => 'Script timeout',
							'xx_task_completed' => 'CURRENT_TIMESTAMP');
		
			$this->db->update('tasks', $update, "task_id = $this->task_id");		
			
		}

		// Terminate db connect
		$this->db->close();	

	}

	
	function onException($e) {

		if($this->processing){
			
			$this->task_status = $this->task_attempt < 3 ? 'W' : 'E';
			$this->task_duration = microtime(true) - $this->task_start;
			$this->task_comments = 'Exception ' . $e->getMessage();

			// Log this
			$this->error('Script Error', "Exception while processing task_id $this->task_id\n\nDuration: $task_duration\n\nMessage: ".$e->getMessage(), 0, true);
		
			// Update tasks table
			$update = array('task_status' => $this->task_status,
							'task_serviced'	=> $this->id,
							'task_comments' => $this->task_comments,
							'task_attempt'	=> $this->task_attempt,
							'xx_task_completed' => 'CURRENT_TIMESTAMP');
		
			$this->db->update('tasks', $update, "task_id = $this->task_id");		
			
		}	

		// Terminate db connect
		$this->db->close();			
		
	
	}
	

	
		
	
	
	
}

// Start it
new Tasks();


?>
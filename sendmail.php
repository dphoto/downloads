<?php


/* ---------------------------------------
	
SendMail
	
--------------------------------------- */


error_reporting(E_ALL ^ E_NOTICE);

//error_reporting(E_ALL);
//ini_set('display_errors', 1);

// Includes
require_once 'services.php';
//require_once 'phpmailer.php';



class SendMail extends Services{



	function Sendmail(){

		echo " Sendmail ";
		$this->error('Send Mail', "Init sendmail", 0);
		
		// Initialise
		parent::__construct('SendMail');
		
		// 1 minute limit
		set_time_limit(60);

		// Reserve some email tasks	//AND email_from LIKE '%support@dphoto.com%'
		$this->db->update('emails', array('email_status' => $this->id), "email_status='W'  ORDER BY email_id LIMIT 20");

		// Get all emails that have been reserved
		$result = $this->db->select("SELECT * FROM emails WHERE email_status = '$this->id'");

		
		// Process the email one at a time
		while($email = mysql_fetch_assoc($result)){
			
			$this->processAWSMail($email);

		}
		
	}


	private function cleanText($s){
		
		return str_replace("Metadata", "M_D_", $s);
		
	}

	function processAWSMail($email){


		$this->error('Send Mail', "Process AWS mail", 0);
		// Put db data into local vars
		foreach($email as $key => $value) ${$key} = $value;

		$email = new AmazonSES();

		$name = $this->getFromName($email_from);


		$from = "$name <mail@dphoto.com>";

		$to = explode(',', $email_to);
		$reply = $email_from;
		$subject = $email_subject;
		$body = $this->cleanText($email_message);

		// Allow for just a name in the from field..
		if(stripos($email_from, '@') === false) $reply = $from;

		// Just send supprt emails from 1 address
		if(stripos($email_from, 'support@dphoto.com') !== false) $from = "DPHOTO <support@dphoto.com>";

		if($template_id != ''){

			$html = $this->getTemplateHTML($email_id, $user_id);

		} else {

			$html = $this->getHTML($email_html);

		}

		

		// Degfault html text
		if($html == '') $html = str_replace("\n", '<br>', $this->cleanText($email_message));

		// No need to supply reply if same as from
		$param_reply = $reply == $from ? '' : array( 'ReplyToAddresses' => array($reply) );


		$response = $email->send_email(
  			
  			// Source (aka From)
  			$from, 
    		
    		// Destination (aka To)
    		array('ToAddresses' => $to),

    		// Message (long form)
		    array( 
		        'Subject' => array(
		            'Data' => $subject,
		            'Charset' => 'UTF-8'
		        ),
		        'Body' => array(
		            'Text' => array(
		                'Data' => $body,
		                'Charset' => 'UTF-8'
		            ),
		        	'Html' => array(
		        		'Data' => $html,
		        		'Charset' => 'UTF-8'
		        	)
		        )

			),

		    // Optional parameters
			$param_reply
		
		);

		$status = $response->isOK() ? 'S' : 'E';

		$this->db->update('emails', array('email_status' => $status, 'email_serviced' => $this->id), "email_id = $email_id");

		if(!$response->isOK()){
	
			$code = $response->body->Error->Code ;
			$message = $response->body->Error->Message ;
			$this->error('AWS Email', "email_id : $email_id\n\ncode : $code \n\nmessage : $message", null, true);

		}

	}


	function getTemplateHTML($email_id, $user_id){

		return file_get_contents( "http://www.dphoto.com/emails/view.php?format=html&id=" . MD5( $email_id . "-" . $user_id) );

	}
				


	function getHTML($email_html){

		if($email_html != ''){
				
			// Need to URL encode the query string
			if(stripos($email_html, '?') !== false){
				
				$parts = explode('?', $email_html, 2);

				parse_str($parts[1], $vars);
				
				foreach($vars as $i) $vars[$i] = urlencode($i);
				
				$email_html = $parts[0] . '?' . http_build_query($vars);
				
			}
			
			if(stripos($email_html, 'http://') === 0){
				
				$html = file_get_contents($email_html);
				
			} else {
			
				$html = $email_html;
			
			}

			return $html;	

		} else {

			return false;

		}

	}

	

	/**
	 * Determines the best text to use for the from name. Preferably uses the real name 
	 * portion of an address, but will return the email address if that is not available.
	 * @param string $email 
	 * @return string
	 */	

	function getFromName($email){

		$from = $this->splitAddress($email);

		if($from['name'] == ''){

			return $from['address'];

		} else {

			return $from['name'];

		}


	}


	function splitAddress($email){
		
		if(stripos($email, '<') === false){
			
			$address = $email;
			$name = '';
			
		} else {
			
			$address = substr($email, stripos($email, '<')+1, -1);
			
			// Check for quotes around the name
			if(stripos($email, '"') !== false){
				
				// Exclude the quotes
				$name = substr($email, 1, stripos($email, '"', 1)-1);
				
			} else {
				
				// Return name potion of address
				$name = substr($email, 0, stripos($email, '<')-1);
				
			}
			
			
		}
		
		return array('name' => $name, 'address' => $address);
		
	}
	

}

//echo "Started";

// Initiate Class
new SendMail();



?>
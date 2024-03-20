<?php
require __DIR__ . '/vendor/autoload.php';

use Cowsayphp\Farm;

header('Content-Type: text/plain');

$text = "Set a message by adding ?message=<message here> to the URL";
if(isset($_GET['message']) && $_GET['message'] != '') {
	$text = htmlspecialchars($_GET['message']);
}

$cow = Farm::create(\Cowsayphp\Farm\Cow::class);
echo $cow->say($text);

header('Content-type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: PUT, GET, POST");
header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept");

date_default_timezone_set("America/Chicago");

$DB_HOST =  getenv('myDB_HOST');
$DB_USERNAME =  getenv('myDB_USERNAME');
$DB_PASSWORD =  getenv('myDB_PASSWORD');
$DB_NAME =  getenv('myDB_NAME');

#When this page is called using GET method following is executed.
#Parameters must contain zipcode=<zip> procedure in single quote or procedure code in single quotes and finally search_type, by_code and by_name are two valid search types.
if ($_SERVER["REQUEST_METHOD"] == 'GET') {
	# determine type of request this will be
	
	# 1. get procedure cost by cpt code: requires zip code, procedure/cpt code, search_type (must be by_code)
	
	# 2. get procedure cost by procedure exact name: requires zip code, procedure name, search_type (must be by_name)
	
	# 3. get name of all the procedures, in this version, just a wildcard search with %<search text>%, requires procedure name
	global $service_use;
	if (!empty($_GET['zipcode']) && !empty($_GET['procedure']) && !empty($_GET['search_type'])) {
		#debug echo "search type is passed\n";
		if ($_GET['search_type'] == 'by_code') {
			#echo "searching by cpt code #1.\n";
		}
		if ($_GET['search_type'] == 'by_name') {
			#echo "searching by Procedure name #2.\n";
		}
		# set how we are going to get the data needed
		# using the zip code get the wage index multiplier from the database for that zip code.
		$service_use = 'get_costs';
	}
	if (empty($_GET['zipcode']) && !empty($_GET['procedure']) && empty($_GET['search_type'])) {
		#echo "only searching procedures by keyword #3.\n";
		$service_use = 'get_procedures';
	}

	if ($service_use == 'get_procedures') {
		#This section is to obtain Procedure names
		$url_procedure = $_GET['procedure'];

		#echo $url_procedure;
		$procedure_cost_array = FileDetails::getInstance()-> getProcedureNames($url_procedure,$DB_HOST, $DB_USERNAME, $DB_PASSWORD, $DB_NAME);
		if(!$procedure_cost_array) {
			$final_results2 = array();
			#print('procedure not found, please use a valid procedure.');
			$final_results2[0]['pro_cpt'] = '';
			$final_results2[0]['pro_name'] = 'error';
			$final_results2[0]['pro_description'] = 'procedure not found.';
			#print_r($final_results2);
			echo json_encode($final_results2);				
			exit();
		}
	
		#Build a final array to be returned as JSON Object. Calling page/app will see JSON data.
		$final_results = array();

		#debug
		#print_r($procedure_cost_array);
		foreach ($procedure_cost_array as $key => $value) {
			#echo $procedure_cost_array[$key]['pro_cpt'];
			$final_results[$key]['pro_cpt'] = $procedure_cost_array[$key]['pro_cpt'];
			$final_results[$key]['pro_name'] = $procedure_cost_array[$key]['pro_name'];
			$final_results[$key]['pro_description'] = $procedure_cost_array[$key]['pro_description'];
			#print($key);
			#print_r($procedure_cost_array);
			}
		
		#return results
		echo json_encode($final_results);

	}
	if ($service_use == 'get_costs') {
		#This section is to obtain cost by site of service
		$url_search_type = $_GET['search_type'];
		$url_zipcode = $_GET['zipcode'];
		$url_procedure = $_GET['procedure'];
		#instantiate the object and call the method to return Wage Index. it accepts a zip code.
		$wage_index_array = FileDetails::getInstance()-> getWageIndexByZip($url_zipcode, $DB_HOST, $DB_USERNAME, $DB_PASSWORD, $DB_NAME);
		if(!$wage_index_array) {
			$final_results = array('error_message' => 'zip code not found in the database. please use a valid zipcode.');
			echo json_encode($final_results);
			exit();  #zipcode not found, no need to continue, likely cause wrong or unsupported zipcode.
		}
		# since the code is here, that means we have wage index, grab the wage index multiplier and other related information.
		$wage_index_multiplier = $wage_index_array[0]['cw_wage'];
		$wage_index_city = $wage_index_array[0]['cw_city'];
		$wage_index_state = $wage_index_array[0]['cw_state'];
		$wage_index_state_abbreviation = $wage_index_array[0]['cw_abbreviation'];
		
# We have validated a zip code and found wage index and related data, next move on to search for procedure related information.
		#if search is by code, or by_name call the appropriate method.
		if ($url_search_type == 'by_code') { 
			$procedure_cost_array = FileDetails::getInstance()-> getProcedureCostByCode($url_procedure,$DB_HOST, $DB_USERNAME, $DB_PASSWORD, $DB_NAME);
			if(!$procedure_cost_array) {
				#no data back, most likely cpt procedure code wasn't found
				$final_results = array('error_message' => 'procedure code not found, please use a valid code.');
				echo json_encode($final_results);
				exit(); #no procedure found based on code, no need to continue
			}
		}

		if ($url_search_type == 'by_name') { #Not use this right now.
			$procedure_cost_array = FileDetails::getInstance()-> getProcedureCostByName($url_procedure,$DB_HOST, $DB_USERNAME, $DB_PASSWORD, $DB_NAME);
			if(!$procedure_cost_array) {
				#no data back, most likely procedure name wasn't found
				$final_results = array('error_message' => 'procedure name not found, please use a valid procedure.');
				echo json_encode($final_results);				
				exit(); #no procedure found based on procedure name, no need to continue
			}
		}

		#Doctor fee is added to all the Site of Service cost.
		$docotor_fee = $procedure_cost_array[0]['pro_professional'];
		$pro_cpt = $procedure_cost_array[0]['pro_cpt'];
		#Ambulatory cost. if it isn't 0 then calculate the cost.
		if($procedure_cost_array[0]['pro_ambulatory'] >0) {
			$ambulatory = ($procedure_cost_array[0]['pro_ambulatory'] + $docotor_fee) * ($wage_index_multiplier);			
		} else {
			$ambulatory = 0;	
		}

		
		#pro_outpaitient is Hospital cost. if it isn't 0 then calculate the cost.
		if($procedure_cost_array[0]['pro_outpatient'] > 0) {
			$hospital = ($procedure_cost_array[0]['pro_outpatient'] + $docotor_fee) * ($wage_index_multiplier);			
		} else {
			$hospital =0;
		}		
		
		#pro_office is Doctor's Office Cost. if it isn't 0 then calculate the cost.
		if ($procedure_cost_array[0]['pro_office'] > 0 ) {
			$office = ($procedure_cost_array[0]['pro_office'] + $docotor_fee) * ($wage_index_multiplier);
		} else {
			$office = 0;
		}
		


		#Build a final array to be returned as JSON Object. Calling page/app will see JSON data.
		#pro_name, pro_description
		$final_results = array('pro_cpt' => $pro_cpt, 'ambulatory' => $ambulatory, 'hospital' => $hospital, 'office' => $office, 'zip' => $url_zipcode, 'pro_name' => $procedure_cost_array[0]['pro_name'], 'pro_description' => $procedure_cost_array[0]['pro_description'], 'city' => $wage_index_city, 'state' => $wage_index_state, 'state_code' => $wage_index_state_abbreviation);
		echo json_encode($final_results);
	}

}

#This code is executed if this page is called using POST Method.
if ($_SERVER["REQUEST_METHOD"] == 'POST') {
	#echo "POST Method";
	#not being used, leaving it as a place holder for later development.
}

#This code is executed if this page is called using PUT Method.
if ($_SERVER["REQUEST_METHOD"] == 'PUT') {
	#echo "PUT Method";
	#not being used, leaving it as a place holder for later development.
}


#Old Code
#Reads an entire file into a string.
$data = file_get_contents("php://input");
#original code
$objData = json_decode($data);


########################################### Class Definition #######################################
class FileDetails {

    private static $_fileDetails;
    private static $db;

    private function __construct() {
    }

    public static function getInstance() {
        global $_fileDetails;
        if (!isset($_fileDetails)) {
            $_fileDetails = new FileDetails();
        }
        return $_fileDetails;
    }
	

############################# New/Updated Code Starts ###########################
# $mysqli -> new mysqli(host, username, password, dbname, port, socket)
/*
port	Optional. Specifies the port number to attempt to connect to the MySQL server
socket	Optional. Specifies the socket or named pipe to be used
*/
    private function _connect($DB_HOST, $DB_USERNAME, $DB_PASSWORD, $DB_NAME) {
        global $db;
		$db = new mysqli($DB_HOST, $DB_USERNAME, $DB_PASSWORD, $DB_NAME);
    }
	
	//Faisal Z. 6/21/2023
	//added this method to test the webservice connection to MySQL.
	public function getDbVersion($DB_HOST, $DB_USERNAME, $DB_PASSWORD, $DB_NAME){
        global $db;
        $this->_connect($DB_HOST, $DB_USERNAME, $DB_PASSWORD, $DB_NAME);
        $str_sql = 'SELECT VERSION();';
        $result = $db->query($str_sql);
        $rows = array();
        while($rs = $result->fetch_array(MYSQLI_ASSOC)) {
            $rows[] = $rs;
        }
        return $rows;
	}

    public function getProcedureNames($procedure,$DB_HOST, $DB_USERNAME, $DB_PASSWORD, $DB_NAME) {
        global $db;
        $this->_connect($DB_HOST, $DB_USERNAME, $DB_PASSWORD, $DB_NAME);
		#3/15/2024 Faisal
		$newphrase = str_replace("'", '', $procedure);
		$procedure = "'%" . $newphrase . "%'";
        $str_sql = 'SELECT pro_cpt, pro_name, pro_description FROM `tc_procedures` where LOWER(pro_name) LIKE LOWER(' . $procedure . ')';
		
        $result = $db->query($str_sql);
        $rows = array();
		#echo $str_sql;
        while($rs = $result->fetch_array(MYSQLI_ASSOC)) {
            $rows[] = $rs;
        }
        return $rows;
    }	
	

	//Returns the Wage Index.
	public function getWageIndexByZip($zipcode,$DB_HOST, $DB_USERNAME, $DB_PASSWORD, $DB_NAME){
        global $db;
        $this->_connect($DB_HOST, $DB_USERNAME, $DB_PASSWORD, $DB_NAME);
		//$str_sql = 'SELECT * FROM `tc_citywage` WHERE cw_zipcode like \'' . $zipcode . '\'';
        $str_sql = 'SELECT * FROM `citywage` WHERE cw_zipcode like \'' . $zipcode . '\'';
        $result = $db->query($str_sql);
        $rows = array();
        while($rs = $result->fetch_array(MYSQLI_ASSOC)) {
            $rows[] = $rs;
        }
        return $rows;
	}
	
	//Returns the cost by site of service.
	public function getProcedureCostByName($procedure,$DB_HOST, $DB_USERNAME, $DB_PASSWORD, $DB_NAME){
        global $db;
        $this->_connect($DB_HOST, $DB_USERNAME, $DB_PASSWORD, $DB_NAME);
		#LOWER function helps with case sensitive issues.
		$str_sql = 'SELECT pro_cpt, pro_name, pro_description, pro_ambulatory, pro_outpatient, pro_office, pro_professional FROM `tc_procedures` where LOWER(pro_name) LIKE LOWER(' . $procedure . ')';
        $result = $db->query($str_sql);
        $rows = array();
        while($rs = $result->fetch_array(MYSQLI_ASSOC)) {
            $rows[] = $rs;
        }
        return $rows;
	}
	
	//Returns the cost by site of service by code
	public function getProcedureCostByCode($procedure,$DB_HOST, $DB_USERNAME, $DB_PASSWORD, $DB_NAME){
        global $db;
        $this->_connect($DB_HOST, $DB_USERNAME, $DB_PASSWORD, $DB_NAME);
		$str_sql = 'SELECT pro_cpt, pro_name, pro_description, pro_ambulatory, pro_outpatient, pro_office, pro_outpatient, pro_professional FROM `tc_procedures` where pro_cpt=' . $procedure . '';
        $result = $db->query($str_sql);
        $rows = array();
        while($rs = $result->fetch_array(MYSQLI_ASSOC)) {
            $rows[] = $rs;
        }
        return $rows;
	}
}

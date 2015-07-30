<?php
if(!defined('MOODLE_INTERNAL')) exit();
if(!defined('NITROREPORTDF_CONTROL_ACCESS')) exit();

/*	 Get information about moodle user	*/
function nitro_get_user($id)
{
	global $DB;
	$user = $DB->get_record_sql('SELECT username,firstname,lastname,email,institution,department,timecreated,lastlogin,picture FROM {user} WHERE id = '.$id);
	return $user;
}

/*	 Get information about moodle course	*/
function nitro_get_course($id)
{
	global $DB;
	$course = $DB->get_record_sql('SELECT fullname,shortname,timecreated,timemodified FROM {course} WHERE id = '.$id);
	return $course;
}

/*	 Get information about moodle quiz	*/
function nitro_get_quiz($id)
{
	global $DB;
	$quiz = $DB->get_record_sql('SELECT course,name,timeopen,timeclose,timelimit,grademethod,decimalpoints,questiondecimalpoints,sumgrades,intro FROM {quiz} WHERE id = '.$id);
	return $quiz;
}

function nitro_convert_time($time)
{
	$txt='';
	if($time>=31536000):
		$txt.=(floor($time/31536000)).' lat ';
		$time=$time-((floor($time/31536000))*31536000);
	endif;
	if($time>=86400):
		$txt.=(floor($time/86400)).' dni ';
		$time=$time-((floor($time/86400))*86400);
	endif;
	if($time>=3600):
		$txt.=(floor($time/3600)).' h ';
		$time=$time-((floor($time/3600))*3600);
	endif;
	if($time>=60):
		$txt.=(floor($time/60)).' m ';
		$time=$time-((floor($time/60))*60);
	endif;	
	$txt.=$time.' s';
	return $txt;	
}

?>
<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_quiz_nitroreportpdf_uninstall()
{
	global $CFG, $DB, $USER;
	
	function delTree($dir)
	{ 
		$files = array_diff(scandir($dir), array('.','..')); 
		foreach ($files as $file)
		{ 
			if(is_dir("$dir/$file")) ? delTree("$dir/$file") : unlink("$dir/$file"); 
		} 
		return rmdir($dir); 
	} 

	delTree($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache');
	return true;
}
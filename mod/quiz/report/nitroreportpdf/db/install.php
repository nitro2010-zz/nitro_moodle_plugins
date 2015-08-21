<?php
function xmldb_quiz_nitroreportpdf_install()
{
	global $DB, $USER, $CFG;
	$latex_file_server=file_get_contents($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/db/latex_servers.json');
	$latex_file_server=json_decode($latex_file_server);
	foreach($latex_file_server as $id => $lfs_record):
		$qnld_db_exists = $DB->get_record('quiz_nitroreportpdf_latex_db', array('url' => $lfs_record->url,'type' => $lfs_record->type,'format' => $lfs_record->format));
		if(!$qnld_db_exists):
			$record_add = array_combine(array('url','options_url','typesender','format','path','type'),array($lfs_record->url,$lfs_record->options_url,$lfs_record->typesender,$lfs_record->format,$lfs_record->path,$lfs_record->type));
			$DB->insert_record('quiz_nitroreportpdf_latex_db', $record_add, false);
		endif;
	endforeach;
}
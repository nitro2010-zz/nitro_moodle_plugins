<?php
defined('MOODLE_INTERNAL') || die;
global $OUTPUT, $CFG, $DB, $PAGE;
require_once("$CFG->dirroot/mod/quiz/report/nitroreportpdf/lib.php");

$quiz_nitroreportpdf_latex_db_options_type		=	array('HTTP-GET','HTTP-POST');
$quiz_nitroreportpdf_latex_db_options_format	=	array('JPG','GIF','PNG','JSON','XML');


$options_declaration = array();
$options_declaration['DECLARATION_NOTMUSTBE'] 	= get_string('DECLARATION_NOTMUSTBE','quiz_nitroreportpdf');
$options_declaration['DECLARATION_MUSTBE'] 		= get_string('DECLARATION_MUSTBE','quiz_nitroreportpdf');
$options_declaration['DECLARATION_CHOOSE'] 		= get_string('DECLARATION_CHOOSE','quiz_nitroreportpdf');




/////////////////////////// Main Settings ///////////////////////////

$settings->add ( new admin_setting_heading ( 'main_settings', 'Main Settings', '' ) );

$settings->add(new admin_setting_configselect('quiz_nitroreportpdf/declaration',get_string('declaration_page','quiz_nitroreportpdf'),get_string('declaration_desc','quiz_nitroreportpdf'), 'DECLARATION_NOTMUSTBE', $options_declaration));
$settings->add(new admin_setting_confightmleditor('quiz_nitroreportpdf/contact',get_string('contact','quiz_nitroreportpdf'),get_string('contact_desc','quiz_nitroreportpdf'),''));

/////////////////////////// LATEX SETTINGS ///////////////////////////
if(!empty($_POST['url']))
{
	$record = new stdClass();
	$record->url = $_POST['url'];
	$record->options_url = $_POST['options_url'];
	$record->type = $_POST['type'];
	$record->format = $_POST['format'];
	$record->path = $_POST['path'];
	$DB->insert_record('quiz_nitroreportpdf_latex_db', $record, false);
}

if($_GET['action'] == 'delete')
{
	$DB->delete_records('quiz_nitroreportpdf_latex_db',array('id' => $_GET['id']));
}

$str='';
$a = $DB->get_records('quiz_nitroreportpdf_latex_db');
$table = new html_table();
$table->head = array('URL','Dodatkowe opcje','Typ','Format','Ścieżka','Akcje');
foreach($a as $id => $record)
{
	$table->data[] = array($record->url,$record->options_url,$record->type,$record->format,$record->path,$OUTPUT->action_link (new moodle_url ( '/admin/settings.php?section=modsettingsquizcatnitroreportpdf', array ('action' => 'delete', 'id' => $id )),'[Usuń]' ));
}

$option_type='<select name="type">';
foreach($quiz_nitroreportpdf_latex_db_options_type as $option)
	$option_type.='<option value="'.$option.'">'.$option.'</option>';
$option_type.='</select>';

$option_format='<select name="format">';
foreach($quiz_nitroreportpdf_latex_db_options_format as $option)
	$option_format.='<option value="'.$option.'">'.$option.'</option>';
$option_format.='</select>';

$table->data[] = array('<input type="text" name="url">','<input type="text" name="options_url">',$option_type,$option_format,'<input type="text" name="path">');

$str .= html_writer::table($table);

$settings->add ( new admin_setting_heading ( 'latex_settings', 'LaTEX Settings', $str ) );




?>
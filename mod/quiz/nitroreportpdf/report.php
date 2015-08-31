<?php
ini_set('max_execution_time', 0);
ini_set('memory_limit','1024M');

if(!defined('MOODLE_INTERNAL')):
	die(get_string('not_allows','quiz_nitroreportpdf'));
endif;
require_once $CFG->dirroot.'/mod/quiz/report/nitroreportpdf/vendor/autoload.php';
use Peekmo\JsonPath\JsonStore;
	
//MAIN CLASS
class quiz_nitroreportpdf_report extends quiz_default_report
{
	public function display($quiz, $cm, $course) {
		global $CFG, $PAGE, $USER, $OUTPUT;
		//you are log out? LOG IN!
		if(!isloggedin()):
			require_login();
			exit();
		endif;
		$this->print_header_and_tabs($cm, $course, $quiz, 'nitroreportpdf');
		$context = context_module::instance($cm->id);
		try {
			file_put_contents($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.'test.test','this is only test');
		} catch(Exception $ez) {
			print_error('e_nopermission','quiz_nitroreportpdf',new moodle_url('/mod/quiz/report.php?id='.$cm->id.'&mode=nitroreportpdf'));
		}
		require_capability('mod/quiz:viewreports', $context);
		//YOU HAVEN'T REQUIRED PERMISSIONS!
		if(!has_capability('mod/quiz:viewreports', $context)):
			print_error('nocapability','quiz_nitroreportpdf',new moodle_url('/mod/quiz/report.php?id='.$cm->id.'&mode=nitroreportpdf'));
		else:
			//display form with options
			echo '<form action="'.($CFG->wwwroot.'/mod/quiz/report.php').'?id='.$cm->id.'&mode=nitroreportpdf" method="post">';
			echo '<input type="hidden" name="nitro_action" value="1" />';
			echo '<input type="checkbox" name="evaluation_nopart" value="1"';
			if((isset($_POST['evaluation_nopart']))&&($_POST['evaluation_nopart']==1)):
				echo 'checked';
			endif;
			echo ' /> '.get_string('gradeallquestion','quiz_nitroreportpdf').'<br />';
			if(get_config('quiz_nitroreportpdf','declaration') == 'DECLARATION_CHOOSE'):
				echo '<br /><input type="checkbox" name="declaration" value="1"';
				if((isset($_POST['declaration']))&&($_POST['declaration']==1)):
					echo 'checked';
				endif;
				echo ' /> '.get_string('attachdeclaration','quiz_nitroreportpdf').'<br />';
			endif;
			echo '<input type="checkbox" name="show_question_summary" value="1"';
			if((isset($_POST['show_question_summary']))&&($_POST['show_question_summary']==1)):
				echo 'checked';
			endif;
			echo ' /> '.get_string('show_question_summary','quiz_nitroreportpdf').'<br />';
			echo '<input type="checkbox" name="generate_excel_files" value="1"';
			if((isset($_POST['generate_excel_files']))&&($_POST['generate_excel_files']==1)):
				echo 'checked';
			endif;
			echo ' /> '.get_string('generate_excel_files','quiz_nitroreportpdf').'<br />';
			echo '<input id="generate_zip" type="checkbox" name="generate_zip" value="1"';
			if((isset($_POST['generate_zip']))&&($_POST['generate_zip']==1)):
				echo 'checked';
			endif;
			echo ' /> '.get_string('generate_zip','quiz_nitroreportpdf').'<br />';
			echo '<div style="padding-left:30px;"><input id="zip_type" type="radio" name="zip_type" value="online" checked> '.get_string('zip_pack_online','quiz_nitroreportpdf').'<br />';
			if(file_exists($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/nrpdf_prepack.zip')):
				echo '<input id="zip_type" type="radio" name="zip_type" value="offline"> '.get_string('zip_pack_offline','quiz_nitroreportpdf');
			endif;
			echo '</div><br />';
			echo '<br /><br /><input id="nitro_submit" type="submit" value="'.(get_string('generate_pdf','quiz_nitroreportpdf')).'" />';
			echo '</form>';
			//if form send - send option and render PDF file
			if((isset($_POST['nitro_action']))&&($_POST['nitro_action']==1)):
				echo '<script>document.getElementById("nitro_submit").disabled=true;</script>';
				$this->nitro_render_pdf($quiz->id,$USER->id,$cm);
			endif;
			@ob_flush();
			@flush();
		endif; //capability if
		return true;
	}

	//function rendering PDF file (quizid,userid)
	protected function nitro_render_pdf($quizid,$userid,$cm)
	{
		global $CFG, $DB, $PAGE, $OUTPUT, $USER, $SESSION, $CM;
		$PAGE->requires->jquery();
		//disable SUBMIT button after click on this button
		echo '<script>document.getElementById("nitro_submit").disabled=true;</script>';
		//define where is storage WIRIS image
		$WIRIS_URL_IMAGE_SERVICE = $CFG->wwwroot.'/question/type/wq/quizzes/service.php?service=cache&name=';
		//GENERATE HTML FILE? DEFAULT IS FALSE
		$generate_html_file=true;
		$html_contents='';
		//numbers of parts of the report
		$PROGRESSBAR_PARTS=9;
		//get information about quiz, if quiz info is empty - quiz doesn't exists
		$info_quiz=$this->nitro_get_quiz($quizid);
		$this->SetBarWidth(0);
		@ob_flush();
		@flush();
		//context
		$context = context_module::instance($cm->id);
		$contexts_array=explode('/',$context->path);
		unset($contexts_array[0],$contexts_array[1]);
		arsort($contexts_array);
		$contexts_array_tmp='';
		foreach($contexts_array AS $id => $val):
			$contexts_array_tmp.='"'.$val.'",';
		endforeach;
		$contexts_array=substr($contexts_array_tmp,0,-1);
		//context end
		//mode note - 1 - error in all answer, 2 - error half answer
		$MODE_NOTE = 2;
		if((isset($_POST['evaluation_nopart']))&&($_POST['evaluation_nopart'] == 1)):
			$MODE_NOTE = 1;
		endif;
	 	// generate_excel_files
		$GENERATE_EXCEL = false;
		if((isset($_POST['generate_excel_files']))&&($_POST['generate_excel_files']==1)):
			$GENERATE_EXCEL = true;
		endif;	
		//if info quiz is empty, show error
		if(empty($info_quiz)):
			print_error('quizdoesntexists','quiz_nitroreportpdf',new moodle_url('/mod/quiz/report.php?id='.$cm->id.'&mode=nitroreportpdf'));
		else:
			//get info about course
			$info_course=$this->nitro_get_course($info_quiz->course);
			//specifies the number of decimal places. If the quiz that did not specify, the default is the number 4
			$decimalpoints=($info_quiz->decimalpoints >= 0) ? $info_quiz->decimalpoints : 4 ;
			$questiondecimalpoints=($info_quiz->questiondecimalpoints >= 0) ? $info_quiz->questiondecimalpoints : 2 ;
			/*
				quiz array. Specify correct questions & answers.
			*/
			$tab_quiz=array();
			if($GENERATE_EXCEL):
				$objPHPExcel = new PHPExcel();
				$objPHPExcel->getProperties()
				->setCategory("Statistic Report for Moodle Quiz")
				->setCompany("Jarosław Maciejewski")
				->setCreator("Moodle - Quiz Nitro Report PDF module")
				->setLastModifiedBy("Moodle - Quiz Nitro Report PDF module")
				->setTitle("Short statistic of test from Moodle")
				->setSubject("Short statistic of test from Moodle")
				->setDescription("Show statistic report from Moodle Quiz")
				->setKeywords("quiz; report; pdf; statistic;");
			endif;
			//GENERATE PDF
			$mpdf=new mPDF('times');
			$mpdf->useAdobeCJK = true;
			$stylesheet = file_get_contents($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/style.css');
			$mpdf->WriteHTML($stylesheet,1);
			$mpdf->setKeywords('egzamin,moodle,jaroslaw,maciejewski');
			$mpdf->setSubject(get_string('exams_on_moodle','quiz_nitroreportpdf'));
			$mpdf->setCreator(get_string('moodle','quiz_nitroreportpdf'));
			$mpdf->setAuthor('Jarosław Maciejewski');
			$mpdf->SetProtection(array('print','print-highres'));
			$mpdf->setTitle(get_string('exam_result','quiz_nitroreportpdf'));
			// PDF HEADER
			$mpdf->setHTMLHeader('<span style="font-size: 10pt;">'.get_string('protocol_exam','quiz_nitroreportpdf').': '.$info_quiz->name.' '.get_string('of_course','quiz_nitroreportpdf').' '.$info_course->fullname.'</span><hr />');
			// PDF FOOTER
			$mpdf->setHTMLFooter('<hr />
			<table width="100%" border="0">
				<tr>
					<td style="font-size: 10pt;text-align: center;">'.get_string('page','quiz_nitroreportpdf').' {PAGENO}/{nb}</td>
					<td style="font-size: 10pt;text-align: right;width: 20%;">{DATE d.m.Y H:i}</td>
				</tr>
			</table>
			<table width="100%" border="0">
				<tr>
					<td style="font-size: 8pt;text-align: center;">'.get_string('gen_npdf','quiz_nitroreportpdf').'</td>
				</tr>
			</table>');
			$mpdf->setAutoTopMargin='pad';
			$mpdf->setAutoBottomMargin='pad';
			
			//GENERATOR MESSAGE AND PROGRESSBAR
?>
		<link rel="stylesheet" href="<?php echo $CFG->wwwroot.'/mod/quiz/report/nitroreportpdf/css.css'; ?>" />
		<div id="nitroreportpdf_text" style="margin-left: auto; margin-right: auto;text-align: center;">
		<br /><br /><br /><br /><b><?php echo get_string('gen_pleasewait','quiz_nitroreportpdf'); ?></b></div><br />
		<div id="nitroreportpdf_progress" class="nitroreportpdf_graph" style=" margin-left: auto ; margin-right: auto;">
			<div id="nitroreportpdf_bar" style="width:0%">
				<span id="nitroreportpdf_bar_text">0%</span>
			</div>
		</div>
<?php
 /*	========================> 				1. COVER	*/
$this->SetBarWidth(number_format(floor(1*(100/$PROGRESSBAR_PARTS)),2,'.',''));
@ob_flush();
@flush();
$HTML_COVER='
			<br /><br /><br /><br /><br /><br /><br /><br /><br /><br /><br /><br /><br /><br /><br /><br /><br />
			<div style="letter-spacing: 5px;text-align: center;font-weight: bold;font-size: 20pt;text-transform:uppercase;">'.get_string('protocol_exam','quiz_nitroreportpdf').'</div>
			<br /><br /><br />
			<div style="text-align: center;">
			<table border="0" width="100%">
				<tr>
					<th style="text-align: center;font-size: 14pt;">'.get_string('course','quiz_nitroreportpdf').': </th>
					<td style="text-align: center;font-size: 14pt;">'.$info_course->fullname.'</td>
				</tr>
				<tr>
					<th style="text-align: center;font-size: 14pt;">'.get_string('exam','quiz_nitroreportpdf').': </th>
					<td style="text-align: center;font-size: 14pt;">'.$info_quiz->name.'</td>
				</tr>
				<tr>
					<th style="text-align: center;font-size: 14pt;">'.get_string('date','quiz_nitroreportpdf').': </th>
					<td style="text-align: center;font-size: 14pt;">'.date('d.m.Y, H:i').'</td>
				</tr>
			</table>
			</div>
			';
$mpdf->AddPage();
$mpdf->Bookmark('1. '.get_string('cover','quiz_nitroreportpdf'),0);
$mpdf->WriteHTML($HTML_COVER);
if($generate_html_file):
	$html_contents.=$NREQ.'<hr noshade>';
endif;
 /*	========================> 				2. Short info about test	*/
$this->SetBarWidth(number_format(floor(2*(100/$PROGRESSBAR_PARTS)),2,'.',''));
@ob_flush();
@flush();
$timeopen='';
$timeclose='';
if((!empty($info_quiz->timeopen))||($info_quiz->timeopen>0)):
	$timeopen='<tr>
					<th style="text-align: left;">'.get_string('timeopen','quiz_nitroreportpdf').':</th>
					<td style="text-align: left;">'.date('d.m.Y H:i',$info_quiz->timeopen).'</td>
				</tr>';
endif;

if((!empty($info_quiz->timeclose))||($info_quiz->timeclose>0)):
	$timeclose='<tr>
					<th style="text-align: left;">'.get_string('timeclose','quiz_nitroreportpdf').':</th>
					<td style="text-align: left;">'.date('d.m.Y H:i',$info_quiz->timeclose).'</td>
				</tr>';
endif;
if((!empty($info_quiz->timelimit))||($info_quiz->timelimit>0)):
	$nitro_convert_time_s = $this->nitro_convert_time($info_quiz->timelimit);
	$timelimit='<tr>
					<th style="text-align: left;">'.get_string('limittime','quiz_nitroreportpdf').':</th>
					<td style="text-align: left;">'.$nitro_convert_time_s.'</td>
				</tr>';
endif;
switch($info_quiz->grademethod):
	case '2':	$grademethod = get_string('avggrade','quiz_nitroreportpdf'); 		break;
	case '3': 	$grademethod = get_string('firstapproach','quiz_nitroreportpdf'); 	break;
	case '4': 	$grademethod = get_string('lastapproach','quiz_nitroreportpdf'); 	break;
	default: 	$grademethod = get_string('highgrade','quiz_nitroreportpdf'); 		break;
endswitch;
$number_question= $DB->count_records_sql('SELECT count(questionid) FROM {quiz_slots} WHERE quizid="'.$quizid.'"');
$introtest='----';
if(!empty($info_quiz->intro)):	
	if($generate_html_file):
		$introtest=$this->files_from_db_img('mod_quiz','intro',array('extra_sql'=>'AND contextid IN ('.$contexts_array.')'),$info_quiz->intro,true);	
	else:
		$introtest=$this->files_from_db_img('mod_quiz','intro',array('extra_sql'=>'AND contextid IN ('.$contexts_array.')'),$info_quiz->intro);	
	endif;
endif; //quiz intro if
$INTROTEST='<div style="text-align: center;font-weight: bold;font-size:14pt;text-transform:uppercase;">'.get_string('short_info_about_test','quiz_nitroreportpdf').'</div>
			<br /><br />
			 <table border="0" width="100%">
				<tr>
					<th style="text-align: left;width:53%;">'.get_string('nametest','quiz_nitroreportpdf').':</th>
					<td style="text-align: left;">'.$info_quiz->name.'</td>
				</tr>
				<tr>
					<th style="text-align: left;">'.get_string('namecourse','quiz_nitroreportpdf').':</th>
					<td style="text-align: left;">'.$info_course->fullname.'</td>
				</tr>
				<tr>
					<th style="text-align: left;">'.get_string('shortcutcourse','quiz_nitroreportpdf').':</th>
					<td style="text-align: left;">'.$info_course->shortname.'</td>
				</tr>
				<tr>
					<th style="text-align: left;">'.get_string('datecreatecourse','quiz_nitroreportpdf').':</th>
					<td style="text-align: left;">'.date('d.m.Y H:i',$info_course->timecreated).'</td>
				</tr>
				<tr>
					<th style="text-align: left;">'.get_string('datemodifycourse','quiz_nitroreportpdf').':</th>
					<td style="text-align: left;">'.date('d.m.Y H:i',$info_course->timemodified).'</td>
				</tr>
				'.$timeopen.'
				'.$timeclose.'
				'.$timelimit.'
				<tr>
					<th style="text-align: left;">'.get_string('modegradetest','quiz_nitroreportpdf').':</th>
					<td style="text-align: left;">'.$grademethod.'</td>
				</tr>
				<tr>
					<th style="text-align: left;">'.get_string('sumpoints','quiz_nitroreportpdf').':</th>
					<td style="text-align: left;">'.$decimalpoints.'</td>
				</tr>
				<tr>
					<th style="text-align: left;">'.get_string('gradequestion','quiz_nitroreportpdf').':</th>
					<td style="text-align: left;">'.$questiondecimalpoints.'</td>
				</tr>
				<tr>
					<th style="text-align: left;">'.get_string('maxpoints','quiz_nitroreportpdf').':</th>
					<td style="text-align: left;">'.number_format($info_quiz->sumgrades,$decimalpoints,".","").'</td>
				</tr>
				<tr>
					<th style="text-align: left;">'.get_string('numquestions','quiz_nitroreportpdf').':</th>
					<td style="text-align: left;">'.$number_question.'</td>
				</tr>
				<tr>
					<th style="text-align: left;">'.get_string('intrototest','quiz_nitroreportpdf').':</th>
					<td style="text-align: left;">'.$introtest.'</td>
				</tr>
			</table>
			';
$mpdf->AddPage();
$mpdf->Bookmark('2. '.get_string('short_info_about_test','quiz_nitroreportpdf'),0);
$mpdf->WriteHTML($INTROTEST);
if($generate_html_file):
	$html_contents.=$INTROTEST.'<hr noshade>';
endif;
 /*	========================> 				3. Correct filled test 				*/
$this->SetBarWidth(number_format(floor(3*(100/$PROGRESSBAR_PARTS)),2,'.',''));
@ob_flush();
@flush();
$mpdf->AddPage();
$mpdf->Bookmark('3. '.get_string('correctfilltest','quiz_nitroreportpdf'),0);	
$CORRECT_FILLED_TEST_INTRO='<p style="text-align: center;font-weight: bold;font-size:14pt;text-transform:uppercase;">'.get_string('questionandanswer','quiz_nitroreportpdf').'</p><p></p>';		
$mpdf->WriteHTML($CORRECT_FILLED_TEST_INTRO);
if($generate_html_file):
	$html_contents.=$CORRECT_FILLED_TEST_INTRO.'<hr noshade>';
endif;
//get questions from quiz
$questions = $DB->get_records_sql('SELECT qs.id AS id,qs.maxmark AS q_grade,q.questiontext AS q_text,q.qtype AS q_type,qs.questionid AS q_idq FROM {quiz_slots} qs,{question} q WHERE qs.quizid='.$quizid.' AND qs.questionid=q.id AND q.parent=0 ORDER BY qs.questionid ASC');
$tab_correct_answers=array();
$nr_question=1;	
foreach($questions AS $q):
	$tab_correct_answers[]=number_format($q->q_grade,$questiondecimalpoints,".","");
	$mpdf->Bookmark($nr_question.'. '.get_string('question2','quiz_nitroreportpdf'),1);
	/* question text */	
	if($generate_html_file):
		$q_text = $this->files_from_db_img('question','questiontext',array('extra_sql'=>' AND itemid="'.$q->q_idq.'"'),$q->q_text,true);
	else:
		$q_text = $this->files_from_db_img('question','questiontext',array('extra_sql'=>' AND itemid="'.$q->q_idq.'"'),$q->q_text);
	endif;
	$tab_quiz[$q->q_idq]['qid']=$q->q_idq;
	$tab_quiz[$q->q_idq]['question']=$q_text;
	$tab_quiz[$q->q_idq]['type']=$q->q_type;
	switch($q->q_type):
		case 'truefalse':
			$truefalse='0';
			//get correct answer TRUE OR FALSE on question
			$question_truefalse_db_true = $DB->get_record_sql('SELECT qa.fraction AS fraction FROM {question_answers} qa, {question_truefalse} qtf WHERE qtf.question="'.$q->q_idq.'" AND qtf.trueanswer=qa.id');
			//get TRUE in language . this variable is use later.
			$tf_sql_true = $DB->get_record_sql('SELECT qa.answer AS answer FROM {question_truefalse} qt, {question_answers} qa WHERE qa.question="'.$q->q_idq.'" AND qt.question="'.$q->q_idq.'" AND qt.trueanswer=qa.id');
			//get FALSE in language . this variable is use later.
			$tf_sql_false = $DB->get_record_sql('SELECT qa.answer AS answer FROM {question_truefalse} qt, {question_answers} qa WHERE qa.question="'.$q->q_idq.'" AND qt.question="'.$q->q_idq.'" AND qt.falseanswer=qa.id');
			//if answer has fraction equals more than 1 - its correct answer
			if($question_truefalse_db_true->fraction>=1):
				$truefalse='1';
			else:
				$truefalse='0';
			endif;
			$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nr_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_truefalse').' ('.number_format($q->q_grade,$questiondecimalpoints,".","").' '.get_string('points_short','quiz_nitroreportpdf').')</td></tr></table><br />'.$q_text.'<br /><u>'.get_string('answer','quiz_nitroreportpdf').':</u> <span style="color:blue;font-weight: bold;">'.(($truefalse==0) ? $tf_sql_false->answer : $tf_sql_true->answer).'</span>';
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
			$tab_quiz[$q->q_idq]['answers'][]=$truefalse;
			$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade,$questiondecimalpoints,".","");
		break;

		case 'numerical':
			$numericalA = $DB->get_record_sql('SELECT showunits,unitsleft,unitgradingtype,unitpenalty FROM {question_numerical_options} WHERE question="'.$q->q_idq.'"');
			$numericalD = $DB->get_record_sql('SELECT id FROM {question_answers} WHERE question="'.$q->q_idq.'" AND fraction>0 ORDER BY id ASC LIMIT 0,1');
			$numericalB = $DB->get_records_sql('SELECT qa.id AS id,qa.answer AS answer,qn.tolerance AS tolerance,qa.fraction AS fraction FROM {question_answers} qa, {question_numerical} qn WHERE qa.question="'.$q->q_idq.'" AND qa.id=qn.answer AND qa.fraction>0 ORDER BY qa.id ASC');
			$numericalCC = $DB->get_record_sql('SELECT id FROM {question_numerical_units} WHERE question="'.$q->q_idq.'" ORDER BY id LIMIT 0,1');
			$numericalC = $DB->get_records_sql('SELECT id,multiplier,unit FROM {question_numerical_units} WHERE question="'.$q->q_idq.'" ORDER BY id');
			switch($numericalA->showunits):
				case 0:
				case 1:
				case 2:
					$tab_correct=array();
					foreach($numericalB as $answers):
						if($numericalA->unitgradingtype == 1):
							$pkt_unitgradetype=number_format($q->q_grade*$answers->fraction*$numericalA->unitpenalty,$questiondecimalpoints,".","");
							if($numericalA->unitpenalty <= 0 ):
								$pkt_unitgradetype=number_format($q->q_grade*$answers->fraction,$questiondecimalpoints,".","");
							endif;
						elseif($numericalA->unitgradingtype == 2):
							$pkt_unitgradetype=number_format($q->q_grade*$answers->fraction-$numericalA->unitpenalty,$questiondecimalpoints,".","");
						endif;
						$tab_correct[]=array('answer'=>$answers->answer,'pkt'=>$pkt_unitgradetype,'type'=>'S');
						$tab_quiz[$q->q_idq]['answers'][]=$answers->answer;
						$tab_quiz[$q->q_idq]['points'][]=$pkt_unitgradetype;
						if($answers->tolerance>0):
							$a=$answers->answer - $answers->tolerance;
							$b=$answers->answer + $answers->tolerance;
							$tab_correct[]=array('answer'=>$a.' - '.$b,'pkt'=>$pkt_unitgradetype,'type'=>'P');
							$tab_quiz[$q->q_idq]['answers'][]=$a.'-'.$b;
							$tab_quiz[$q->q_idq]['points'][]=$pkt_unitgradetype;
						endif;
						if(count($numericalC)>0):
							foreach($numericalC as $units):
								if($numericalA->unitsleft==0):
									$tab_correct[]=array('answer'=>($answers->answer*$units->multiplier).$units->unit,'pkt'=>number_format($q->q_grade*$answers->fraction,$questiondecimalpoints,".",""),'type'=>'S');
									$tab_quiz[$q->q_idq]['answers'][]=($answers->answer*$units->multiplier).'|'.$units->unit;
									$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade*$answers->fraction,$questiondecimalpoints,".","");
								else:
									$tab_correct[]=array('answer'=>$units->unit.($answers->answer*$units->multiplier),'pkt'=>number_format($q->q_grade*$answers->fraction,$questiondecimalpoints,".",""),'type'=>'S');
									$tab_quiz[$q->q_idq]['answers'][]=$units->unit.'|'.($answers->answer*$units->multiplier);
									$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade*$answers->fraction,$questiondecimalpoints,".","");
								endif;
								if(($units->id==$numericalCC->id)&&($answers->tolerance>0)):
									if($numericalA->unitsleft==0):
										$tab_correct[]=array('answer'=>$a.' - '.$b.$units->unit,'pkt'=>number_format($q->q_grade*$answers->fraction,$questiondecimalpoints,".",""),'type'=>'P');
										$tab_quiz[$q->q_idq]['answers'][]=$a.'-'.$b.'|'.$units->unit;
										$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade*$answers->fraction,$questiondecimalpoints,".","");
									else:
										$tab_correct[]=array('answer'=>$units->unit.$a.' - '.$b,'pkt'=>number_format($q->q_grade*$answers->fraction,$questiondecimalpoints,".",""),'type'=>'P');
										$tab_quiz[$q->q_idq]['answers'][]=$units->unit.'|'.$a.'-'.$b;
										$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade*$answers->fraction,$questiondecimalpoints,".","");
									endif;
								endif;
								if(($units->id!=$numericalCC->id)&&($answers->tolerance>0)):
									$tab_correct[]=array('answer'=>($a*$units->multiplier).' - '.($b*$units->multiplier),'pkt'=>$pkt_unitgradetype,'type'=>'P');
									$tab_quiz[$q->q_idq]['answers'][]=($a*$units->multiplier).'-'.($b*$units->multiplier).'|';
									$tab_quiz[$q->q_idq]['points'][]=$pkt_unitgradetype;
									if($numericalA->unitsleft==0):
										$tab_correct[]=array('answer'=>($a*$units->multiplier).' - '.($b*$units->multiplier).$units->unit,'pkt'=>number_format($q->q_grade*$answers->fraction,$questiondecimalpoints,".",""),'type'=>'P');
										$tab_quiz[$q->q_idq]['answers'][]=($a*$units->multiplier).'-'.($b*$units->multiplier).'|'.$units->unit;
										$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade*$answers->fraction,$questiondecimalpoints,".","");
									else:
										$tab_correct[]=array('answer'=>$units->unit.($a*$units->multiplier).' - '.($b*$units->multiplier),'pkt'=>number_format($q->q_grade*$answers->fraction,$questiondecimalpoints,".",""),'type'=>'P');
										$tab_quiz[$q->q_idq]['answers'][]=$units->unit.'|'.($a*$units->multiplier).'-'.($b*$units->multiplier);
										$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade*$answers->fraction,$questiondecimalpoints,".","");
									endif;
								endif;
							endforeach;
						endif; // if numericalC end
					endforeach; //foreach answers
				break;
				default:
					foreach($numericalB as $answers):
						$tab_correct[]=array('answer'=>$answers->answer,'pkt'=>number_format($q->q_grade*$answers->fraction,$questiondecimalpoints,".",""));
						$tab_quiz[$q->q_idq]['answers'][]=$answers->answer;
						$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade*$answers->fraction,$questiondecimalpoints,".","");
						if($answers->tolerance>0):
							$tab_correct[]=array('answer'=>($answers->answer-$answers->tolerance).' - '.($answers->answer+$answers->tolerance),'pkt'=>number_format($q->q_grade*$answers->fraction,$questiondecimalpoints,".",""));
							$tab_quiz[$q->q_idq]['answers'][]=($answers->answer-$answers->tolerance).'-'.($answers->answer+$answers->tolerance);
							$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade*$answers->fraction,$questiondecimalpoints,".","");
						endif;
					endforeach;
				break;
			endswitch; // switch ShowUnits
			for($i=0;$i<count($tab_correct);$i++)
				if($tab_correct[$i]['pkt'] < 0)
					$tab_correct[$i]['pkt']=number_format(0,$questiondecimalpoints,".","");
			for($i=0;$i<count($tab_quiz[$q->q_idq]['points']);$i++)
				if($tab_quiz[$q->q_idq]['points'] < 0)
					$tab_quiz[$q->q_idq]['points']=number_format(0,$questiondecimalpoints,".","");
			foreach($tab_correct AS $id => $tab):
				$u_answer[$id] = $tab['answer'];
				$u_pkt[$id] = $tab['pkt'];
				$u_type[$id] = $tab['type'];
			endforeach;
			array_multisort($u_type, SORT_DESC, $u_pkt, SORT_DESC, $u_answer, SORT_STRING,SORT_ASC, $tab_correct);
			$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nr_question.'.</b></td><td style="text-align: right;">'.get_string('pluginname','qtype_numerical').' ('.number_format($q->q_grade,$questiondecimalpoints,".","").' '.get_string('points_short','quiz_nitroreportpdf').')</td></tr></table><br />'.$q_text.'<br /><u>'.get_string('answers','quiz_nitroreportpdf').':</u><br /><br /><table border="0" style="margin-left: 0%; margin-right: 0%;" class="table"><tr><th style="text-transform:capitalize;">'.get_string('points_short','quiz_nitroreportpdf').'.</th><th>'.get_string('answer','quiz_nitroreportpdf').'</th></tr>';
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;			
			for($i=0;$i<count($tab_correct);$i++):
				$NREQ='<tr><td style="text-align: center;">'.$tab_correct[$i]['pkt'].'</td><td>'.$tab_correct[$i]['answer'].'</td></tr>';
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;				
			endfor;
			$answer2='';
			$l=1;
			foreach($numericalB AS $ans0):
				$answer2.='- '.get_string('main_answer','quiz_nitroreportpdf').' '.$l.': '.$ans0->answer.', '.get_string('error_deviation','quiz_nitroreportpdf').':	'.$ans0->tolerance.', '.get_string('points_short','quiz_nitroreportpdf').'. '.number_format($q->q_grade*$ans0->fraction,2,'.','').'<br />';
				$l++;
			endforeach;
			$l=1;
			foreach($numericalC AS $ans1):
				$answer2.='- '.get_string('unit','quiz_nitroreportpdf').' '.$l.': '.$ans1->unit.', '.get_string('multiplier','quiz_nitroreportpdf').': '.$ans1->multiplier.'<br />';
				$l++;
			endforeach;
			if(in_array($numericalA->showunits,array(0,1,2))):
				$answer2.='- '.get_string('error_reduction','quiz_nitroreportpdf').' '.$numericalA->unitpenalty.' '.get_string('points_as_fraction','quiz_nitroreportpdf').' ';
				if($numericalA->unitgradingtype == 1):
					$answer2.=get_string('given_answer','quiz_nitroreportpdf');
				elseif($numericalA->unitgradingtype == 2):
					$answer2.=get_string('question2','quiz_nitroreportpdf');
				endif;
				$answer2.='<br />- '.get_string('unitafter','quiz_nitroreportpdf').' ';
				if($numericalA->unitsleft==0):
					$answer2.=get_string('right','quiz_nitroreportpdf');
				else:
					$answer2.=get_string('left','quiz_nitroreportpdf');
				endif;
				$answer2.=' '.get_string('numberstr','quiz_nitroreportpdf').'<br />';
			endif;
			$NREQ='</table><br /><u>'.get_string('othersprops','quiz_nitroreportpdf').':</u> <br />'.$answer2;
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
		break;

		case 'gapselect':
			$question_gapselect = $DB->get_records_sql('SELECT id,answer FROM {question_answers} WHERE question="'.$q->q_idq.'" ORDER BY id ASC');
			$tab_temp=array();
			foreach($question_gapselect AS $answers):
				$tab_temp[]=$answers->answer;
			endforeach;
			preg_match_all('/\[\[([0-9]+)\]\]/',$q_text,$ZN);
			$tab_quiz[$q->q_idq]['answers']=$ZN[1];
			$tab_quiz[$q->q_idq]['choices']=$tab_temp;
			for($i=0;$i<count($tab_temp);$i++):
				$q_text=preg_replace('/\[\['.($i+1).'\]\]/','<span style="color:blue;font-weight: bold;">'.$tab_temp[$i].'</span>',$q_text);
			endfor;
			$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nr_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_gapselect').' ('.number_format($q->q_grade,$questiondecimalpoints,".","").' '.get_string('points_short','quiz_nitroreportpdf').')</td></tr></table><br />'.$q_text;
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
			$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade,$questiondecimalpoints,".","");
		break;
		
		case 'ddimageortext':
			$data = null;
			$bigfile_details = $DB->get_record_sql('SELECT contextid,filepath,filename,filesize,timecreated,timemodified,contenthash FROM {files} WHERE component="qtype_ddimageortext" AND filearea="bgimage" AND itemid="'.$q->q_idq.'" AND mimetype<>"" AND filename<>"."');
			$filename=hash('sha384',"qtype_ddimageortextbgimage".$bigfile_details->filesize.$bigfile_details->timecreated.$bigfile_details->timemodified.$bigfile_details->contenthash.$bigfile_details->filepath.$bigfile_details->filename).'.'.pathinfo($bigfile_details->filename)['extension'];
			if(!file_exists($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$filename)):
				$fs = null;
				$file_big = null;
				$fs_big = get_file_storage();
				$file_big = $fs_big->get_file($bigfile_details->contextid, 'qtype_ddimageortext', 'bgimage', $q->q_idq, $bigfile_details->filepath,$bigfile_details->filename);
				if($file_big):
					$file_big->copy_content_to($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$filename);
				endif; // file
			endif;
			touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$filename);
			$data['ddimageortext_bigfile']=$filename;
			$tab_quiz[$q->q_idq]['ddimageortext_bigfile']=$filename;
			$tab_text=array();
			$tab_image=array();
			$dd_files = $DB->get_records_sql('SELECT id,no,label FROM {qtype_ddimageortext_drags} WHERE questionid="'.$q->q_idq.'" ORDER BY no ASC');
			$dd_files_ids=array_keys($dd_files);
			for($z=0;$z<count($dd_files_ids);$z++):
				$dd_files2 = $DB->get_record_sql('SELECT no,xleft,ytop,choice FROM {qtype_ddimageortext_drops} WHERE questionid="'.$q->q_idq.'" AND no="'.$dd_files[$dd_files_ids[$z]]->no.'" ORDER BY choice ASC');
				if(isset($dd_files2->no)):
					$dd_filesA = $DB->get_record_sql('SELECT f.id AS f_id,f.contextid AS f_contexid,f.filepath AS f_filepath,f.filename AS f_filename,f.filesize AS f_filesize,f.timecreated AS f_timecreated,f.timemodified AS f_timemodified,f.contenthash AS f_contenthash FROM {files} f WHERE f.itemid="'.$dd_files_ids[$z].'" AND contextid="'.$bigfile_details->contextid.'" AND f.component="qtype_ddimageortext" AND f.filearea="dragimage" AND f.mimetype<>"" AND filename<>"."');
					$filename=hash('sha384',"qtype_ddimageortextdragimage".$dd_filesA->f_filesize.$dd_filesA->f_timecreated.$dd_filesA->f_timemodified.$dd_filesA->f_contenthash.$dd_filesA->f_filepath.$dd_filesA->f_filename).'.'.pathinfo($dd_filesA->f_filename)['extension'];
					if(!empty($dd_filesA->f_id)):	
						if(!file_exists($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$filename)):	
							$fs = null;
							$mfile = null;
							$fs = get_file_storage();
							$mfile = $fs->get_file($dd_filesA->f_contexid,'qtype_ddimageortext','dragimage',$dd_files_ids[$z],$dd_filesA->f_filepath,$dd_filesA->f_filename);
							if($mfile):
								$mfile->copy_content_to($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$filename);
							endif;		
						endif;
						touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$filename);
						$tab_image[]=array('x'=>$dd_files2->xleft,'y'=>$dd_files2->ytop,'filename'=>$filename);
						$tab_quiz[$q->q_idq]['answers'][$dd_files[$dd_files_ids[$z]]->no-1]=array('lab_img' => $filename,'type' => 'image','choice' => $dd_files[$dd_files_ids[$z]]->no,'x' => $dd_files2->xleft,'y' => $dd_files2->ytop);
					else:
						$tab_text[]=array('x'=>$dd_files2->xleft,'y'=>$dd_files2->ytop,'text'=>$dd_files[$dd_files_ids[$z]]->label);
						$tab_quiz[$q->q_idq]['answers'][$dd_files[$dd_files_ids[$z]]->no-1]=array('lab_img' => $dd_files[$dd_files_ids[$z]]->label,'type' => 'text','choice' => $dd_files[$dd_files_ids[$z]]->no,'x' => $dd_files2->xleft,'y' => $dd_files2->ytop);
					endif;
				else:
					$dd_filesA = $DB->get_record_sql('SELECT f.id AS f_id,f.contextid AS f_contexid,f.filepath AS f_filepath,f.filename AS f_filename,f.filesize AS f_filesize,f.timecreated AS f_timecreated,f.timemodified AS f_timemodified,f.contenthash AS f_contenthash FROM FROM {files} f WHERE f.itemid="'.$dd_files_ids[$z].'" AND contextid="'.$bigfile_details->contextid.'" AND f.component="qtype_ddimageortext" AND f.filearea="dragimage" AND f.mimetype<>"" AND filename<>"."');
					$filename=hash('sha384',"qtype_ddimageortextdragimage".$dd_filesA->f_filesize.$dd_filesA->f_timecreated.$dd_filesA->f_timemodified.$dd_filesA->f_contenthash.$dd_filesA->f_filepath.$dd_filesA->f_filename).'.'.pathinfo($dd_filesA->f_filename)['extension'];
					if(!empty($dd_filesA->f_id)):
						if(!file_exists($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$filename)):	
							$fs = null;
							$mfile = null;
							$fs = get_file_storage();
							$mfile = $fs->get_file($dd_filesA->f_contexid,'qtype_ddimageortext','dragimage',$dd_files_ids[$z],$dd_filesA->f_filepath,$dd_filesA->f_filename);
							if($mfile):
								$mfile->copy_content_to($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$tempfilename);
							endif;
						endif;
						touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$filename);
						$tab_image[]=array('x'=>-1000,'y'=>-1000,'filename'=>$tempfilename);
						$tab_quiz[$q->q_idq]['answers'][$dd_files[$dd_files_ids[$z]]->no-1]=array('lab_img' => $tempfilename,'type' => 'image','choice' => $dd_files[$dd_files_ids[$z]]->no,'x' => -1000,'y' => -1000);
					else:
						$tab_text[]=array('x'=>-1000,'y'=>-1000,'text'=>$dd_files[$dd_files_ids[$z]]->label);
						$tab_quiz[$q->q_idq]['answers'][$dd_files[$dd_files_ids[$z]]->no-1]=array('lab_img' => $dd_files[$dd_files_ids[$z]]->label,'type' => 'text','choice' => $dd_files[$dd_files_ids[$z]]->no,'x' => -1000,'y' => -1000);
					endif;
				endif; // if exists more some are unused
			endfor;
			$data['texts']=json_encode($tab_text);
			$data['images']=json_encode($tab_image);
			$data['filename']='_U'.$userid.'_Q'.$quizid.'_'.strtotime('now').uniqid().uniqid().'.jpg';
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL,$CFG->wwwroot.'/mod/quiz/report/nitroreportpdf/image.php');
			curl_setopt($ch, CURLOPT_HEADER,0);
			curl_setopt($ch, CURLOPT_POST,1);
			curl_setopt($ch, CURLOPT_TIMEOUT,60);
			curl_setopt($ch, CURLOPT_POSTFIELDS,$data);
			curl_exec($ch);
			curl_close($ch);
			$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nr_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_ddimageortext').' ('.number_format($q->q_grade,$questiondecimalpoints,".","").' '.get_string('points_short','quiz_nitroreportpdf').')</td></tr></table><br />'.$q_text.'<br /><img src="report/nitroreportpdf/cache/'.$data['filename'].'" />';
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
			$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade,$questiondecimalpoints,".","");
		break;

		case 'multianswer':
			$question_multianswer = $DB->get_records_sql('SELECT id,questiontext FROM {question} WHERE parent="'.$q->q_idq.'" ORDER BY id ASC');
			$i=0;
			foreach($question_multianswer AS $id => $multianswer):
				$getanswer=$this->nitro_get_multianswer_correct_answer($multianswer->questiontext);
				$tab_quiz[$q->q_idq]['answers'][]=$getanswer;
				if(count($getanswer['answers']) > 1):
					for($l=0;$l<count($getanswer['answers']);$l++):
						$points=$getanswer['points'][$l];
						if(empty($points)):
							$points=0;
						endif;
						if($l == $getanswer['correct']):
							$correct_answer.='<span style="color:blue;font-weight: bold;">'.$getanswer['answers'][$l].' ('.$points.' '.get_string('points_short','quiz_nitroreportpdf').')</span>, ';
						else:
							$correct_answer.=$getanswer['answers'][$l].' ('.$points.' '.get_string('points_short','quiz_nitroreportpdf').')</span>, ';
						endif;
					endfor;
					$correct_answer='['.substr($correct_answer,0,-2).']';
				else:
					$correct_answer='<span style="color:blue;font-weight: bold;">'.$getanswer['answers'][0].' ('.$points.' '.get_string('points_short','quiz_nitroreportpdf').')</span>';
				endif;
				$q_text=preg_replace('/{#'.$i.'}/',$correct_answer,$q_text);
				$question_multianswer_resp = $DB->get_records_sql('SELECT id FROM {question_answers} WHERE question="'.$id.'" ORDER BY id ASC');
				$j=0;			
				foreach($question_multianswer_resp AS $res):	
					$tab_quiz[$q->q_idq]['answers'][$i]['answers_id'][$res->id]=$j;
					$j++;	
				endforeach;	
				$i++;
			endforeach;	
			$tab_quiz[$q->q_idq]['question_with_answers']=$q_text;
			$tab_quiz[$q->q_idq]['points'][0]=number_format($q->q_grade,$questiondecimalpoints,".","");
			if($MODE_NOTE == 2):
				$points=0;
				$i=0;
				for($z=0;$z<count($tab_quiz[$q->q_idq]['answers']);$z++):
					$points+=$tab_quiz[$q->q_idq]['answers'][$z]['points'][$tab_quiz[$q->q_idq]['answers'][$z]['correct']];
				endfor;
				$tab_quiz[$q->q_idq]['points'][0]=number_format($points,$questiondecimalpoints,".","");
			endif;
			$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nr_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_multianswer').' ('.$tab_quiz[$q->q_idq]['points'][0].' '.get_string('points_short','quiz_nitroreportpdf').')</td></tr></table><br />'.$q_text;
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
		break;

		case 'ddwtos':
			$ddwtos_answers = $DB->get_records_sql('SELECT id,answer FROM {question_answers} WHERE question="'.$q->q_idq.'" ORDER BY id ASC');
			$answer_nb=1;
			foreach($ddwtos_answers AS $ddwtos_answers):
				$q_text=preg_replace('/\[\['.$answer_nb.'\]\]/','<span style="color:blue;font-weight: bold;">'.$ddwtos_answers->answer.'</span>',$q_text);
				$tab_quiz[$q->q_idq]['answers'][$answer_nb]=$ddwtos_answers->answer;
				$answer_nb++;
			endforeach;
			$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nr_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_ddwtos').' ('.number_format($q->q_grade,$questiondecimalpoints,".","").' '.get_string('points_short','quiz_nitroreportpdf').')</td></tr></table><br />'.$q_text;
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
			$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade,$questiondecimalpoints,".","");
		break;

		case 'match':
			$match_answers = $DB->get_records_sql('SELECT id,questiontext,answertext FROM {qtype_match_subquestions} WHERE questionid="'.$q->q_idq.'" ORDER BY id ASC');
			$answers_tab='';
			foreach($match_answers AS $match_answers):
				if($generate_html_file):
					$question=$this->files_from_db_img('qtype_match','subquestion',array('extra_sql'=>' AND itemid="'.$match_answers->id.'" AND contextid IN ('.$contexts_array.')'),$match_answers->questiontext,true);
				else:
					$question=$this->files_from_db_img('qtype_match','subquestion',array('extra_sql'=>' AND itemid="'.$match_answers->id.'" AND contextid IN ('.$contexts_array.')'),$match_answers->questiontext);
				endif;
				$answer=$match_answers->answertext;
				$tab_quiz[$q->q_idq]['answers'][$match_answers->id]=array('question'=>$question,'answer'=>$answer);
				$answers_tab.='<tr><td>'.$question.'</td><td>'.$answer.'</td></tr>';
			endforeach;  //if are some file to process
			$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nr_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_match').' ('.number_format($q->q_grade,$questiondecimalpoints,".","").' '.get_string('points_short','quiz_nitroreportpdf').')</td></tr></table><br />'.$q_text.'<br /><u>'.get_string('answers','quiz_nitroreportpdf').':</u><br /><br /><table border="1"><tr><th>'.get_string('question2','quiz_nitroreportpdf').'</th><th>'.get_string('answer','quiz_nitroreportpdf').'</th></tr>'.$answers_tab.'</table>';
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
			$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade,$questiondecimalpoints,".","");
		break;

		case 'multichoice':
			$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade,$questiondecimalpoints,".","");
			$answers_db = $DB->get_records_sql('SELECT id,answer,fraction FROM {question_answers} WHERE question="'.$q->q_idq.'"');
			$multi_tb = $DB->get_record_sql('SELECT single FROM {qtype_multichoice_options} WHERE questionid="'.$q->q_idq.'"');
			if($multi_tb->single==1):
				$type_q=get_string('questiontypemultichoiceone','quiz_nitroreportpdf');
			else:
				$type_q=get_string('questiontypemultichoicemulti','quiz_nitroreportpdf');
			endif;
			$NREQ='<table border="0" style="width: 100%;"><tr><td><b>'.$nr_question.'.</b></td><td style="text-align: right;">'.$type_q.' ('.number_format($q->q_grade,$questiondecimalpoints,".","").' '.get_string('points_short','quiz_nitroreportpdf').')</td></tr></table><br />'.$q_text.'<br /><u>'.get_string('answers','quiz_nitroreportpdf').':</u> <br /><br />';
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
			$nr_answer=1;
			foreach($answers_db AS $answer):
				if($generate_html_file):
					$answer_txt=$this->files_from_db_img('question','answer',array('extra_sql'=>' AND itemid="'.$answer->id.'" AND contextid IN ('.$contexts_array.')'),$answer->answer,true);
				else:
					$answer_txt=$this->files_from_db_img('question','answer',array('extra_sql'=>' AND itemid="'.$answer->id.'" AND contextid IN ('.$contexts_array.')'),$answer->answer);
				endif;
				$corr='';
				$tab_quiz[$q->q_idq]['qanswers'][$answer->id]=0;
				if(($multi_tb->single == 1) && ($answer->fraction>=1)):
					$corr='<span style="color: blue;"><b>[X]</b></span> ';
					$tab_quiz[$q->q_idq]['qanswers'][$answer->id]=1;
				endif;
				if(($multi_tb->single == 0) && ($answer->fraction>0)):
					$corr='<span style="color: blue;"><b>[X]</b></span> ';
					$tab_quiz[$q->q_idq]['qanswers'][$answer->id]=1;
				endif;
				$NREQ=$corr.'<b>'.$nr_answer.'.</b> '.$answer_txt;
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;
				$tab_quiz[$q->q_idq]['answers'][$answer->id]=$answer_txt;
				$nr_answer++;
			endforeach;
		break;

		case 'ddmatch':		
			$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade,$questiondecimalpoints,".","");
			$ddmatch_answers = $DB->get_records_sql('SELECT id,questiontext,answertext FROM {qtype_ddmatch_subquestions} WHERE questionid="'.$q->q_idq.'" ORDER BY id ASC');
			$answers_tab='';
			foreach($ddmatch_answers AS $ddmatch_answers):
				if($generate_html_file):
					$answer=$this->files_from_db_img('qtype_ddmatch','subanswer',array('extra_sql'=>' AND itemid="'.$ddmatch_answers->id.'" AND contextid IN ('.$contexts_array.')'),$ddmatch_answers->answertext,true);
					
					$question=$this->files_from_db_img('qtype_ddmatch','subquestion',array('extra_sql'=>' AND itemid="'.$ddmatch_answers->id.'" AND contextid IN ('.$contexts_array.')'),$ddmatch_answers->questiontext,true);
				else:
					$answer=$this->files_from_db_img('qtype_ddmatch','subanswer',array('extra_sql'=>' AND itemid="'.$ddmatch_answers->id.'" AND contextid IN ('.$contexts_array.')'),$ddmatch_answers->answertext);
					
					$question=$this->files_from_db_img('qtype_ddmatch','subquestion',array('extra_sql'=>' AND itemid="'.$ddmatch_answers->id.'" AND contextid IN ('.$contexts_array.')'),$ddmatch_answers->questiontext);
				endif;
				$tab_quiz[$q->q_idq]['questions'][$ddmatch_answers->id]=$question;
				$tab_quiz[$q->q_idq]['answers'][$ddmatch_answers->id]=$answer;
				$answers_tab.='<tr><td>'.$question.'</td><td>'.$answer.'</td></tr>';
			endforeach;
			$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nr_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_ddmatch').' ('.number_format($q->q_grade,$questiondecimalpoints,".","").' '.get_string('points_short','quiz_nitroreportpdf').')</td></tr></table><br />'.$q_text.'<br /><u>'.get_string('answers','quiz_nitroreportpdf').':</u><br /><br /><table border="1" style="margin-left: auto; margin-right: auto;"><tr><th>'.get_string('question2','quiz_nitroreportpdf').'</th><th>'.get_string('answer','quiz_nitroreportpdf').'</th></tr>'.$answers_tab.'</table>';
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
		break;
		
		case 'ordering':		
			$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade,$questiondecimalpoints,".","");
			$ordering_answers = $DB->get_records_sql('SELECT id,answer FROM {question_answers} WHERE question="'.$q->q_idq.'" ORDER BY fraction ASC');
			$q_text=$q->q_text.'<br><br>';		
			foreach($ordering_answers AS $ordering_answers):
				$q_text.=$ordering_answers->answer.'<br><hr><br>';
				$tab_quiz[$q->q_idq]['answers'][$ordering_answers->id]=$ordering_answers->answer;
				$tab_quiz[$q->q_idq]['answers_md5'][md5($ordering_answers->answer)]=$ordering_answers->id;
			endforeach;		
			$q_text=substr($q_text,0,-12).'<br><br>'.get_string('options','quiz_nitroreportpdf').':<br>';
			$ordering_options = $DB->get_record_sql('SELECT selecttype,selectcount FROM {qtype_ordering_options} WHERE questionid="'.$q->q_idq.'"');
			switch($ordering_options->selecttype):
				case 0:
					$q_text.='- '.get_string('selecttype','qtype_ordering').': '.get_string('selectall','qtype_ordering').'<br>';
				break;
				case 1:
					$q_text.='- '.get_string('selecttype','qtype_ordering').': '.get_string('selectrandom','qtype_ordering').'<br>';
				break;
				case 2:
					$q_text.='- '.get_string('selecttype','qtype_ordering').': '.get_string('selectcontiguous','qtype_ordering').'<br>';
				break;
			endswitch;
			$q_text.='- '.get_string('selectcount','qtype_ordering').': ';
			if($ordering_options->selectcount == 0):
				$q_text.= get_string('all','quiz_nitroreportpdf').' <br>';
			else:
				$q_text.=$ordering_options->selectcount.' <br>';
			endif;
			$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nr_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_ordering').' ('.number_format($q->q_grade,$questiondecimalpoints,".","").' '.get_string('points_short','quiz_nitroreportpdf').')</td></tr></table><br />'.$q_text;
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
		break;
	
		case 'gapfill':
			$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade,$questiondecimalpoints,".","");
			$gapfill_answers = $DB->get_records_sql('SELECT id,answer FROM {question_answers} WHERE question="'.$q->q_idq.'" ORDER BY id ASC');
			$gapfill_options = $DB->get_records_sql('SELECT question,delimitchars,casesensitive,noduplicates FROM {question_gapfill} WHERE question="'.$q->q_idq.'"');	
			$tab_quiz[$q->q_idq]['options']=array($gapfill_options[$q->q_idq]->delimitchars,$gapfill_options[$q->q_idq]->casesensitive,$gapfill_options[$q->q_idq]->noduplicates);
			$q_text=$q->q_text;	
			foreach($gapfill_answers AS $gapfill_answers):
				$tab_quiz[$q->q_idq]['answers'][]=$gapfill_answers->answer;
			endforeach;
			preg_match_all('/\\'.(substr($tab_quiz[$q->q_idq]['options'][0],0,1)).'(.*)\\'.(substr($tab_quiz[$q->q_idq]['options'][0],1,1)).'/U',$q_text,$founded);
			for($i=0;$i<count($founded[1]);$i++):
				$q_text=preg_replace('/\\'.(substr($tab_quiz[$q->q_idq]['options'][0],0,1)).$founded[1][$i].'\\'.(substr($tab_quiz[$q->q_idq]['options'][0],1,1)).'/',$tab_quiz[$q->q_idq]['answers'][$i],$q_text);
			endfor;
			$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nr_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_gapfill').' ('.number_format($q->q_grade,$questiondecimalpoints,".","").' '.get_string('points_short','quiz_nitroreportpdf').')</td></tr></table><br />'.$q_text;
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
		break;

// WIRIS QUESTIONS *** WIRIS QUESTIONS *** WIRIS QUESTIONS
		case 'truefalsewiris':	
			$truefalse='0';
			//get correct answer TRUE OR FALSE on question
			$question_truefalse_db_true = $DB->get_record_sql('SELECT qa.fraction AS fraction FROM {question_answers} qa, {question_truefalse} qtf WHERE qtf.question="'.$q->q_idq.'" AND qtf.trueanswer=qa.id');
			//get TRUE in language . this variable is use later.
			$tf_sql_true = $DB->get_record_sql('SELECT qa.answer AS answer FROM {question_truefalse} qt, {question_answers} qa WHERE qa.question="'.$q->q_idq.'" AND qt.question="'.$q->q_idq.'" AND qt.trueanswer=qa.id');
			//get FALSE in language . this variable is use later.
			$tf_sql_false = $DB->get_record_sql('SELECT qa.answer AS answer FROM {question_truefalse} qt, {question_answers} qa WHERE qa.question="'.$q->q_idq.'" AND qt.question="'.$q->q_idq.'" AND qt.falseanswer=qa.id');
			//if answer has fraction equals more than 1 - its correct answer
			if($question_truefalse_db_true->fraction>=1):
				$truefalse='1';
			else:
				$truefalse='0';
			endif;
			$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nr_question.'.</b></td><td style="text-align:right;">WIRIS - '.get_string('pluginname','qtype_truefalsewiris').' ('.number_format($q->q_grade,$questiondecimalpoints,".","").' '.get_string('points_short','quiz_nitroreportpdf').')</td></tr></table><br />'.$q_text.'<br /><u>'.get_string('answer','quiz_nitroreportpdf').':</u> <span style="color:blue;font-weight: bold;">'.(($truefalse==0) ? $tf_sql_false->answer : $tf_sql_true->answer).'</span>';
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
			$tab_quiz[$q->q_idq]['answers'][]=$truefalse;
			$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade,$questiondecimalpoints,".","");
		break;	
		
		case 'matchwiris':	
			$match_answers = $DB->get_records_sql('SELECT id,questiontext,answertext FROM {qtype_match_subquestions} WHERE questionid="'.$q->q_idq.'" ORDER BY id ASC');
			$answers_tab='';
			foreach($match_answers AS $match_answers):				
				if($generate_html_file):
				$question=$this->files_from_db_img('qtype_match','subquestion',array('extra_sql'=>' AND itemid="'.$match_answers->id.'" AND contextid IN ('.$contexts_array.')'),$match_answers->questiontext,true);
				else:
				$question=$this->files_from_db_img('qtype_match','subquestion',array('extra_sql'=>' AND itemid="'.$match_answers->id.'" AND contextid IN ('.$contexts_array.')'),$match_answers->questiontext);
				endif;
				$answer=$match_answers->answertext;
				$tab_quiz[$q->q_idq]['answers'][$match_answers->id]=array('question'=>$question,'answer'=>$answer);
				$answers_tab.='<tr><td>'.$question.'</td><td>'.$answer.'</td></tr>';
			endforeach;  //if are some file to process
			$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nr_question.'.</b></td><td style="text-align:right;">WIRIS - '.get_string('pluginname','qtype_match').' ('.number_format($q->q_grade,$questiondecimalpoints,".","").' '.get_string('points_short','quiz_nitroreportpdf').')</td></tr></table><br />'.$q_text.'<br /><u>'.get_string('answers','quiz_nitroreportpdf').':</u><br /><br /><table border="1"><tr><th>'.get_string('question2','quiz_nitroreportpdf').'</th><th>'.get_string('answer','quiz_nitroreportpdf').'</th></tr>'.$answers_tab.'</table>';
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
			$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade,$questiondecimalpoints,".","");
		break;		
		
		case 'multianswerwiris':
			$question_multianswer = $DB->get_records_sql('SELECT id,questiontext FROM {question} WHERE parent="'.$q->q_idq.'" ORDER BY id ASC');
			$i=0;
			foreach($question_multianswer AS $id => $multianswer):	
				$multianswer_questiontext=preg_replace('/\\\#/','@@@@@',$multianswer->questiontext);
				$getanswer=$this->nitro_get_multianswer_correct_answer($multianswer_questiontext);
				$tab_quiz[$q->q_idq]['answers'][]=$getanswer;
				if(count($getanswer['answers']) > 1):
					for($l=0;$l<count($getanswer['answers']);$l++):
						$points=$getanswer['points'][$l];
						if(empty($points)):
							$points=0;
						endif;
						if($l == $getanswer['correct']):
							$correct_answer.='<span style="color:blue;font-weight: bold;">'.$getanswer['answers'][$l].' ('.$points.' '.get_string('points_short','quiz_nitroreportpdf').')</span>, ';
						else:
							$correct_answer.=$getanswer['answers'][$l].' ('.$points.' '.get_string('points_short','quiz_nitroreportpdf').')</span>, ';
						endif;
					endfor;
					$correct_answer='['.substr($correct_answer,0,-2).']';
				else:
					$correct_answer='<span style="color:blue;font-weight: bold;">'.$getanswer['answers'][0].' ('.$points.' '.get_string('points_short','quiz_nitroreportpdf').')</span>';
				endif;
				$q_text=preg_replace('/{#'.$i.'}/',$correct_answer,$q_text);	
				$question_multianswer_resp = $DB->get_records_sql('SELECT id FROM {question_answers} WHERE question="'.$id.'" ORDER BY id ASC');
				$j=0;
				foreach($question_multianswer_resp AS $res):	
					$tab_quiz[$q->q_idq]['answers'][$i]['answers_id'][$res->id]=$j;
					$j++;	
				endforeach;	
				$i++;
			endforeach;
			$tab_quiz[$q->q_idq]['question_with_answers']=$q_text;
			$tab_quiz[$q->q_idq]['points'][0]=number_format($q->q_grade,$questiondecimalpoints,".","");
			if($MODE_NOTE == 2):
				$points=0;
				$i=0;
				for($z=0;$z<count($tab_quiz[$q->q_idq]['answers']);$z++):
					$points+=$tab_quiz[$q->q_idq]['answers'][$z]['points'][$tab_quiz[$q->q_idq]['answers'][$z]['correct']];
				endfor;
				$tab_quiz[$q->q_idq]['points'][0]=number_format($points,$questiondecimalpoints,".","");
			endif;
			$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nr_question.'.</b></td><td style="text-align:right;">WIRIS - '.get_string('pluginname','qtype_multianswerwiris').' ('.$tab_quiz[$q->q_idq]['points'][0].' '.get_string('points_short','quiz_nitroreportpdf').')</td></tr></table><br />'.$q_text;
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
		break;	

		case 'multichoicewiris':
			$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade,$questiondecimalpoints,".","");
			$answers_db = $DB->get_records_sql('SELECT id,answer,fraction FROM {question_answers} WHERE question="'.$q->q_idq.'"');
			$multi_tb = $DB->get_record_sql('SELECT single FROM {qtype_multichoice_options} WHERE questionid="'.$q->q_idq.'"');
			if($multi_tb->single==1):
				$type_q=get_string('questiontypemultichoiceone','quiz_nitroreportpdf');
			else:
				$type_q=get_string('questiontypemultichoicemulti','quiz_nitroreportpdf');
			endif;
			$NREQ='<table border="0" style="width: 100%;"><tr><td><b>'.$nr_question.'.</b></td><td style="text-align: right;">WIRIS - '.$type_q.' ('.number_format($q->q_grade,$questiondecimalpoints,".","").' '.get_string('points_short','quiz_nitroreportpdf').')</td></tr></table><br />'.$q_text.'<br /><u>'.get_string('answers','quiz_nitroreportpdf').':</u> <br /><br />';
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
			$nr_answer=1;
			foreach($answers_db AS $answer):
				if($generate_html_file):
					$answer_txt=$this->files_from_db_img('question','answer',array('extra_sql'=>' AND itemid="'.$answer->id.'" AND contextid IN ('.$contexts_array.')'),$answer->answer,true);
				else:
					$answer_txt=$this->files_from_db_img('question','answer',array('extra_sql'=>' AND itemid="'.$answer->id.'" AND contextid IN ('.$contexts_array.')'),$answer->answer);
				endif;
				$corr='';
				$tab_quiz[$q->q_idq]['qanswers'][$answer->id]=0;
				if(($multi_tb->single == 1) && ($answer->fraction>=1)):
					$corr='<span style="color: blue;"><b>[X]</b></span> ';
					$tab_quiz[$q->q_idq]['qanswers'][$answer->id]=1;
				endif;
				if(($multi_tb->single == 0) && ($answer->fraction>0)):
					$corr='<span style="color: blue;"><b>[X]</b></span> ';
					$tab_quiz[$q->q_idq]['qanswers'][$answer->id]=1;
				endif;
				$NREQ=$corr.'<b>'.$nr_answer.'.</b> '.$answer_txt;
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;
				$tab_quiz[$q->q_idq]['answers'][$answer->id]=$answer_txt;
				$nr_answer++;
			endforeach;
		break;		
					
		case 'shortanswerwiris':	
			$question_answer = $DB->get_records_sql('SELECT id,answer,fraction FROM {question_answers} WHERE question="'.$q->q_idq.'" ORDER BY id ASC');
			$i=0;
			foreach($question_answer AS $id => $answer):
				$tab_quiz[$q->q_idq]['answers'][$i]=$answer->answer;
				$tab_quiz[$q->q_idq]['fraction'][$i]=$answer->fraction;
				$tab_quiz[$q->q_idq]['answers_id'][$id]=$i;
				$i++;
			endforeach;	
			$tab_quiz[$q->q_idq]['points'][0]=number_format($q->q_grade,$questiondecimalpoints,".","");
			$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nr_question.'.</b></td><td style="text-align:right;">WIRIS - '.get_string('pluginname','qtype_shortanswerwiris').' ('.$tab_quiz[$q->q_idq]['points'][0].' '.get_string('points_short','quiz_nitroreportpdf').')</td></tr></table><br />'.$q_text;
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
		break;		
// WIRIS QUESTIONS *** WIRIS QUESTIONS *** WIRIS QUESTIONS

		case 'multichoiceset':
			$answers = $DB->get_records_sql('SELECT id,answer,fraction FROM {question_answers} WHERE question="'.$q->q_idq.'" ORDER BY id ASC');
			$answers_tab='';
			$answers_corr_tab=array();
			$i=1;
			foreach($answers AS $answers):
				if($generate_html_file):
					$answer=$this->files_from_db_img('question','question',array('extra_sql'=>' AND itemid="'.$answers->id.'" AND contextid IN ('.$contexts_array.')'),$answers->answer,true);
				else:
					$answer=$this->files_from_db_img('question','question',array('extra_sql'=>' AND itemid="'.$answers->id.'" AND contextid IN ('.$contexts_array.')'),$answers->answer);
				endif;
				$tab_quiz[$q->q_idq]['answers'][$i-1]=array('answer'=>$answer,'fraction'=>$answers->fraction);
				$tab_quiz[$q->q_idq]['answers_id'][$answers->id]=$i-1;
				$answers_tab.='<b>'.$i.'.</b>';
				if($answers->fraction>0):
					$answers_tab.='<span style="color:blue;font-weight: bold;">[X]</span>';
					$answers_corr_tab[]=$answers->id;
				endif;
				$answers_tab.=$answer.'<br><br>';
				$i++;
			endforeach;  //if are some file to process
			$tab_quiz[$q->q_idq]['answers_corr_tab']=$answers_corr_tab;
			$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nr_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_multichoiceset').' ('.number_format($q->q_grade,$questiondecimalpoints,".","").' '.get_string('points_short','quiz_nitroreportpdf').')</td></tr></table><br />'.$q_text.'<br /><br />'.$answers_tab.'<br />';
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
			$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade,$questiondecimalpoints,".","");
		break;

		case 'calculatedsimple':
			$numericalA = $DB->get_record_sql('SELECT showunits,unitsleft,unitgradingtype,unitpenalty FROM {question_numerical_options} WHERE question="'.$q->q_idq.'"');
			$numericalB = $DB->get_records_sql('SELECT qa.id AS id,qa.answer AS answer, qa.fraction AS fraction, qc.tolerance AS tolerance,qc.tolerancetype AS tolerancetype, qc.correctanswerlength AS correctanswerlength, qc.correctanswerformat AS correctanswerformat FROM {question_answers} qa, {question_calculated} qc WHERE qa.question="'.$q->q_idq.'" AND qa.id=qc.answer AND qa.fraction>0 ORDER BY qa.id ASC');
			$numericalC = $DB->get_records_sql('SELECT id,multiplier,unit FROM {question_numerical_units} WHERE question="'.$q->q_idq.'" ORDER BY id');
			$options['showunits']=$numericalA->showunits;
			$options['unitsleft']=$numericalA->unitsleft;
			$options['unitgradingtype']=$numericalA->unitgradingtype;
			$options['unitpenalty']=$numericalA->unitpenalty;
			$tab_quiz[$q->q_idq]['options']=$options;
			$i=0;
			foreach($numericalB as $answers):
				$tab_quiz[$q->q_idq]['answers'][$i]['answer']=$answers->answer;
				$tab_quiz[$q->q_idq]['answers'][$i]['fraction']=$answers->fraction;
				$tab_quiz[$q->q_idq]['answers'][$i]['tolerance']=$answers->tolerance;
				$tab_quiz[$q->q_idq]['answers'][$i]['tolerancetype']=$answers->tolerancetype;
				$tab_quiz[$q->q_idq]['answers'][$i]['correctanswerlength']=$answers->correctanswerlength;
				$tab_quiz[$q->q_idq]['answers'][$i]['correctanswerformat']=$answers->correctanswerformat;
				$tab_quiz[$q->q_idq]['answersid'][$answers->id]=$i;
				$i++;
			endforeach;
			$i=0;
			foreach($numericalC AS $id => $unit):
				$tab_quiz[$q->q_idq]['units'][$i]['unit']=$unit->answer;
				$tab_quiz[$q->q_idq]['units'][$i]['multiplier']=$unit->multiplier;
				$i++;
			endforeach;
			$tab_quiz[$q->q_idq]['points']=number_format($q->q_grade,$questiondecimalpoints,".","");	
			$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nr_question.'.</b></td><td style="text-align: right;">'.get_string('pluginname','qtype_calculatedsimple').' ('.number_format($q->q_grade,$questiondecimalpoints,".","").' '.get_string('points_short','quiz_nitroreportpdf').')</td></tr></table><br />'.$q_text.'<br />';
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
		break;

		default:	
			$tab_quiz[$q->q_idq]['points'][]=number_format($q->q_grade,$questiondecimalpoints,".","");
		break;
	endswitch;
	
	$NREQ='<hr noshade style="height:2px;color:black;" />';
	
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ.'<hr noshade>';
	endif;
	$nr_question++;
endforeach; // question while processing

 /*	4. Points for questions	*/
$this->SetBarWidth(number_format(floor(4*(100/$PROGRESSBAR_PARTS)),2,'.',''));
@ob_flush();
@flush();
$mpdf->AddPage();
$mpdf->Bookmark('4. '.get_string('pointsforquestion','quiz_nitroreportpdf'),0);
$NREQ='<p style="text-align: center;font-weight: bold;font-size:14pt;text-transform:uppercase;">'.get_string('pointsforquestion','quiz_nitroreportpdf').'</p><p></p>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
for($i=0;$i<count($tab_correct_answers);$i++):
	if($i % 2 == 1):
		$attach_style=' class="table_td_highlight"';
	endif;
	$question_and_points.='<tr'.$attach_style.'><td>'.($i+1).'</td><td style="text-align: right;">'.$tab_correct_answers[$i].'</td></tr>';
	unset($attach_style);
endfor;
$NREQ='
<table style="margin-left: auto; margin-right: auto;" class="table">
	<tr>
		<th>'.get_string('noquestion','quiz_nitroreportpdf').'</th>
		<th>'.get_string('nopoints','quiz_nitroreportpdf').'</th>
	</tr>
	'.$question_and_points.'
	<tr>
		<td><b>'.get_string('total','quiz_nitroreportpdf').'</b></td>
		<td style="text-align: right;">'.number_format($info_quiz->sumgrades,$questiondecimalpoints,".","").'</td>
	</tr>
</table>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ.'<hr noshade>';;
endif;
if($GENERATE_EXCEL):
	$SheetCount=$objPHPExcel->getSheetCount();
	$objPHPExcel->createSheet(NULL,$SheetCount);
	$objPHPExcel->setActiveSheetIndex($SheetCount);
	$objPHPExcel->getActiveSheet()->setTitle(get_string('pointsforquestion','quiz_nitroreportpdf'));
	$objPHPExcel->getActiveSheet()->setCellValue('A1', get_string('noquestion','quiz_nitroreportpdf'))->setCellValue('B1', get_string('nopoints','quiz_nitroreportpdf'));
	$objPHPExcel->getActiveSheet()->getRowDimension(1)->setRowHeight(19.83);
	for($i=0;$i<count($tab_correct_answers);$i++):
		$objPHPExcel->getActiveSheet()->getRowDimension((2+$i))->setRowHeight(19.83);
		$objPHPExcel->getActiveSheet()->setCellValue('A'.(2+$i), ($i+1));
		$objPHPExcel->getActiveSheet()->setCellValue('B'.(2+$i), $tab_correct_answers[$i]);
		$objPHPExcel->getActiveSheet()->getStyle('A'.($i+2))->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
		$objPHPExcel->getActiveSheet()->getStyle('B'.($i+2))->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_RIGHT);
	endfor;
	for($i=0;$i<count($tab_correct_answers)+1;$i++):
		if($i % 2 == 0):
			$objPHPExcel->getActiveSheet()->getStyle('A'.($i+1).':B'.($i+1))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('A'.($i+1).':B'.($i+1))->getFill()->getStartColor()->setRGB('FFFFA1');
		endif;
	endfor;
	$objPHPExcel->getActiveSheet()->setCellValue('A'.$i, get_string('sum','quiz_nitroreportpdf'));
	$objPHPExcel->getActiveSheet()->setCellValue('B'.$i, number_format($info_quiz->sumgrades,$questiondecimalpoints,".",""));
	$objPHPExcel->getActiveSheet()->getRowDimension($i)->setRowHeight(19.83);
	$objPHPExcel->getActiveSheet()->getStyle('A'.$i.':B'.$i)->getFont()->setBold(true);
	$objPHPExcel->getActiveSheet()->getStyle('A1:B1')->getFont()->setBold(true);
	$objPHPExcel->getActiveSheet()->getStyle('A1:B1')->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
	$objPHPExcel->getActiveSheet()->getStyle('A1:B1')->getFill()->getStartColor()->setRGB('0057AF');
	$objPHPExcel->getActiveSheet()->getStyle('A1:B1')->getFont()->getColor()->setARGB(PHPExcel_Style_Color::COLOR_WHITE);
	$objPHPExcel->getActiveSheet()->getStyle('A1:B'.$i)->getFont()->setSize(14);
	$styleArray = array(
		'borders' => array(
			'allborders' => array(
				'style' => PHPExcel_Style_Border::BORDER_THIN,
				'color' => array(
					'rgb' => '000000'
				),
			),
		),
	);
	$objPHPExcel->getActiveSheet()->getStyle('A1:B'.$i)->applyFromArray($styleArray);
	$objPHPExcel->getActiveSheet()->getStyle('A1:B'.$i)->getAlignment()->setWrapText(false);
	$objPHPExcel->getActiveSheet()->getColumnDimension('A')->setAutoSize(true);
	$objPHPExcel->getActiveSheet()->getColumnDimension('B')->setAutoSize(true);
	$objPHPExcel->getActiveSheet()->getProtection()->setPassword(substr(hash('sha512',rand()),0,12));
	$objPHPExcel->getActiveSheet()->getProtection()->setSheet(true);
	$objPHPExcel->getActiveSheet()->getProtection()->setFormatCells(true);
	$objPHPExcel->getActiveSheet()->getHeaderFooter()->setOddFooter('&L&D, &T &R &P / &N');
endif;
 /*	5. Quiz evaluation		*/
$this->SetBarWidth(number_format(floor(5*(100/$PROGRESSBAR_PARTS)),2,'.',''));
@ob_flush();
@flush();
$mpdf->AddPage();
$mpdf->Bookmark('5. '.get_string('evaluation','quiz_nitroreportpdf'),0);
$NREQ='<p style="text-align: center;font-weight: bold;font-size:14pt;text-transform:uppercase;">'.get_string('evaluation','quiz_nitroreportpdf').'</p>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
$quiz_feedback_corr = $DB->get_record_sql('SELECT id,maxgrade FROM {quiz_feedback} WHERE quizid="'.$quizid.'" ORDER BY id ASC LIMIT 0,1');
$quiz_feedback = $DB->get_records_sql('SELECT id,feedbacktext,mingrade,maxgrade FROM {quiz_feedback} WHERE quizid="'.$quizid.'" ORDER BY id ASC');
$quiz_count=count($quiz_feedback);
$maxpoints=number_format($info_quiz->sumgrades,4,".","");
$tab_notes=array();
$tab_notes2=array();
$minus='0.';
for($i=1;$i<$decimalpoints;$i++):
	$minus.='0';
endfor;
$minus.='1';
$correction=number_format(($quiz_feedback_corr->maxgrade/100)-$minus,4,'.','');
$i=0;
if($quiz_count <= 0 ):
	$NREQ='<p style="text-align: center;">'.get_string('noschemgrade').'</p>';
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ;
	endif;
else:
	foreach($quiz_feedback AS $feedback):
		$feedback_text = $feedback->feedbacktext;
		$tab_notes[$i]['mingrade_moodle']=$feedback->mingrade;
		$tab_notes[$i]['maxgrade_moodle']=$feedback->maxgrade;
		$tab_notes[$i]['mingrade_precent']=number_format(($feedback->mingrade/$correction)+$minus,$decimalpoints,".","");
		$tab_notes[$i]['maxgrade_precent']=number_format($feedback->maxgrade/$correction,$decimalpoints,".","");
		$tab_notes[$i]['mingrade_points']=number_format(($tab_notes[$i]['mingrade_precent']/100)*$maxpoints,4,".","");
		$tab_notes[$i]['maxgrade_points']=number_format(($tab_notes[$i]['maxgrade_precent']/100)*$maxpoints,4,".","");
		if($i==0):
			$tab_notes[$i]['maxgrade_precent']=number_format(100,$decimalpoints,".","");
			$tab_notes[$i]['maxgrade_points']=number_format($maxpoints,4,".","");
		endif;
		if($i==count($quiz_feedback)-1):
			$tab_notes[$i]['mingrade_precent']=number_format(0,$decimalpoints,".","");
			$tab_notes[$i]['mingrade_points']=number_format(0,4,".","");
		endif;	
		if($generate_html_file):
			$feedback_text=$this->files_from_db_img('mod_quiz','feedback',array('extra_sql'=>' AND itemid="'.$feedback->id.'" AND contextid IN ('.$contexts_array.')'),$feedback_text,true);
		else:
			$feedback_text=$this->files_from_db_img('mod_quiz','feedback',array('extra_sql'=>' AND itemid="'.$feedback->id.'" AND contextid IN ('.$contexts_array.')'),$feedback_text);
		endif;
		$tab_notes[$i]['feedback']=$feedback_text;
		$i++;
	endforeach; // foreach feedback
	$tab_notes2=$tab_notes;
	for($i=0;$i<count($tab_notes);$i++):
		if($i % 2 == 1):
			$attach_style=' class="table_td_highlight"';
		endif;
		$tab_notes_feedback.='
			<tr'.$attach_style.'>
			<td>'.$tab_notes[$i]['mingrade_precent'].'</td>
			<td>'.$tab_notes[$i]['maxgrade_precent'].'</td>
			<td>'.$tab_notes[$i]['mingrade_points'].'</td>
			<td>'.$tab_notes[$i]['maxgrade_points'].'</td>
			<td>'.$tab_notes[$i]['feedback'].'</td>
		</tr>';
		unset($attach_style);
	endfor;
	$tab_notes='
	<table style="margin-left: auto; margin-right: auto;" class="table">
		<tr>
			<th colspan="2">'.get_string('percents','quiz_nitroreportpdf').'</th>
			<th colspan="2">'.get_string('points','quiz_nitroreportpdf').'</th>
			<th rowspan="2">'.get_string('grade','quiz_nitroreportpdf').'</th>
		</tr>
		<tr>
			<th>'.get_string('from','quiz_nitroreportpdf').'</th>
			<th>'.get_string('to','quiz_nitroreportpdf').'</th>
			<th>'.get_string('from','quiz_nitroreportpdf').'</th>
			<th>'.get_string('to','quiz_nitroreportpdf').'</th>
		</tr>
		'.$tab_notes_feedback.'
	</table>';
	$mpdf->WriteHTML($tab_notes);
	if($generate_html_file):
		$html_contents.=$tab_notes.'<hr noshade>';;
	endif;
endif; // $quiz_count if grades = 0
if($GENERATE_EXCEL):
	$SheetCount=$objPHPExcel->getSheetCount();
	$objPHPExcel->createSheet(NULL,$SheetCount);
	$objPHPExcel->setActiveSheetIndex($SheetCount);
	$objPHPExcel->getActiveSheet()->setTitle(get_string('evaluation','quiz_nitroreportpdf'));
	$objPHPExcel->getActiveSheet()->setCellValue('A1', get_string('evaluation','quiz_nitroreportpdf'))
	->setCellValue('A2', get_string('percents','quiz_nitroreportpdf'))
	->setCellValue('C2', get_string('points','quiz_nitroreportpdf'))
	->setCellValue('A3', get_string('from','quiz_nitroreportpdf'))
	->setCellValue('B3', get_string('to','quiz_nitroreportpdf'))
	->setCellValue('C3', get_string('from','quiz_nitroreportpdf'))
	->setCellValue('D3', get_string('to','quiz_nitroreportpdf'))
	->setCellValue('E2', get_string('grade','quiz_nitroreportpdf'));
	$objPHPExcel->getActiveSheet()->mergeCells('A1:E1')
	->mergeCells('A2:B2')
	->mergeCells('C2:D2')
	->mergeCells('E2:E3');
	$objPHPExcel->getActiveSheet()->getStyle('A1:E3')->getFont()->setBold(true);
	for($i=0;$i<count($tab_notes2);$i++):
		$objPHPExcel->getActiveSheet()
		->setCellValue('A'.(4+$i), $tab_notes2[$i]['mingrade_precent'])
		->setCellValue('B'.(4+$i), $tab_notes2[$i]['maxgrade_precent'])
		->setCellValue('C'.(4+$i), $tab_notes2[$i]['mingrade_points'])
		->setCellValue('D'.(4+$i), $tab_notes2[$i]['maxgrade_points'])
		->setCellValue('E'.(4+$i), strip_tags($tab_notes2[$i]['feedback']));
		if($i % 2 == 1):
			$objPHPExcel->getActiveSheet()->getStyle('A'.(4+$i).':E'.(4+$i))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('A'.(4+$i).':E'.(4+$i))->getFill()->getStartColor()->setRGB('FFFFA1');
		endif;
		$objPHPExcel->getActiveSheet()->getRowDimension((4+$i))->setRowHeight(19.83);
	endfor;
	$objPHPExcel->getActiveSheet()->getStyle('A1:E'.(4+$i))->getFont()->setSize(14);
	$objPHPExcel->getActiveSheet()->getStyle('A1:E'.(4+$i))->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
	$objPHPExcel->getActiveSheet()->getStyle('E2')->getAlignment()->setVertical(PHPExcel_Style_Alignment::VERTICAL_CENTER);
	$objPHPExcel->getActiveSheet()->getRowDimension(1)->setRowHeight(19.83);
	$objPHPExcel->getActiveSheet()->getRowDimension(2)->setRowHeight(19.83);
	$objPHPExcel->getActiveSheet()->getRowDimension(3)->setRowHeight(19.83);
	$objPHPExcel->getActiveSheet()->getStyle('A1:E3')->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
	$objPHPExcel->getActiveSheet()->getStyle('A1:E3')->getFill()->getStartColor()->setRGB('0057AF');
	$objPHPExcel->getActiveSheet()->getStyle('A1:E3')->getFont()->getColor()->setARGB(PHPExcel_Style_Color::COLOR_WHITE);
	PHPExcel_Shared_Font::setAutoSizeMethod(PHPExcel_Shared_Font::AUTOSIZE_METHOD_EXACT);
	$objPHPExcel->getActiveSheet()->getStyle('A1:E'.(4+$i))->getAlignment()->setWrapText(false);
	$objPHPExcel->getActiveSheet()->getColumnDimension('A')->setWidth(-1);
	$objPHPExcel->getActiveSheet()->getColumnDimension('B')->setWidth(-1);
	$objPHPExcel->getActiveSheet()->getColumnDimension('C')->setWidth(-1);
	$objPHPExcel->getActiveSheet()->getColumnDimension('D')->setWidth(-1);
	$objPHPExcel->getActiveSheet()->getColumnDimension('E')->setWidth(-1);
	$styleArray = array(
		'borders' => array(
			'allborders' => array(
				'style' => PHPExcel_Style_Border::BORDER_THIN,
				'color' => array(
					'rgb' => '000000'
				),
			),
		),
	);
	$objPHPExcel->getActiveSheet()->getStyle('A1:E8')->applyFromArray($styleArray);
	$objPHPExcel->getActiveSheet()->getProtection()->setPassword(substr(hash('sha512',rand()),0,12));
	$objPHPExcel->getActiveSheet()->getProtection()->setSheet(true);
	$objPHPExcel->getActiveSheet()->getProtection()->setFormatCells(true);
	$objPHPExcel->getActiveSheet()->getHeaderFooter()->setOddFooter('&L&D, &T &R &P / &N');
endif;
 /*	6. Quiz filled by exams		*/
$this->SetBarWidth(number_format(floor(6*(100/$PROGRESSBAR_PARTS)),2,'.',''));
@ob_flush();
@flush();
$mpdf->AddPage();
$mpdf->Bookmark('6. '.get_string('exam_tests','quiz_nitroreportpdf'),0);
$NREQ='<p style="text-align: center;font-weight: bold;font-size:14pt;text-transform:uppercase;">'.get_string('exam_tests','quiz_nitroreportpdf').'</p><p></p>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
$tab_users=array();
/*	5. Get users who filled exam		*/
$quiz_users = $DB->get_records_sql('SELECT DISTINCT(qa.userid) AS userid  FROM {quiz_attempts} qa,{user} u WHERE qa.quiz="'.$quizid.'" AND userid=u.id AND qa.state="finished" ORDER BY u.lastname ASC, u.firstname ASC, u.username ASC');
if(count($quiz_users)==0):
	$progress_user=number_format(floor(7*(100/$PROGRESSBAR_PARTS)),2,'.','');
else:
	$progress_user=number_format(floor(7*(100/$PROGRESSBAR_PARTS)/count($quiz_users)),2,'.','');
endif;
$user_i=1;
foreach($quiz_users AS $users):
	$this->SetBarWidth($progress_user*$user_i);
	@ob_flush();
	@flush();
	$get_info_user=$this->nitro_get_user($users->userid);
	$mpdf->Bookmark('6.'.$user_i.'. '.get_string('examined','quiz_nitroreportpdf').': '.$get_info_user->firstname.' '.$get_info_user->lastname,1);
	$createaccount=($get_info_user->timecreated > 0) ? date('d.m.Y H:i',$get_info_user->timecreated) : '-----';
	$lastlogin=($get_info_user->lastlogin > 0) ? date('d.m.Y H:i',$get_info_user->lastlogin) : '-----';
	$tab_users[$users->userid]['uid']=$users->userid;
	$tab_users[$users->userid]['name']=$get_info_user->firstname;
	$tab_users[$users->userid]['surname']=$get_info_user->lastname;
	$tab_users[$users->userid]['email']=$get_info_user->email;
	$tab_users[$users->userid]['username']=$get_info_user->username;
	$user_photo=$CFG->dirroot.'/mod/quiz/report/nitroreportpdf/nophoto.png';
	if($get_info_user->picture > 0):
		$filex = $DB->get_record_sql('SELECT contextid,itemid,filepath,filename FROM {files} WHERE id="'.$get_info_user->picture.'" AND filename<>"."');
		$fs = null;
		$file = null;
		$fs = get_file_storage();
		$file = $fs->get_file($filex->contextid,'user','icon',$filex->itemid,$filex->filepath,$filex->filename);
		$tempfilename='_U'.$userid.'_Q'.$quizid.'_'.strtotime('now').uniqid().uniqid().$ffile;
		if($file):
			$file->copy_content_to($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$tempfilename);
			$user_photo=$CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$tempfilename;
		endif; // file
	endif; // photo
	switch($info_quiz->grademethod):
		case '2':
			$grademethod_sql = $DB->get_records_sql('SELECT uniqueid,timestart,timefinish,sumgrades FROM {quiz_attempts} WHERE quiz="'.$quizid.'" AND userid="'.$users->userid.'" AND state="finished" ORDER BY id ASC');
		break;
		case '3':
			$grademethod_sql = $DB->get_record_sql('SELECT uniqueid,timestart,timefinish,sumgrades FROM {quiz_attempts} WHERE quiz="'.$quizid.'" AND userid="'.$users->userid.'" AND state="finished" ORDER BY id ASC LIMIT 0,1');
		break;
		case '4':
			$grademethod_sql = $DB->get_record_sql('SELECT uniqueid,timestart,timefinish,sumgrades FROM {quiz_attempts} WHERE quiz="'.$quizid.'" AND userid="'.$users->userid.'" AND state="finished" ORDER BY id DESC LIMIT 0,1');
		break;
		default:
			$grademethod_sql = $DB->get_records_sql('SELECT id,sumgrades FROM {quiz_attempts} WHERE quiz="'.$quizid.'" AND userid="'.$users->userid.'" AND state="finished"');
			$max_id = -1;
			$max_sumgrades = -1;
			foreach($grademethod_sql AS $grademethod):
				if($max_id == -1):
					$max_id = $grademethod->id;
					$max_sumgrades = $grademethod->sumgrades;
				endif;
				if($grademethod->sumgrades >= $max_sumgrades):
					$max_id = $grademethod->id;
					$max_sumgrades = $grademethod->sumgrades;
				endif;
			endforeach;
			$grademethod_sql = $DB->get_record_sql('SELECT uniqueid,timestart,timefinish,sumgrades FROM {quiz_attempts} WHERE id="'.$max_id.'" AND state="finished"');
		break;
	endswitch;
	$NREQ='<br /><table style="margin-left: auto; margin-right: auto;" class="table">
		<tr>
			<th>'.get_string('name','quiz_nitroreportpdf').':</th>
			<td>'.$get_info_user->firstname.'</td>
			<td rowspan="8" style="vertical-align: middle;text-align: center;"><img src="'.$user_photo.'" /></td>
		</tr>
		<tr>
			<th>'.get_string('surname','quiz_nitroreportpdf').':</th>
			<td>'.$get_info_user->lastname.'</td>
		</tr>
		<tr>
			<th>'.get_string('username','quiz_nitroreportpdf').':</th>
			<td>'.$get_info_user->username.'</td>
		</tr>
		<tr>
			<th>'.get_string('email','quiz_nitroreportpdf').':</th>
			<td>'.$get_info_user->email.'</td>
		</tr>
		<tr>
			<th>'.get_string('institution','quiz_nitroreportpdf').':</th>
			<td>'.$get_info_user->institution.'</td>
		</tr>
		<tr>
			<th>'.get_string('department','quiz_nitroreportpdf').':</th>
			<td>'.$get_info_user->department.'</td>
		</tr>
		<tr>
			<th>'.get_string('accountcreated','quiz_nitroreportpdf').':</th>
			<td>'.$createaccount.'</td>
		</tr>
		<tr>
			<th>'.get_string('lastlogin','quiz_nitroreportpdf').':</th>
			<td>'.$lastlogin.'</td>
		</tr>
	</table>
	<br /><br />';
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ;
	endif;
	$nb_question=1;
	foreach($tab_quiz AS $tq):
		$mpdf->Bookmark('6.1. '.get_string('question_upper','quiz_nitroreportpdf').' '.$nb_question,2);
		switch($tq['type']):
			case 'truefalse':
				$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format(0,$questiondecimalpoints,".","");
				$xxx1 = $DB->get_record_sql('SELECT qasd.id AS id, qasd.value AS value FROM  {question_attempts} qa,{question_attempt_steps} qas, {question_attempt_step_data} qasd WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.sequencenumber="1" AND qas.id=qasd.attemptstepid AND name="answer" ORDER BY qas.id ASC');
				$tab_users[$users->userid]['answers'][$tq['qid']]['answer'][0]=$xxx1->value;
				$user_answer=trim($xxx1->value);
				$answer='';
				$answer=($user_answer == 1) ? $tf_sql_true->answer : $tf_sql_false->answer;
				$anscolor='';
				if($user_answer == ""):
					$anscolor='<span style="color:red;font-weight: bold;">'.get_string('noanswer','quiz_nitroreportpdf').'</span>';
				elseif($user_answer == $tq['answers'][0]):
					$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0],$questiondecimalpoints,".","");
					$tab_users[$users->userid]['answers'][$tq['qid']]['answer'][0]=$user_answer;
					$anscolor='<span style="color:blue;font-weight: bold;">'.$answer.'</span>';
				else:
					$anscolor='<span style="color:red;font-weight: bold;">'.$answer.'</span>';
				endif;
				$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nb_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_truefalse').'</td></tr></table><br />'.$tq['question'].'<br /><u>'.get_string('answered','quiz_nitroreportpdf').':</u> '.$anscolor.'<br /><br />
				<table style="margin-left: auto; margin-right: auto;" class="table">
					<tr>
						<th style="font-size: 10pt;">'.get_string('points_available','quiz_nitroreportpdf').'</th>
						<th style="font-size: 10pt;">'.get_string('gained_points','quiz_nitroreportpdf').'</th>
					</tr>
					<tr>
						<td style="text-align: center;font-size: 10pt;">'.$tq['points'][0].'</td>
						<td style="text-align: center;font-size: 10pt;">'.$tab_users[$users->userid]['attempt'][$tq['qid']].'</td>
					</tr>
				</table>';
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;
			break;
				
			case 'numerical':
				$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format(0,$questiondecimalpoints,".","");
				$xxx1 = $DB->get_records_sql('SELECT qas.id AS id FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.sequencenumber IN (1) ORDER BY qas.id ASC');
				$xxx2=array_keys($xxx1);
				$xxx_resp=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="answer"');
				preg_match('/([0-9.]+)(.*)/',$xxx_resp->value,$m1);
				$ans_number = trim($m1[1]);
				$ans_unit 	= trim($m1[2]);		
				$ans = $ans_number;
				$xanso='<span style="color:red;font-weight: bold;">'.$xxx_resp->value.'</span>';	
				if(!empty($ans_unit)):
					$ans.='|'.$ans_unit;
				endif;
				for($i=0;$i<count($tq['answers']);$i++):
					if(preg_match('/-/',$tq['answers'][$i])):
						$tq_unit='';
						$tq_range=$tq['answers'][$i];
						if(preg_match('/\|/',$tq['answers'][$i])):
							$tq_unit=substr($tq['answers'][$i],strpos($tq['answers'][$i],'|')+1);
							$tq_range = substr($tq['answers'][$i],0,-count($tq_unit)-2);
						endif;
						$e=explode('-',$tq_range);
						if(($ans_number >= $e[0])	&&	($ans_number <= $e[1]) && ($ans_unit == $tq_unit)):
							$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][$i],$questiondecimalpoints,".","");
							$xanso='<span style="color:blue;font-weight: bold;">'.$xxx_resp->value.'</span>';
							break;
						endif;
					else:
						if($ans == $tq['answers'][$i]):
							$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][$i],$questiondecimalpoints,".","");
							$xanso='<span style="color:blue;font-weight: bold;">'.$xxx_resp->value.'</span>';
							break;
						endif;
					endif;
				endfor;
				if(empty($xxx_resp->value)):
					$xanso='<span style="color:red;font-weight: bold;">'.get_string('noanswer','quiz_nitroreportpdf').'</span>';
				endif;
				$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nb_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_numerical').'</td></tr></table><br />'.$tq['question'].'<br /><u>'.get_string('answered','quiz_nitroreportpdf').':</u> '.$xanso.'</span><br /><br />
				<table style="margin-left: auto; margin-right: auto;" class="table">
					<tr>
						<th style="font-size: 10pt;">'.get_string('points_available','quiz_nitroreportpdf').'</th>
						<th style="font-size: 10pt;">'.get_string('gained_points','quiz_nitroreportpdf').'</th>
					</tr>
					<tr>
						<td style="text-align: center;font-size: 10pt;">'.max($tq['points']).'</td>
						<td style="text-align: center;font-size: 10pt;">'.$tab_users[$users->userid]['attempt'][$tq['qid']].'</td>
					</tr>
				</table>';
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;
			break;

			case 'gapselect':
				$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format(0,$questiondecimalpoints,".","");
				$xxx1 = $DB->get_records_sql('SELECT qas.id AS id FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.sequencenumber IN (0,1) ORDER BY qas.id ASC');
				$xxx2=array_keys($xxx1);			
				$xxx_questions=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name LIKE "_choiceorder%"');				
				$xxx_answers_orders=$DB->get_records_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name LIKE "p%" ORDER BY id ASC');		
				$ttab1=null;
				$ttab1=explode(',',$xxx_questions->value);
				$question=$tq['question'];	
				$ttab2=null;
				foreach($xxx_answers_orders AS $xao):	
					$ttab2[]=$xao->value;
				endforeach;	
				$selected=0;
				for($z=0;$z<count($ttab1);$z++):
					if(($ttab2[$z]-1) < 0):
						$question=preg_replace('/\[\['.($z+1).'\]\]/','<span style="color:red;font-weight: bold;">___</span>',$question);
					elseif(($ttab1[$ttab2[$z]-1])==$tq['answers'][$z]):
						$question=preg_replace('/\[\['.($z+1).'\]\]/','<span style="color:blue;font-weight: bold;">'.($tq['choices'][ $ttab1[$ttab2[$z]-1]-1]).'</span>',$question);
						$selected++;
					else:
						$question=preg_replace('/\[\['.($z+1).'\]\]/','<span style="color:red;font-weight: bold;">'.($tq['choices'][ $ttab1[$ttab2[$z]-1]-1]).'</span>',$question);
					endif;
				endfor;
				if($MODE_NOTE == 1):
					if(count(array_diff($tq['answers'],$ttab2))==0):
						$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0],$questiondecimalpoints,".","");
					endif;
				else:
					$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0]*$selected/count($tq['answers']),$questiondecimalpoints,".","");
				endif;
				$NREQ='
				<table border="0" style="width:100%;"><tr><td><b>'.$nb_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_gapselect').'</td></tr></table><br />'.$question.'<br /><br />
				<table style="margin-left: auto; margin-right: auto;" class="table">
				<tr>
				<th style="font-size: 10pt;">'.get_string('points_available','quiz_nitroreportpdf').'</th>
				<th style="font-size: 10pt;">'.get_string('gained_points','quiz_nitroreportpdf').'</th>
				</tr>
				<tr>
				<td style="text-align: center;font-size: 10pt;">'.$tq['points'][0].'</td>
				<td style="text-align: center;font-size: 10pt;">'.$tab_users[$users->userid]['attempt'][$tq['qid']].'</td>
				</tr>
				</table>';
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;				
			break;				

			case 'ddimageortext':
				$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format(0,$questiondecimalpoints,".","");
				$xxx1 = $DB->get_records_sql('SELECT qas.id AS id FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.sequencenumber IN (0,1) ORDER BY qas.id ASC');
				$xxx2=array_keys($xxx1);			
				$xxx_questions=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name LIKE "_choiceorder%"');				
				$xxx_answers_orders=$DB->get_records_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name LIKE "p%" ORDER BY id ASC');				
				$ttab1=null;
				$ttab1=explode(',',$xxx_questions->value);
				$question=$tq['question'];	
				$ttab2=null;
				$data=null;
				$tab_text=array();
				$tab_image=array();
				$data['ddimageortext_bigfile']= $tq['ddimageortext_bigfile'];
				foreach($xxx_answers_orders AS $xao):	
					$ttab2[]=$xao->value;
				endforeach;
				$selected=0;
				for($z=0;$z<count($ttab1);$z++):
					if(($ttab2[$z]-1) < 0):
						$tab_text[]=array('x'=> $tq['answers'][$ttab1[$ttab2[$z]-1]-1]['x'],'y'=> $tq['answers'][$ttab1[$ttab2[$z]-1]-1]['y'],'text'=> 'XXXXX');
					elseif(($ttab1[$ttab2[$z]-1])==$tq['answers'][$z]['choice']):
						if($tq['answers'][$ttab1[$ttab2[$z]-1]-1]['type'] == "text"):
							$tab_text[]=array('x'=> $tq['answers'][$ttab1[$ttab2[$z]-1]-1]['x'],'y'=> $tq['answers'][$ttab1[$ttab2[$z]-1]-1]['y'],'text'=> $tq['answers'][$ttab1[$ttab2[$z]-1]-1]['lab_img']);
						else:
							$tab_image[]=array('x'=> $tq['answers'][$ttab1[$ttab2[$z]-1]-1]['x'],'y'=> $tq['answers'][$ttab1[$ttab2[$z]-1]-1]['y'],'filename' => $tq['answers'][$ttab1[$ttab2[$z]-1]-1]['lab_img']);
						endif;	
						$selected++;
					else:
						$tab_text[]=array('x'=> $tq['answers'][$ttab1[$ttab2[$z]-1]-1]['x'],'y'=> $tq['answers'][$ttab1[$ttab2[$z]-1]-1]['y'],'text'=> 'XXXXX');
					endif;
				endfor;
				if($MODE_NOTE == 1):
					if(count(array_diff($tq['answers'],$ttab2))==0):
						$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0],$questiondecimalpoints,".","");
					endif;
				else:
					$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0]*$selected/count($tq['answers']),$questiondecimalpoints,".","");
				endif;
				$data['texts']=json_encode($tab_text);
				$data['images']=json_encode($tab_image);
				$data['filename']='_U'.$userid.'_Q'.$quizid.'_'.strtotime('now').uniqid().uniqid().'.jpg';
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $CFG->wwwroot.'/mod/quiz/report/nitroreportpdf/image.php');
				curl_setopt($ch, CURLOPT_HEADER, 0);
				curl_setopt($ch, CURLOPT_POST, 1);
				curl_setopt($ch, CURLOPT_POSTFIELDS,$data);
				curl_setopt($ch, CURLOPT_TIMEOUT, 60);
				curl_exec($ch);
				curl_close($ch);
				$NREQ='
				<table border="0" style="width:100%;"><tr><td><b>'.$nb_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_ddimageortext').'</td></tr></table><br />'.$tq['question'].'<br /><img src="report/nitroreportpdf/cache/'.$data['filename'].'" /><br /><br />
				<table style="margin-left: auto; margin-right: auto;" class="table">
				<tr>
					<th style="font-size: 10pt;">'.get_string('points_available','quiz_nitroreportpdf').'</th>
					<th style="font-size: 10pt;">'.get_string('gained_points','quiz_nitroreportpdf').'</th>
				</tr>
				<tr>
					<td style="text-align: center;font-size: 10pt;">'.$tq['points'][0].'</td>
					<td style="text-align: center;font-size: 10pt;">'.$tab_users[$users->userid]['attempt'][$tq['qid']].'</td>
				</tr>
				</table>';
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;
			break;
	
			case 'multichoice':
				$tab_users[$users->userid]['attempt'][$tq['qid']]=0;
				$multi_tb = $DB->get_record_sql('SELECT single FROM {qtype_multichoice_options} WHERE questionid="'.$tq['qid'].'"');
				$xxx1 = $DB->get_records_sql('SELECT qas.id AS id FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.sequencenumber IN (0,1) ORDER BY qas.id ASC');
				$xxx2=array_keys($xxx1);
				$xxx3_tab_bin=array();
				$count_corrected=0;
				foreach($tq['qanswers'] as $id => $a):
					if($a==1):
						$count_corrected++;
					endif;
				endforeach;
				if($multi_tb->single==1):
					$questiontypemultichoice=get_string('questiontypemultichoiceone','quiz_nitroreportpdf');
					$xxx3=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="_order"');
					$xxx4=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name="answer"');
					$xxx3_u=$xxx3->value;
					///contains user's answers in binary format . this table must be sorting by id
					$xxx3_tab=explode(',',$xxx3_u);
					if((!is_object($xxx4))):
						$xxx4 = new StdClass;
						$xxx4->id=-1;
						$xxx4->value=-1;
					endif;
					for($z=0;$z<count($xxx3_tab);$z++):
						//specify place in options
						if($z==$xxx4->value):
							$xxx3_tab_bin[$xxx3_tab[$z]]=1;
						else:
							$xxx3_tab_bin[$xxx3_tab[$z]]=0;
						endif;
					endfor;
					if((!isset($xxx4->value))):
						$xxx4->value=-1;
					endif;
					for($z=0;$z<count($xxx3_tab);$z++):
						//specify place in options
						if($z==$xxx4->value):
							$xxx3_tab_bin[$xxx3_tab[$z]]=1;
						else:
							$xxx3_tab_bin[$xxx3_tab[$z]]=0;
						endif;
					endfor;
					$xxx3_tab_bin_ids=$this->quick_sort(array_keys($xxx3_tab_bin));
					$xxx3_tab_bin2=array();
					for($z=0;$z<count($xxx3_tab_bin_ids);$z++):
						$xxx3_tab_bin2[$xxx3_tab_bin_ids[$z]]=$xxx3_tab_bin[$xxx3_tab_bin_ids[$z]];
					endfor;
					$xxx3_tab_bin=$xxx3_tab_bin2;
					$corrected=0;
					$user_asked='';
					$odp=0;
					foreach($xxx3_tab_bin as $id => $bin):
						$multiple=$bin*$tq[qanswers][$id];
						if($multiple == 1):
							$corrected++;
						endif;
						if(($bin == 1)&&($bin != $tq[qanswers][$id])):
							$user_asked.='<span style="color: red;"><b>[X]</b></span> ';
						elseif(($bin == 1)&&($bin == $tq[qanswers][$id])):
							$user_asked.='<span style="color: blue;"><b>[X]</b></span> ';
						endif;
						$user_asked.='<b>'.chr(65+$odp).".</b> &nbsp;";
						$user_asked.=$tq[answers][$id].'<br />';
						$odp++;
					endforeach;
					$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0]*$corrected/$count_corrected,$questiondecimalpoints,".","");
				elseif(($multi_tb->single==0)&&(count($xxx2)>0)):
					$questiontypemultichoice=get_string('questiontypemultichoicemulti','quiz_nitroreportpdf');
					$xxx3=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="_order"');
					$xxx4=$DB->get_records_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name LIKE "choice%" ORDER BY name ASC');
					$xxx3_u=$xxx3->value;
					$xxx3_tab=explode(',',$xxx3_u);
					$xxx4_tab_bin=array();
					$xxx4_ids=array_keys($xxx4);
					for($z=0;$z<count($xxx4_ids);$z++):
						$xxx4_tab_bin[]=$xxx4[$xxx4_ids[$z]]->value;
					endfor;
					$xxx4_tab_bin_temp=array();
					if(count($xxx4)==0):
						for($y=0;$y<count($tq['qanswers']);$y++):
							$xxx4_tab_bin[]=0;
						endfor;
					endif;
					for($z=0;$z<count($xxx3_tab);$z++):
						$xxx4_tab_bin_temp[$xxx3_tab[$z]]=$xxx4_tab_bin[$z];
					endfor;
					$xxx4_tab_bin_temp_ids=$this->quick_sort(array_keys($xxx4_tab_bin_temp));
					$xxx4_tab_bin_temp2=array();
					for($z=0;$z<count($xxx4_tab_bin_temp_ids);$z++):
						$xxx4_tab_bin_temp2[$xxx4_tab_bin_temp_ids[$z]]=$xxx4_tab_bin_temp[$xxx4_tab_bin_temp_ids[$z]];
					endfor;
					$xxx3_tab_bin=$xxx4_tab_bin_temp2;
					unset($xxx4_tab_bin_temp2);
					$corrected=0;
					$user_asked='';
					$odp=0;
					foreach($xxx3_tab_bin as $id => $bin):
						$multiple=$bin*$tq[qanswers][$id];
						if($multiple == 1):
							$corrected++;
						endif;
						if(($bin == 1)&&($bin != $tq[qanswers][$id])):
							$user_asked.='<span style="color: red;"><b>[X]</b></span> ';
						elseif(($bin == 1)&&($bin == $tq[qanswers][$id])):
							$user_asked.='<span style="color: blue;"><b>[X]</b></span> ';
						endif;
						$user_asked.='<b>'.chr(65+$odp).".</b> &nbsp;";
						$user_asked.=$tq[answers][$id].'<br />';
						$odp++;
					endforeach;
				endif;
				if($MODE_NOTE == 1):
					if($corrected==$count_corrected):
						$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0],$questiondecimalpoints,".","");
					endif;
				else:
					$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0]*$corrected/$count_corrected,$questiondecimalpoints,".","");
				endif;
				$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nb_question.'.</b></td><td style="text-align:right;">'.$questiontypemultichoice.'</td></tr></table><br />'.$tq['question'].'<br /><br />'.$user_asked.'<br />
				<table style="margin-left: auto; margin-right: auto;" class="table">
				<tr>
				<th style="font-size: 10pt;">'.get_string('points_available','quiz_nitroreportpdf').'</th>
				<th style="font-size: 10pt;">'.get_string('gained_points','quiz_nitroreportpdf').'</th>
				</tr>
				<tr>
				<td style="text-align: center;font-size: 10pt;">'.$tq['points'][0].'</td>
				<td style="text-align: center;font-size: 10pt;">'.$tab_users[$users->userid]['attempt'][$tq['qid']].'</td>
				</tr>
				</table>';
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;
			break;		

			case 'multianswer':	
				$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format(0,$questiondecimalpoints,".","");
				$points=0;
				$question=$tq['question'];
				$xxx1 = $DB->get_records_sql('SELECT qas.id AS id FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.sequencenumber IN (0,1) ORDER BY qas.id ASC');
				$xxx2=array_keys($xxx1);		
				$xxx_questions=$DB->get_records_sql('SELECT id,name,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name LIKE "_sub%_order"');		
				foreach($xxx_questions AS $id => $questions):		
					preg_match('/_sub(.*)_order/',$questions->name,$number_question_db);
					$number_question_db=$number_question_db[1];
					$xxx_resp_exist=$DB->get_record_sql('SELECT count(*) as how FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name="sub'.$number_question_db.'_answer"');		
					if($xxx_resp_exist->how > 0):
						$xxx_resp=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name="sub'.$number_question_db.'_answer"');		
						$ttab1=null;
						$ttab1=explode(',',$questions->value);
						$index=$ttab1[$xxx_resp->value];
						$index2=$tq['answers'][$number_question_db-1]['answers_id'][$index];
						if($index2 == $tq['answers'][$number_question_db-1]['correct']):
							$question=preg_replace('/\{#'.($number_question_db).'\}/','<span style="color:green;font-weight: bold;">'.$tq['answers'][$number_question_db-1]['answers'][$index2].'</span>',$question);
							$points+=$tq['answers'][$number_question_db-1]['points'][$index2];
						else:
							$question=preg_replace('/\{#'.($number_question_db).'\}/','<span style="color:red;font-weight: bold;">'.$tq['answers'][$number_question_db-1]['answers'][$index2].'</span>',$question);
							$points+=$tq['answers'][$number_question_db-1]['points'][$index2];
						endif;
					else:	
						$question=preg_replace('/\{#'.($number_question_db).'\}/','<span style="color:red;font-weight: bold;">___</span>',$question);
					endif;
				endforeach;		
				preg_match_all('/\{#([0-9]+)\}/',$question,$rest);
				if(count($rest[1])>0):
					for($i=0;$i<count($rest[1]);$i++):
						$xxx_resp=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name="sub'.$rest[1][$i].'_answer"');
						for($j=0;$j<count($tq['answers'][$rest[1][$i]-1]['answers']);$j++):
							if($tq['answers'][$rest[1][$i]-1]['type'][$j] == 'range'):
								$ex=explode('-',$tq['answers'][$rest[1][$i]-1]['answers'][$j]);
								if(($xxx_resp->value>=$ex[0])&&($xxx_resp->value<=$ex[1])):
									$points+=$tq['answers'][$rest[1][$i]-1]['points'][$j];
									if($tq['answers'][$rest[1][$i]-1]['points'][$j]==0):
										$question=preg_replace('/\{#'.($rest[1][$i]).'\}/','<span style="color:red;font-weight: bold;">'.$xxx_resp->value.'</span>',$question);
										break;
									else:
										$question=preg_replace('/\{#'.($rest[1][$i]).'\}/','<span style="color:blue;font-weight: bold;">'.$xxx_resp->value.'</span>',$question);
										break;
									endif;
								else:
									$question=preg_replace('/\{#'.($rest[1][$i]).'\}/','<span style="color:red;font-weight: bold;">'.$xxx_resp->value.'</span>',$question);
								endif;
							else:
								$points+=$tq['answers'][$rest[1][$i]-1]['points'][$j];			
								if($xxx_resp->value == $tq['answers'][$rest[1][$i]-1]['answers'][$j]):
									$question=preg_replace('/\{#'.($rest[1][$i]).'\}/','<span style="color:green;font-weight: bold;">'.$xxx_resp->value.'</span>',$question);
									break;
								else:
									$question=preg_replace('/\{#'.($rest[1][$i]).'\}/','<span style="color:red;font-weight: bold;">'.$xxx_resp->value.'</span>',$question);
									break;
								endif;
							endif;
						endfor;
					endfor;
				endif;
				$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($points,$questiondecimalpoints,".","");
				$NREQ='
				<table border="0" style="width:100%;"><tr><td><b>'.$nb_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_multianswer').'</td></tr></table><br />'.$question.'<br /><br />
				<table style="margin-left: auto; margin-right: auto;" class="table">
					<tr>
						<th style="font-size: 10pt;">'.get_string('points_available','quiz_nitroreportpdf').'</th>
						<th style="font-size: 10pt;">'.get_string('gained_points','quiz_nitroreportpdf').'</th>
					</tr>
					<tr>
						<td style="text-align: center;font-size: 10pt;">'.$tq['points'][0].'</td>
						<td style="text-align: center;font-size: 10pt;">'.$tab_users[$users->userid]['attempt'][$tq['qid']].'</td>
					</tr>
				</table>';	
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;
			break;

			case 'ddwtos':
				$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format(0,$questiondecimalpoints,".","");
				$question=$tq['question'];
				$xxx1 = $DB->get_records_sql('SELECT qas.id AS id FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.sequencenumber IN (0,1) ORDER BY qas.id ASC');
				$xxx2=array_keys($xxx1);			
				$xxx_questions=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name LIKE "_choiceorder%"');				
				$xxx_answers_orders=$DB->get_records_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name LIKE "p%" ORDER BY id ASC');				
				$ttab1=null;
				$ttab1=explode(',',$xxx_questions->value);
				$question=$tq['question'];	
				$ttab2=null;
				foreach($xxx_answers_orders AS $xao):	
					$ttab2[]=$xao->value;
				endforeach;	
				$selected=0;
				for($z=0;$z<count($ttab2);$z++):
					if($ttab2[$z]<=0):
						$question=preg_replace('/\[\['.($z+1).'\]\]/','<span style="color:red;font-weight: bold;">___</span>',$question);
					else:
						if($tq['answers'][($z+1)] == $tq['answers'][$ttab1[$ttab2[$z]-1]]):
							$question=preg_replace('/\[\['.($z+1).'\]\]/','<span style="color:blue;font-weight: bold;">'.$tq['answers'][$ttab1[$ttab2[$z]-1]].'</span>',$question);
							$selected++;
						else:
							$question=preg_replace('/\[\['.($z+1).'\]\]/','<span style="color:red;font-weight: bold;">'.$tq['answers'][$ttab1[$ttab2[$z]-1]].'</span>',$question);
						endif;
					endif;
				endfor;
				if($MODE_NOTE == 1):
					if($selected == count($tq['answers'])):
						$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0],$questiondecimalpoints,".","");
					endif;
				else:
					$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0]*$selected/count($tq['answers']),$questiondecimalpoints,".","");
				endif;			
				$NREQ='
				<table border="0" style="width:100%;"><tr><td><b>'.$nb_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_ddwtos').'</td></tr></table><br />'.$question.'<br /><br />
				<table style="margin-left: auto; margin-right: auto;" class="table">
				<tr>
				<th style="font-size: 10pt;">'.get_string('points_available','quiz_nitroreportpdf').'</th>
				<th style="font-size: 10pt;">'.get_string('gained_points','quiz_nitroreportpdf').'</th>
				</tr>
				<tr>
				<td style="text-align: center;font-size: 10pt;">'.$tq['points'][0].'</td>
				<td style="text-align: center;font-size: 10pt;">'.$tab_users[$users->userid]['attempt'][$tq['qid']].'</td>
				</tr>
				</table>';
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;
			break;

			case 'match':
				$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format(0,$questiondecimalpoints,".","");
				$xxx1 = $DB->get_records_sql('SELECT qas.id AS id FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.sequencenumber IN (0,1) ORDER BY qas.id ASC');
				$xxx2=array_keys($xxx1);
				$db_quest_order=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="_stemorder"');
				$db_answ_order=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="_choiceorder"');
				$db_answ_order2=$DB->get_records_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name LIKE "sub%"');
				$selected=0;
				$question='';
				$question.='<table style="border-bottom:1px solid black;border-left:1px solid black;border-right:1px solid black;" cellspacing="0" cellpadding="0">';
				$db_quest_order_id=explode(',',$db_quest_order->value);
				$db_answ_order_id=explode(',',$db_answ_order->value);
				$db_answ_order_id_order=array();
				foreach($db_answ_order2 AS $id => $low):
					$db_answ_order_id_order[]=$low->value;
				endforeach;
				for($p=0;$p<count($db_quest_order_id);$p++):
					$question.='<tr><td style="border-right:1px solid black;border-top:1px solid black;padding:5px;">'.$tq['answers'][$db_quest_order_id[$p]]['question'].'</td>';
					$idorder=$db_answ_order_id_order[$p];
					if($idorder == 0):
						$question.='<td style="color:blue;font-weight: bold;border-top:1px solid black;padding:5px;">&nbsp;&nbsp;&nbsp;</td>';
					else:
						if($db_quest_order_id[$p] == $db_answ_order_id[$idorder-1]):
							$selected++;
							$question.='<td style="color:blue;font-weight: bold;border-top:1px solid black;padding:5px;">'.$tq['answers'][$db_answ_order_id[$idorder-1]]['answer'].'</td>';
						else:
							$question.='<td style="color:red;font-weight: bold;border-top:1px solid black;padding:5px;">'.$tq['answers'][$db_answ_order_id[$idorder-1]]['answer'].'</td>';
						endif;
					endif;
					$question.='</tr>';
				endfor;
				$question.='</table>';
				if($MODE_NOTE == 1):
					for($p=0;$p<count($db_quest_order_id);$p++):
						$selected2=0;
						$idorder=$db_answ_order_id_order[$p];
						if($db_quest_order_id[$p] == $db_answ_order_id[$idorder-1]):
							$selected2++;
						endif;
					endfor;
					if($selected2==count($db_answ_order_id)):
						$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0],$questiondecimalpoints,".","");
					endif;
				else:
					$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0]*$selected/count($db_answ_order_id),$questiondecimalpoints,".","");
				endif;
				$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nb_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_match').'</td></tr></table><br />'.$question.'<br /><br />
				<table style="margin-left: auto; margin-right: auto;" class="table">
				<tr>
				<th style="font-size: 10pt;">'.get_string('points_available','quiz_nitroreportpdf').'</th>
				<th style="font-size: 10pt;">'.get_string('gained_points','quiz_nitroreportpdf').'</th>
				</tr>
				<tr>
				<td style="text-align: center;font-size: 10pt;">'.$tq['points'][0].'</td>
				<td style="text-align: center;font-size: 10pt;">'.$tab_users[$users->userid]['attempt'][$tq['qid']].'</td>
				</tr>
				</table>';
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;
			break;

			case 'ddmatch':
				$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format(0,$questiondecimalpoints,".","");
				$xxx1 = $DB->get_records_sql('SELECT qas.id AS id FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.sequencenumber IN (0,1) ORDER BY qas.id ASC');
				$xxx2=array_keys($xxx1);
				$xxx_questions=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="_stemorder"');
				$xxx_answers=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="_choiceorder"');
				$xxx_answers_orders=$DB->get_records_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name LIKE "sub%"');
				$tab_questions_answers=array();
				$tab_questions_answers1=array();
				$ttab1=null;
				$ttab1=explode(',',$xxx_questions->value);
				$ttab2=null;
				$ttab2=explode(',',$xxx_answers->value);
				$ttab3=null;
				foreach($xxx_answers_orders AS $id => $orders):
					$ttab3[]=$ttab2[($orders->value-1)];
				endforeach;
				for($i=0;$i<count($ttab1);$i++):
					$tab_questions_answers[$ttab1[$i]]=$ttab3[$i];
				endfor;
				$tab_questions_answers_keys=$this->quick_sort(array_keys($tab_questions_answers));
				for($i=0;$i<count($tab_questions_answers_keys);$i++):
					$tab_questions_answers1[$tab_questions_answers_keys[$i]]=$tab_questions_answers[$tab_questions_answers_keys[$i]];
				endfor;
				$tab_questions_answers=$tab_questions_answers1;
				/* ID question = ID answer = OK. */
				$corrected=0;
				foreach($tab_questions_answers AS $id => $answer):
					if($id == $answer):
						$corrected++;
					endif;
				endforeach;
				if($MODE_NOTE == 1):
					if( count($tab_questions_answers) == $corrected ):
						$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0],$questiondecimalpoints,".","");
					endif;
				else:
					$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0]*$corrected/count($tab_questions_answers),$questiondecimalpoints,".","");
				endif;
				$answer='<table border="1" style="margin-left: auto; margin-right: auto;"><tr><th>'.get_string('question2','quiz_nitroreportpdf').'</th><th>'.get_string('answer','quiz_nitroreportpdf').'</th><th>'.get_string('corrected_question','quiz_nitroreportpdf').'</th></tr>';
				foreach($tab_questions_answers AS $id => $ans):
					if($id == $ans):
							$answer.='<tr><td>'.$tq['questions'][$id].'</td><td>'.$tq['answers'][$ans].'</td><td style="text-align: center;">'.get_string('yes','quiz_nitroreportpdf').'</td></tr>';
					else:
							$answer.='<tr><td>'.$tq['questions'][$id].'</td><td>'.$tq['answers'][$ans].'</td><td style="text-align: center;">'.get_string('no','quiz_nitroreportpdf').'</td></tr>';
					endif;
				endforeach;
				$answer.='</table>';
				$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nb_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_ddmatch').'</td></tr></table><br />'.$tq['question'].'<br /><br />'.$answer.'<br /><br /><table style="margin-left: auto; margin-right: auto;" class="table"><tr><th style="font-size: 10pt;">'.get_string('points_available','quiz_nitroreportpdf').'</th><th style="font-size: 10pt;">'.get_string('gained_points','quiz_nitroreportpdf').'</th></tr><tr><td style="text-align: center;font-size: 10pt;">'.$tq['points'][0].'</td><td style="text-align: center;font-size: 10pt;">'.$tab_users[$users->userid]['attempt'][$tq['qid']].'</td></tr></table>';
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;
			break;

			case 'ordering':	
				$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format(0,$questiondecimalpoints,".","");	
				$xxx1 = $DB->get_records_sql('SELECT qas.id AS id FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.sequencenumber IN (0,1) ORDER BY qas.id ASC');	
				$xxx2=array_keys($xxx1);
				$db_quest_order=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="_correctresponse"');
				$db_answ_order=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name="response_'.$tq['qid'].'"');
				$db_answ_order=preg_replace('/ordering_item_/','',$db_answ_order->value);
				$db_answ_order=explode(',',$db_answ_order);
				$ttab1=null;
				$ttab1=explode(',',$db_quest_order->value);
				$ttab2=null;
				for($i=0;$i<count($db_answ_order);$i++):
					$ttab2[]=$tq['answers_md5'][$db_answ_order[$i]];			
				endfor;	
				$selected=0;
				$question=$tq['question'].'<br><br>';
				for($i=0;$i<count($ttab2);$i++):
					if($ttab1[$i] == $ttab2[$i]):
						$question.='<span style="color:blue;font-weight: bold;">'.$tq['answers'][$ttab2[$i]].'</span><br><hr><br>';
						$selected++;
					else:
						$question.='<span style="color:red;font-weight: bold;">'.$tq['answers'][$ttab2[$i]].'</span><br><hr><br>';
					endif;
				endfor;
				$question=substr($question,0,-12);
				if($MODE_NOTE == 1):
					$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0],$questiondecimalpoints,".","");
				else:
					$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0]*$selected/count($ttab1),$questiondecimalpoints,".","");
				endif;
				$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nb_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_ordering').'</td></tr></table><br />'.$question.'<br /><br />
				<table style="margin-left: auto; margin-right: auto;" class="table">
				<tr>
				<th style="font-size: 10pt;">'.get_string('points_available','quiz_nitroreportpdf').'</th>
				<th style="font-size: 10pt;">'.get_string('gained_points','quiz_nitroreportpdf').'</th>
				</tr>
				<tr>
				<td style="text-align: center;font-size: 10pt;">'.$tq['points'][0].'</td>
				<td style="text-align: center;font-size: 10pt;">'.$tab_users[$users->userid]['attempt'][$tq['qid']].'</td>
				</tr>
				</table>';
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;
			break;
			
			case 'gapfill':			
				$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format(0,$questiondecimalpoints,".","");			
				$xxx1 = $DB->get_records_sql('SELECT qas.id AS id FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.sequencenumber IN (1) ORDER BY qas.id ASC');	
				$xxx2=array_keys($xxx1);
				$db_answ_order=$DB->get_records_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name LIKE "p%"');		
				$ttab2=null;
				foreach($db_answ_order AS $db_answ_order):
					$ttab2[]=$db_answ_order->value;
				endforeach;
				$question=$tq['question'];			
				$selected=0;			
				preg_match_all('/\\'.(substr($tab_quiz[$q->q_idq]['options'][0],0,1)).'(.*)\\'.(substr($tab_quiz[$q->q_idq]['options'][0],1,1)).'/U',$tq['question'],$founded);
				for($i=0;$i<count($tq['answers']);$i++):			
					if($tq['answers'][$i] == $ttab2[$i]):
						$question=preg_replace('/\\'.(substr($tab_quiz[$q->q_idq]['options'][0],0,1)).$founded[1][$i].'\\'.(substr($tab_quiz[$q->q_idq]['options'][0],1,1)).'/','<span style="color:blue;font-weight: bold;">'.$ttab2[$i].'</span>',$question);
						$selected++;
					else:
						$question=preg_replace('/\\'.(substr($tab_quiz[$q->q_idq]['options'][0],0,1)).$founded[1][$i].'\\'.(substr($tab_quiz[$q->q_idq]['options'][0],1,1)).'/','<span style="color:red;font-weight: bold;">'.$ttab2[$i].'</span>',$question);
					endif;	
				endfor;	
				if($MODE_NOTE == 1):
					$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0],$questiondecimalpoints,".","");
				else:
					$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0]*$selected/count($tq['answers']),$questiondecimalpoints,".","");
				endif;
				$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nb_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_gapfill').'</td></tr></table><br />'.$question.'<br /><br />
				<table style="margin-left: auto; margin-right: auto;" class="table">
				<tr>
				<th style="font-size: 10pt;">'.get_string('points_available','quiz_nitroreportpdf').'</th>
				<th style="font-size: 10pt;">'.get_string('gained_points','quiz_nitroreportpdf').'</th>
				</tr>
				<tr>
				<td style="text-align: center;font-size: 10pt;">'.$tq['points'][0].'</td>
				<td style="text-align: center;font-size: 10pt;">'.$tab_users[$users->userid]['attempt'][$tq['qid']].'</td>
				</tr>
				</table>';
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;				
			break;
			
// WIRIS QUESTIONS *** WIRIS QUESTIONS *** WIRIS QUESTIONS
			case 'truefalsewiris':
				$question=$tq['question'];			
				$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format(0,$questiondecimalpoints,".","");	
				$xxx1 = $DB->get_records_sql('SELECT qas.id AS id FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.sequencenumber IN (0,1) ORDER BY qas.id ASC');	
				$xxx2=array_keys($xxx1);			
				$db_answ=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="_qi"');
				$db_answ2=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name="answer"');
				$db_answ=$db_answ->value;
				$db_answ2=$db_answ2->value;
				preg_match_all('/#([a-zA-Z0-9\-\.]+)/',$tq['question'],$pl);
				$pl=$pl[1];
				for($i=0;$i<count($pl);$i++):
					preg_match('/<variable name="'.$pl[$i].'" type="(.*)">(.*)<\/variable>/U',$db_answ,$pl2);
					switch($pl2[1]):
						case 'mathml':
							$var=$pl2[2];
							$var=preg_replace('/<!\[CDATA\[/','',$var);
							$var=preg_replace('/\]\]>/','',$var);
							$question=preg_replace('/#'.$pl[$i].'/',$var,$question);
						break;
						case 'imageref':
							$var=$WIRIS_URL_IMAGE_SERVICE.$pl2[2];
							$file_md5='wiris_'.md5($var).'.png';
							if(file_exists($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5)):
								touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5);
							else:
								$ch = curl_init();
								curl_setopt($ch,CURLOPT_URL,$var);
								curl_setopt($ch,CURLOPT_RETURNTRANSFER,true); 
								curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false); 
								curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,20);  
								curl_setopt($ch,CURLOPT_MAXREDIRS,10); 
								curl_setopt($ch,CURLOPT_TIMEOUT,600);
								$req=curl_exec($ch);
								curl_close($ch);
								file_put_contents($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5,$req);
							endif;
							$question=preg_replace('/#'.$pl[$i].'/','<img src="'.$CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5.'">',$question);
							touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5);
						break;
					endswitch;
				endfor;
				$anscolor='';
				if($tq['answers'][0] == 0):
					$answer='True';
				else:
					$answer='False';
				endif;
				if($db_answ2 == ""):
					$anscolor='<span style="color:red;font-weight: bold;">'.get_string('noanswer','quiz_nitroreportpdf').'</span>';
				elseif($db_answ2 == $tq['answers'][0]):
					$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0],$questiondecimalpoints,".","");
					$tab_users[$users->userid]['answers'][$tq['qid']]['answer'][0]=$db_answ2;
					$anscolor='<span style="color:blue;font-weight: bold;">'.$answer.'</span>';
				else:
					$anscolor='<span style="color:red;font-weight: bold;">'.$answer.'</span>';
				endif;
				$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nb_question.'.</b></td><td style="text-align:right;">WIRIS - '.get_string('pluginname','qtype_truefalsewiris').'</td></tr></table><br />'.$question.'<br /><u>'.get_string('answered','quiz_nitroreportpdf').':</u> '.$anscolor.'<br /><br />
				<table style="margin-left: auto; margin-right: auto;" class="table">
				<tr>
				<th style="font-size: 10pt;">'.get_string('points_available','quiz_nitroreportpdf').'</th>
				<th style="font-size: 10pt;">'.get_string('gained_points','quiz_nitroreportpdf').'</th>
				</tr>
				<tr>
				<td style="text-align: center;font-size: 10pt;">'.$tq['points'][0].'</td>
				<td style="text-align: center;font-size: 10pt;">'.$tab_users[$users->userid]['attempt'][$tq['qid']].'</td>
				</tr>
				</table>';
				//write to HTML FILE


				//PDF
				preg_match_all('/<math.*>(.*)<\/math>/U',$NREQ,$math);
				$math=$math[0];
				for($i=0;$i<count($math);$i++):
					$req=$this->latexmlfunctions('mathml2image',urlencode($math[$i]));
					if($req != "@500"):
						$NREQ=preg_replace('/<math.*>.*<\/math>/U','<img src="'.$req.'">',$NREQ);
					else:
						$req=$this->latexmlfunctions('mathml2latex',urlencode($math[$i]));
						if($req != "@500"):
							$req=$this->latexmlfunctions('latex2image',urlencode($req));
							if($req != "@500"):
								$NREQ=preg_replace('/<math.*>.*<\/math>/U','<img src="'.$req.'">',$NREQ);
							endif;
						endif;
					endif;
				endfor;
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;
			break;	
		
			case 'matchwiris':		
				$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format(0,$questiondecimalpoints,".","");
				$xxx1 = $DB->get_records_sql('SELECT qas.id AS id FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.sequencenumber IN (0,1) ORDER BY qas.id ASC');
				$xxx2=array_keys($xxx1);		
				$db_quest_order=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="_stemorder"');
				$db_answ_order=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="_choiceorder"');
				$db_answ_order2=$DB->get_records_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name LIKE "sub%"');
				$db_answ=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="_qi"');
				$db_answ=$db_answ->value;
				//question
				$question=$tq['question'];	
				preg_match_all('/#([a-zA-Z0-9\-\.]+)/',$tq['question'],$pl);
				$pl=$pl[1];
				for($i=0;$i<count($pl);$i++):
					preg_match('/<variable name="'.$pl[$i].'" type="(.*)">(.*)<\/variable>/U',$db_answ,$pl2);
					switch($pl2[1]):
						case 'mathml':
							$var=$pl2[2];
							$var=preg_replace('/<!\[CDATA\[/','',$var);
							$var=preg_replace('/\]\]>/','',$var);
							$question=preg_replace('/#'.$pl[$i].'/',$var,$question);
						break;
						case 'imageref':
							$var=$WIRIS_URL_IMAGE_SERVICE.$pl2[2];
							$file_md5='wiris_'.md5($var).'.png';
							if(file_exists($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5)):
								touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5);
							else:
								$ch = curl_init();
								curl_setopt($ch,CURLOPT_URL,$var);
								curl_setopt($ch,CURLOPT_RETURNTRANSFER,true); 
								curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false); 
								curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,20);  
								curl_setopt($ch,CURLOPT_MAXREDIRS,10); 
								curl_setopt($ch,CURLOPT_TIMEOUT,600);
								$req=curl_exec($ch);
								curl_close($ch);
								file_put_contents($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5,$req);
							endif;
							$question=preg_replace('/#'.$pl[$i].'/','<img src="'.$CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5.'">',$question);
							touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5);
						break;
					endswitch;
				endfor;
				$answers='';
				$copy_answers=$tq['answers'];
				foreach($copy_answers AS $ID => $ANS):
					$an=$ANS['answer'];
					$qs=$ANS['question'];	
					//QS		
					preg_match_all('/#([a-zA-Z0-9\-\.]+)/',$qs,$plX);
					$plX=$plX[1];
					for($i=0;$i<count($plX);$i++):
						preg_match('/<variable name="'.$plX[$i].'" type="(.*)">(.*)<\/variable>/U',$db_answ,$pl2X);
						switch($pl2X[1]):
							case 'mathml':
								$varX=$pl2X[2];
								$varX=preg_replace('/<!\[CDATA\[/','',$varX);
								$varX=preg_replace('/\]\]>/','',$varX);
								$qs=preg_replace('/#'.$plX[$i].'/',$varX,$qs);
							break;
							case 'imageref':
								$varX=$WIRIS_URL_IMAGE_SERVICE.$pl2X[2];
								$file_md5='wiris_'.md5($varX).'.png';
								if(file_exists($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5)):
									touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5);
								else:
									$ch = curl_init();
									curl_setopt($ch,CURLOPT_URL,$varX);
									curl_setopt($ch,CURLOPT_RETURNTRANSFER,true); 
									curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false); 
									curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,20);  
									curl_setopt($ch,CURLOPT_MAXREDIRS,10); 
									curl_setopt($ch,CURLOPT_TIMEOUT,600);
									$req=curl_exec($ch);
									curl_close($ch);
									file_put_contents($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5,$req);
								endif;
								$qs=preg_replace('/#'.$pl[$i].'/','<img src="'.$CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5.'">',$qs);
								touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5);
							break;
						endswitch;
					endfor;	

					//AN		
					preg_match_all('/#([a-zA-Z0-9\-\.]+)/',$an,$plX);
					$plX=$plX[1];
					for($i=0;$i<count($plX);$i++):
						preg_match('/<variable name="'.$plX[$i].'" type="(.*)">(.*)<\/variable>/U',$db_answ,$pl2X);
						switch($pl2X[1]):
							case 'mathml':
								$varX=$pl2X[2];
								$varX=preg_replace('/<!\[CDATA\[/','',$varX);
								$varX=preg_replace('/\]\]>/','',$varX);
								$an=preg_replace('/#'.$plX[$i].'/',$varX,$an);
							break;
							case 'imageref':
								$varX=$WIRIS_URL_IMAGE_SERVICE.$pl2X[2];
								$file_md5='wiris_'.md5($varX).'.png';
								if(file_exists($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5)):
									touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5);
								else:
									$ch = curl_init();
									curl_setopt($ch,CURLOPT_URL,$varX);
									curl_setopt($ch,CURLOPT_RETURNTRANSFER,true); 
									curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false); 
									curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,20);  
									curl_setopt($ch,CURLOPT_MAXREDIRS,10); 
									curl_setopt($ch,CURLOPT_TIMEOUT,600);
									$req=curl_exec($ch);
									curl_close($ch);
									file_put_contents($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5,$req);
								endif;
								$an=preg_replace('/#'.$pl[$i].'/','<img src="'.$CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5.'">',$an);
								touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5);
							break;
						endswitch;
					endfor;	
					$copy_answers[$ID]['question']=$qs;
					$copy_answers[$ID]['answer']=$an;
				endforeach;
				$selected=0;
				$answers.='<table style="border-bottom:1px solid black;border-left:1px solid black;border-right:1px solid black;" cellspacing="0" cellpadding="0">';
				$db_quest_order_id=explode(',',$db_quest_order->value);
				$db_answ_order_id=explode(',',$db_answ_order->value);
				$db_answ_order_id_order=array();
				foreach($db_answ_order2 AS $id => $low):
					$db_answ_order_id_order[]=$low->value;
				endforeach;
				for($p=0;$p<count($db_quest_order_id);$p++):
					$answers.='<tr><td style="border-right:1px solid black;border-top:1px solid black;padding:5px;">'.$copy_answers[$db_quest_order_id[$p]]['question'].'</td>';
					$idorder=$db_answ_order_id_order[$p];
					if($idorder == 0):
						$answers.='<td style="color:blue;font-weight: bold;border-top:1px solid black;padding:5px;">&nbsp;&nbsp;&nbsp;</td>';
					else:
						if($db_quest_order_id[$p] == $db_answ_order_id[$idorder-1]):
							$selected++;
							$answers.='<td style="color:blue;font-weight: bold;border-top:1px solid black;padding:5px;">'.$copy_answers[$db_answ_order_id[$idorder-1]]['answer'].'</td>';
						else:
							$answers.='<td style="color:red;font-weight: bold;border-top:1px solid black;padding:5px;">'.$copy_answers[$db_answ_order_id[$idorder-1]]['answer'].'</td>';
						endif;
					endif;
					$answers.='</tr>';
				endfor;
				$answers.='</table>';
				if($MODE_NOTE == 1):
					for($p=0;$p<count($db_quest_order_id);$p++):
						$selected2=0;
						$idorder=$db_answ_order_id_order[$p];
						if($db_quest_order_id[$p] == $db_answ_order_id[$idorder-1]):
							$selected2++;
						endif;
					endfor;
					if($selected2==count($db_answ_order_id)):
						$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0],$questiondecimalpoints,".","");
					endif;
				else:
					$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0]*$selected/count($db_answ_order_id),$questiondecimalpoints,".","");
				endif;
				$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nb_question.'.</b></td><td style="text-align:right;">WIRIS - '.get_string('pluginname','qtype_matchwiris').'</td></tr></table><br />'.$question.'<br /><br />'.$answers.'<br /><br />
				<table style="margin-left: auto; margin-right: auto;" class="table">
				<tr>
				<th style="font-size: 10pt;">'.get_string('points_available','quiz_nitroreportpdf').'</th>
				<th style="font-size: 10pt;">'.get_string('gained_points','quiz_nitroreportpdf').'</th>
				</tr>
				<tr>
				<td style="text-align: center;font-size: 10pt;">'.$tq['points'][0].'</td>
				<td style="text-align: center;font-size: 10pt;">'.$tab_users[$users->userid]['attempt'][$tq['qid']].'</td>
				</tr>
				</table>';
				//write to HTML FILE			


				//PDF
				preg_match_all('/<math.*>(.*)<\/math>/U',$NREQ,$math);
				$math=$math[0];
				for($i=0;$i<count($math);$i++):
					$req=$this->latexmlfunctions('mathml2image',urlencode($math[$i]));
					if($req != "@500"):
						$NREQ=preg_replace('/<math.*>.*<\/math>/U','<img src="'.$req.'">',$NREQ);
					else:
						$req=$this->latexmlfunctions('mathml2latex',urlencode($math[$i]));
						if($req != "@500"):
							$req=$this->latexmlfunctions('latex2image',urlencode($req));
							if($req != "@500"):
								$NREQ=preg_replace('/<math.*>.*<\/math>/U','<img src="'.$req.'">',$NREQ);
							endif;
						endif;
					endif;
				endfor;
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;
			break;			
		
			case 'multianswerwiris':		
				$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format(0,$questiondecimalpoints,".","");
				$points=0;
				$question=$tq['question'];
				$xxx1 = $DB->get_records_sql('SELECT qas.id AS id FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.sequencenumber IN (0,1) ORDER BY qas.id ASC');
				$xxx2=array_keys($xxx1);		
				$xxx_questions=$DB->get_records_sql('SELECT id,name,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name LIKE "_sub%_order"');
				$db_answ=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="_qi"');
				$db_answ=$db_answ->value;			
				//WIRIS CHANGE!
				$copy_answers=$tq['answers'];
				for($z=0;$z<count($copy_answers);$z++):		
					for($x=0;$x<count($copy_answers[$i]['answers']);$x++):
						$an=$copy_answers[$z]['answers'][$x];
						//AN		
						preg_match_all('/@@@@@([a-zA-Z0-9\-\.]+)/',$an,$plX);
						$plX=$plX[1];
						for($i=0;$i<count($plX);$i++):
							preg_match('/<variable name="'.$plX[$i].'" type="(.*)">(.*)<\/variable>/U',$db_answ,$pl2X);
							switch($pl2X[1]):
								case 'mathml':
									$varX=$pl2X[2];
									$varX=preg_replace('/<!\[CDATA\[/','',$varX);
									$varX=preg_replace('/\]\]>/','',$varX);
									$an=preg_replace('/@@@@@'.$plX[$i].'/',$varX,$an);
								break;
								case 'imageref':
									$varX=$WIRIS_URL_IMAGE_SERVICE.$pl2X[2];
									$file_md5='wiris_'.md5($varX).'.png';
									if(file_exists($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5)):
										touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5);
									else:
										$ch = curl_init();
										curl_setopt($ch,CURLOPT_URL,$varX);
										curl_setopt($ch,CURLOPT_RETURNTRANSFER,true); 
										curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false); 
										curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,20);  
										curl_setopt($ch,CURLOPT_MAXREDIRS,10); 
										curl_setopt($ch,CURLOPT_TIMEOUT,600);
										$req=curl_exec($ch);
										curl_close($ch);
										file_put_contents($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5,$req);
									endif;
									$an=preg_replace('/@@@@@'.$plX[$i].'/','<img src="'.$CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5.'">',$an);
									touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5);
								break;
							endswitch;
						endfor;
						$copy_answers[$z]['answers'][$x]=$an;	
					endfor;		
				endfor;

				////////////////////WIRIS CHANGE!
				foreach($xxx_questions AS $id => $questions):		
					preg_match('/_sub(.*)_order/',$questions->name,$number_question_db);
					$number_question_db=$number_question_db[1];		
					$xxx_resp_exist=$DB->get_record_sql('SELECT count(*) as how FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name="sub'.$number_question_db.'_answer"');		
					if($xxx_resp_exist->how > 0):
						$xxx_resp=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name="sub'.$number_question_db.'_answer"');				
						$ttab1=null;
						$ttab1=explode(',',$questions->value);
						$index=$ttab1[$xxx_resp->value];
						if(($index<0)||(empty($index))):
							$question=preg_replace('/\{#'.($number_question_db).'\}/','<span style="color:red;font-weight: bold;">___</span>',$question);
						else:
							$index2=$copy_answers[$number_question_db-1]['answers_id'][$index];
							if($copy_answers[$number_question_db-1]['correct'] == $index2):
								$points+=$copy_answers[$number_question_db-1]['points'][$index2];
							endif;
							$question=preg_replace('/\{#'.($number_question_db).'\}/','<span style="color:red;font-weight: bold;">'.$copy_answers[$number_question_db-1]['answers'][$index2].'</span>',$question);
						endif;			
					endif;
				endforeach;
						
				preg_match_all('/\{#([0-9]+)\}/',$question,$rest);
				if(count($rest[1])>0):
					for($i=0;$i<count($rest[1]);$i++):
						$xxx_resp=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name="sub'.$rest[1][$i].'_answer"');	
						$xxx_resp=$xxx_resp->value;
						if($copy_answers[$rest[1][$i]-1]['typewiris'][0] == 'shortanswer'):
							$xxx_correct_c=$DB->get_record_sql('SELECT count(*) as how FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="_sub'.$rest[1][$i].'_matching_answer"');
							if($xxx_correct_c->how==0):
								$question=preg_replace('/\{#'.($rest[1][$i]).'\}/','<span style="color:red;font-weight: bold;">___</span>',$question);
							else:
								$xxx_correct=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="_sub'.$rest[1][$i].'_matching_answer"');
								$xxx_correct=$xxx_correct->value;
								$myanswer=$xxx_resp;
								$correctanswer=$copy_answers[$rest[1][$i]-1]['answers'][$copy_answers[$rest[1][$i]-1]['answers_id'][$xxx_correct]];
								
								require_once $CFG->dirroot.'/question/type/wq/quizzes/quizzes.php';
								$builder = com_wiris_quizzes_api_QuizzesBuilder::getInstance();
								$request = $builder->newEvalRequest($correctanswer,$myanswer,null, null);
								$service = $builder->getQuizzesService();
								$response = $service->execute($request);
								$instance = $builder->newQuestionInstance();
								$instance->update($response);
								$correct = $instance->isAnswerCorrect(0);
								if($correct == 1):
									$points=$copy_answers[$rest[1][$i]-1]['points'][$copy_answers[$rest[1][$i]-1]['answers_id'][$xxx_correct]];
									$question=preg_replace('/\{#'.($rest[1][$i]).'\}/','<span style="color:blue;font-weight: bold;">\$'.$myanswer.'\$</span>',$question);
								else:
									$question=preg_replace('/\{#'.($rest[1][$i]).'\}/','<span style="color:red;font-weight: bold;">\$'.$myanswer.'\$</span>',$question);
								endif;
							endif;
						elseif($copy_answers[$rest[1][$i]-1]['typewiris'][0] == 'numerical'):
							for($j=0;$j<count($copy_answers[$rest[1][$i]-1]['answers']);$j++):
								if($copy_answers[$rest[1][$i]-1]['type'][$j] == 'range'):
									$ex=explode('-',$copy_answers[$rest[1][$i]-1]['answers'][$j]);
									if(($xxx_resp->value>=$ex[0])&&($xxx_resp->value<=$ex[1])):
										if($copy_answers[$rest[1][$i]-1]['points'][$j]==0):
											$question=preg_replace('/\{#'.($rest[1][$i]).'\}/','<span style="color:red;font-weight: bold;">'.$xxx_resp->value.'</span>',$question);
											break;
										else:
											$points+=$copy_answers[$rest[1][$i]-1]['points'][$j];
											$question=preg_replace('/\{#'.($rest[1][$i]).'\}/','<span style="color:blue;font-weight: bold;">'.$xxx_resp->value.'</span>',$question);
											break;
										endif;
									else:
										$question=preg_replace('/\{#'.($rest[1][$i]).'\}/','<span style="color:red;font-weight: bold;">'.$xxx_resp->value.'</span>',$question);
									endif;
								else:						
									if($xxx_resp->value == $copy_answers[$rest[1][$i]-1]['answers'][$j]):
										$question=preg_replace('/\{#'.($rest[1][$i]).'\}/','<span style="color:green;font-weight: bold;">'.$xxx_resp->value.'</span>',$question);
										$points+=$copy_answers[$rest[1][$i]-1]['points'][$j];
										break;
									else:
										$question=preg_replace('/\{#'.($rest[1][$i]).'\}/','<span style="color:red;font-weight: bold;">'.$xxx_resp->value.'</span>',$question);
										break;
									endif;
								endif;
							endfor;
						elseif($copy_answers[$rest[1][$i]-1]['typewiris'][0] == 'multichoice'):
							$xxx_resp_exist=$DB->get_record_sql('SELECT count(*) as how FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name="sub'.$rest[1][$i].'_answer"');		
							if($xxx_resp_exist->how > 0):			
								$ttab1=null;
								$ttab1=explode(',',$questions->value);
								$index=$ttab1[$xxx_resp->value];
								if(($index<0)||(empty($index))):
									$question=preg_replace('/\{#'.($rest[1][$i]).'\}/','<span style="color:red;font-weight: bold;">___</span>',$question);
								else:
									$index2=$copy_answers[$rest[1][$i]-1]['answers_id'][$index];
									if($copy_answers[$rest[1][$i]-1]['correct'] == $index2):
										$points+=$copy_answers[$rest[1][$i]-1]['points'][$index2];
									endif;
									$question=preg_replace('/\{#'.($rest[1][$i]).'\}/','<span style="color:red;font-weight: bold;">'.$copy_answers[$rest[1][$i]-1]['answers'][$index2].'</span>',$question);
								endif;	
							endif;
						endif;
					endfor;
				endif;

				/////QUESTION		
				preg_match_all('/#([a-zA-Z0-9\-\.]+)/',$question,$plX);
				$plX=$plX[1];
				for($i=0;$i<count($plX);$i++):
					preg_match('/<variable name="'.$plX[$i].'" type="(.*)">(.*)<\/variable>/U',$db_answ,$pl2X);
					switch($pl2X[1]):
						case 'mathml':
							$varX=$pl2X[2];
							$varX=preg_replace('/<!\[CDATA\[/','',$varX);
							$varX=preg_replace('/\]\]>/','',$varX);
							$question=preg_replace('/#'.$plX[$i].'/',$varX,$question);
						break;
						case 'imageref':
							$varX=$WIRIS_URL_IMAGE_SERVICE.$pl2X[2];
							$file_md5='wiris_'.md5($varX).'.png';
							if(file_exists($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5)):
								touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5);
							else:
								$ch = curl_init();
								curl_setopt($ch,CURLOPT_URL,$varX);
								curl_setopt($ch,CURLOPT_RETURNTRANSFER,true); 
								curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false); 
								curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,20);  
								curl_setopt($ch,CURLOPT_MAXREDIRS,10); 
								curl_setopt($ch,CURLOPT_TIMEOUT,600);
								$req=curl_exec($ch);
								curl_close($ch);
								file_put_contents($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5,$req);
							endif;
							$question=preg_replace('/#'.$pl[$i].'/','<img src="'.$CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5.'">',$question);
							touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5);
						break;
					endswitch;
				endfor;
				$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($points,$questiondecimalpoints,".","");

				$NREQ='
				<table border="0" style="width:100%;"><tr><td><b>'.$nb_question.'.</b></td><td style="text-align:right;">WIRIS - '.get_string('pluginname','qtype_multianswerwiris').'</td></tr></table><br />'.$question.'<br /><br />
				<table style="margin-left: auto; margin-right: auto;" class="table">
				<tr>
				<th style="font-size: 10pt;">'.get_string('points_available','quiz_nitroreportpdf').'</th>
				<th style="font-size: 10pt;">'.get_string('gained_points','quiz_nitroreportpdf').'</th>
				</tr>
				<tr>
				<td style="text-align: center;font-size: 10pt;">'.$tq['points'][0].'</td>
				<td style="text-align: center;font-size: 10pt;">'.$tab_users[$users->userid]['attempt'][$tq['qid']].'</td>
				</tr>
				</table>';	
				//WRITE TO HTML

				//PDF
				preg_match_all('/<math.*>(.*)<\/math>/U',$NREQ,$math);
				$math=$math[0];
				for($i=0;$i<count($math);$i++):
					$req=$this->latexmlfunctions('mathml2image',urlencode($math[$i]));
					if($req != "@500"):
						$NREQ=preg_replace('/<math.*>.*<\/math>/U','<img src="'.$req.'">',$NREQ);
					else:
						$req=$this->latexmlfunctions('mathml2latex',urlencode($math[$i]));
						if($req != "@500"):
							$req=$this->latexmlfunctions('latex2image',urlencode($req));
							if($req != "@500"):
								$NREQ=preg_replace('/<math.*>.*<\/math>/U','<img src="'.$req.'">',$NREQ);
							endif;
						endif;
					endif;
				endfor;
				preg_match_all('/\$(.*)\$/U',$NREQ,$math);	
				$math=$math[1];
				for($i=0;$i<count($math);$i++):
					$req=$this->latexmlfunctions('latex2image',urlencode($math[$i]));
					if($req != "@500"):
						$NREQ=preg_replace('/<math.*>.*<\/math>/U','<img src="'.$req.'">',$NREQ);
					endif;
				endfor;	
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;
			break;		
			
			case 'multichoicewiris':		
				$question=$tq['question'];	
				$tab_users[$users->userid]['attempt'][$tq['qid']]=0;
				$multi_tb = $DB->get_record_sql('SELECT single FROM {qtype_multichoice_options} WHERE questionid="'.$tq['qid'].'"');
				$xxx1 = $DB->get_records_sql('SELECT qas.id AS id FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.sequencenumber IN (0,1) ORDER BY qas.id ASC');
				$xxx2=array_keys($xxx1);
				$db_answ=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="_qi"');
				$db_answ=$db_answ->value;
				//QUESTION
				preg_match_all('/#([a-zA-Z0-9\-\.]+)/',$question,$pl);
				$pl=$pl[1];	
				for($i=0;$i<count($pl);$i++):
					preg_match('/<variable name="'.$pl[$i].'" type="(.*)">(.*)<\/variable>/U',$db_answ,$pl2);
					switch($pl2[1]):
						case 'mathml':
							$var=$pl2[2];
							$var=preg_replace('/<!\[CDATA\[/','',$var);
							$var=preg_replace('/\]\]>/','',$var);
							$question=preg_replace('/#'.$pl[$i].'/',$var,$question);
						break;
						case 'imageref':
							$var=$WIRIS_URL_IMAGE_SERVICE.$pl2[2];
							$file_md5='wiris_'.md5($var).'.png';
							if(file_exists($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5)):
								touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5);
							else:
								$ch = curl_init();
								curl_setopt($ch,CURLOPT_URL,$var);
								curl_setopt($ch,CURLOPT_RETURNTRANSFER,true); 
								curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false); 
								curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,20);  
								curl_setopt($ch,CURLOPT_MAXREDIRS,10); 
								curl_setopt($ch,CURLOPT_TIMEOUT,600);
								$req=curl_exec($ch);
								curl_close($ch);
								file_put_contents($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5,$req);
							endif;
							$question=preg_replace('/#'.$pl[$i].'/','<img src="'.$CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5.'">',$question);
							touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5);
						break;
					endswitch;
				endfor;	
				//ANSWERS
				$answers=$tq['answers'];
				foreach($answers AS $ID => $ans):	
					$ansX=$ans;
					preg_match_all('/#([a-zA-Z0-9\-\.]+)/',$ansX,$pl);
					$pl=$pl[1];	
					for($i=0;$i<count($pl);$i++):
						preg_match('/<variable name="'.$pl[$i].'" type="(.*)">(.*)<\/variable>/U',$db_answ,$pl2);
						switch($pl2[1]):
							case 'mathml':
								$var=$pl2[2];
								$var=preg_replace('/<!\[CDATA\[/','',$var);
								$var=preg_replace('/\]\]>/','',$var);
								$ansX=preg_replace('/#'.$pl[$i].'/',$var,$ansX);
							break;
							case 'imageref':
								$var=$WIRIS_URL_IMAGE_SERVICE.$pl2[2];
								$file_md5='wiris_'.md5($var).'.png';
								if(file_exists($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5)):
									touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5);
								else:
									$ch = curl_init();
									curl_setopt($ch,CURLOPT_URL,$var);
									curl_setopt($ch,CURLOPT_RETURNTRANSFER,true); 
									curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false); 
									curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,20);  
									curl_setopt($ch,CURLOPT_MAXREDIRS,10); 
									curl_setopt($ch,CURLOPT_TIMEOUT,600);
									$req=curl_exec($ch);
									curl_close($ch);
									file_put_contents($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5,$req);
								endif;
								$ansX=preg_replace('/#'.$pl[$i].'/','<img src="'.$CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5.'">',$ansX);
								touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5);
							break;
						endswitch;
					endfor;	
					$answers[$ID]=$ansX;
				endforeach;
				$xxx3_tab_bin=array();
				$count_corrected=0;
				foreach($tq['qanswers'] as $id => $a):
					if($a==1):
						$count_corrected++;
					endif;
				endforeach;
				if($multi_tb->single==1):
					$questiontypemultichoice=get_string('questiontypemultichoiceone','quiz_nitroreportpdf');
					$xxx3=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="_order"');
					$xxx4=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name="answer"');
					$xxx3_u=$xxx3->value;
					///contains user's answers in binary format . this table must be sorting by id
					$xxx3_tab=explode(',',$xxx3_u);
					if((!is_object($xxx4))):
						$xxx4 = new StdClass;
						$xxx4->id=-1;
						$xxx4->value=-1;
					endif;
					for($z=0;$z<count($xxx3_tab);$z++):
						//specify place in options
						if($z==$xxx4->value):
							$xxx3_tab_bin[$xxx3_tab[$z]]=1;
						else:
							$xxx3_tab_bin[$xxx3_tab[$z]]=0;
						endif;
					endfor;
					if((!isset($xxx4->value))):
						$xxx4->value=-1;
					endif;
					for($z=0;$z<count($xxx3_tab);$z++):
						//specify place in options
						if($z==$xxx4->value):
							$xxx3_tab_bin[$xxx3_tab[$z]]=1;
						else:
							$xxx3_tab_bin[$xxx3_tab[$z]]=0;
						endif;
					endfor;
					$xxx3_tab_bin_ids=$this->quick_sort(array_keys($xxx3_tab_bin));
					$xxx3_tab_bin2=array();
					for($z=0;$z<count($xxx3_tab_bin_ids);$z++):
						$xxx3_tab_bin2[$xxx3_tab_bin_ids[$z]]=$xxx3_tab_bin[$xxx3_tab_bin_ids[$z]];
					endfor;
					$xxx3_tab_bin=$xxx3_tab_bin2;
					$corrected=0;
					$user_asked='';
					$odp=0;
					foreach($xxx3_tab_bin as $id => $bin):
						$multiple=$bin*$tq[qanswers][$id];
						if($multiple == 1):
							$corrected++;
						endif;
						if(($bin == 1)&&($bin != $tq[qanswers][$id])):
							$user_asked.='<span style="color: red;"><b>[X]</b></span> ';
						elseif(($bin == 1)&&($bin == $tq[qanswers][$id])):
							$user_asked.='<span style="color: blue;"><b>[X]</b></span> ';
						endif;
						$user_asked.='<b>'.chr(65+$odp).".</b> &nbsp;";
						$user_asked.=$answers[$id].'<br />';
						$odp++;
					endforeach;
					$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0]*$corrected/$count_corrected,$questiondecimalpoints,".","");
				elseif(($multi_tb->single==0)&&(count($xxx2)>0)):
					$questiontypemultichoice=get_string('questiontypemultichoicemulti','quiz_nitroreportpdf');
					$xxx3=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="_order"');
					$xxx4=$DB->get_records_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name LIKE "choice%" ORDER BY name ASC');
					$xxx3_u=$xxx3->value;
					$xxx3_tab=explode(',',$xxx3_u);
					$xxx4_tab_bin=array();
					$xxx4_ids=array_keys($xxx4);
					for($z=0;$z<count($xxx4_ids);$z++):
						$xxx4_tab_bin[]=$xxx4[$xxx4_ids[$z]]->value;
					endfor;
					$xxx4_tab_bin_temp=array();
					if(count($xxx4)==0):
						for($y=0;$y<count($tq['qanswers']);$y++):
							$xxx4_tab_bin[]=0;
						endfor;
					endif;
					for($z=0;$z<count($xxx3_tab);$z++):
						$xxx4_tab_bin_temp[$xxx3_tab[$z]]=$xxx4_tab_bin[$z];
					endfor;
					$xxx4_tab_bin_temp_ids=$this->quick_sort(array_keys($xxx4_tab_bin_temp));
					$xxx4_tab_bin_temp2=array();
					for($z=0;$z<count($xxx4_tab_bin_temp_ids);$z++):
						$xxx4_tab_bin_temp2[$xxx4_tab_bin_temp_ids[$z]]=$xxx4_tab_bin_temp[$xxx4_tab_bin_temp_ids[$z]];
					endfor;
					$xxx3_tab_bin=$xxx4_tab_bin_temp2;
					unset($xxx4_tab_bin_temp2);
					$corrected=0;
					$user_asked='';
					$odp=0;
					foreach($xxx3_tab_bin as $id => $bin):
						$multiple=$bin*$tq[qanswers][$id];
						if($multiple == 1):
							$corrected++;
						endif;
						if(($bin == 1)&&($bin != $tq[qanswers][$id])):
							$user_asked.='<span style="color: red;"><b>[X]</b></span> ';
						elseif(($bin == 1)&&($bin == $tq[qanswers][$id])):
							$user_asked.='<span style="color: blue;"><b>[X]</b></span> ';
						endif;
						$user_asked.='<b>'.chr(65+$odp).".</b> &nbsp;";
						$user_asked.=$answers[$id].'<br />';
						$odp++;
					endforeach;
				endif;
				if($MODE_NOTE == 1):
					if($corrected==$count_corrected):
						$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0],$questiondecimalpoints,".","");
					endif;
				else:
					$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0]*$corrected/$count_corrected,$questiondecimalpoints,".","");
				endif;
				$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nb_question.'.</b></td><td style="text-align:right;">'.$questiontypemultichoice.'</td></tr></table><br />'.$question.'<br /><br />'.$user_asked.'<br />
				<table style="margin-left: auto; margin-right: auto;" class="table">
				<tr>
				<th style="font-size: 10pt;">'.get_string('points_available','quiz_nitroreportpdf').'</th>
				<th style="font-size: 10pt;">'.get_string('gained_points','quiz_nitroreportpdf').'</th>
				</tr>
				<tr>
				<td style="text-align: center;font-size: 10pt;">'.$tq['points'][0].'</td>
				<td style="text-align: center;font-size: 10pt;">'.$tab_users[$users->userid]['attempt'][$tq['qid']].'</td>
				</tr>
				</table>';
				//WRITE TO HTML

				//WRITE TO PDF
				preg_match_all('/<math.*>(.*)<\/math>/U',$NREQ,$math);
				$math=$math[0];
				for($i=0;$i<count($math);$i++):
					$req=$this->latexmlfunctions('mathml2image',urlencode($math[$i]));
					if($req != "@500"):
						$NREQ=preg_replace('/<math.*>.*<\/math>/U','<img src="'.$req.'">',$NREQ);
					else:
						$req=$this->latexmlfunctions('mathml2latex',urlencode($math[$i]));
						if($req != "@500"):
							$req=$this->latexmlfunctions('latex2image',urlencode($req));
							if($req != "@500"):
								$NREQ=preg_replace('/<math.*>.*<\/math>/U','<img src="'.$req.'">',$NREQ);
							endif;
						endif;
					endif;
				endfor;
				$mpdf->WriteHTML($NREQ);	
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;
			break;		
				
			case 'shortanswerwiris':
				$question=$tq['question'];	
				$tab_users[$users->userid]['attempt'][$tq['qid']]=0;
				$multi_tb = $DB->get_record_sql('SELECT single FROM {qtype_multichoice_options} WHERE questionid="'.$tq['qid'].'"');
				$xxx1 = $DB->get_records_sql('SELECT qas.id AS id FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.sequencenumber IN (0,1) ORDER BY qas.id ASC');
				$xxx2=array_keys($xxx1);
				$db_answ=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="_qi"');
				$db_answ=$db_answ->value;
				$db_answer=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name="answer"');
				$db_answer=$db_answer->value;	
				$copy_answers=$tq['answers'];
				foreach($copy_answers AS $ID => $ANS):
					$an=$ANS;
					preg_match_all('/#([a-zA-Z0-9\-\.]+)/',$an,$plX);
					$plX=$plX[1];
					for($i=0;$i<count($plX);$i++):
						preg_match('/<variable name="'.$plX[$i].'" type="(.*)">(.*)<\/variable>/U',$db_answ,$pl2X);
						switch($pl2X[1]):
							case 'mathml':
								$varX=$pl2X[2];
								$varX=preg_replace('/<!\[CDATA\[/','',$varX);
								$varX=preg_replace('/\]\]>/','',$varX);
								$an=preg_replace('/#'.$plX[$i].'/',$varX,$an);
							break;
							case 'imageref':
								$varX=$WIRIS_URL_IMAGE_SERVICE.$pl2X[2];
								$file_md5='wiris_'.md5($varX).'.png';
								if(file_exists($CFG->dirroot.	'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5)):
									touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5);
								else:
									$ch = curl_init();
									curl_setopt($ch,CURLOPT_URL,$varX);
									curl_setopt($ch,CURLOPT_RETURNTRANSFER,true); 
									curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false); 
									curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,20);  
									curl_setopt($ch,CURLOPT_MAXREDIRS,10); 
									curl_setopt($ch,CURLOPT_TIMEOUT,600);
									$req=curl_exec($ch);
									curl_close($ch);
									file_put_contents($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5,$req);
								endif;
								$an=preg_replace('/#'.$pl[$i].'/','<img src="'.$CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5.'">',$an);
								touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$file_md5);
							break;
						endswitch;
					endfor;	
					$copy_answers[$ID]=$an;
				endforeach;	
				$answer='<span style="color:red;font-weight: bold;">'.$db_answer.'</span>';
				require_once $CFG->dirroot.'/question/type/wq/quizzes/quizzes.php';
				for($i=0;$i<count($copy_answers);$i++):
					$builder = com_wiris_quizzes_api_QuizzesBuilder::getInstance();
					$request = $builder->newEvalRequest($copy_answers[$i],$db_answer, null, null);
					$service = $builder->getQuizzesService();
					$response = $service->execute($request);
					$instance = $builder->newQuestionInstance();
					$instance->update($response);
					$correct = $instance->isAnswerCorrect(0);
					if($correct == 1):
						$tab_users[$users->userid]['attempt'][$tq['qid']]=$tq['fraction'][$i]*$tq['points'][0];
						$answer='<span style="color:blue;font-weight: bold;">'.$db_answer.'</span>';			
						break;
					endif;	
				endfor;	
				$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nb_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_shortanswerwiris').'</td></tr></table><br />'.$question.'<br /><br />
				Odpowiedź: '.$answer.'
				<br /><br />
				<table style="margin-left: auto; margin-right: auto;" class="table">
				<tr>
				<th style="font-size: 10pt;">'.get_string('points_available','quiz_nitroreportpdf').'</th>
				<th style="font-size: 10pt;">'.get_string('gained_points','quiz_nitroreportpdf').'</th>
				</tr>
				<tr>
				<td style="text-align: center;font-size: 10pt;">'.$tq['points'][0].'</td>
				<td style="text-align: center;font-size: 10pt;">'.$tab_users[$users->userid]['attempt'][$tq['qid']].'</td>
				</tr>
				</table>';
				//WRITE TO HTML

				//WRITE TO PDF
				preg_match_all('/<math.*>(.*)<\/math>/U',$NREQ,$math);
				$math=$math[0];
				for($i=0;$i<count($math);$i++):
					$req=$this->latexmlfunctions('mathml2image',urlencode($math[$i]));
					if($req != "@500"):
						$NREQ=preg_replace('/<math.*>.*<\/math>/U','<img src="'.$req.'">',$NREQ);
					else:
						$req=$this->latexmlfunctions('mathml2latex',urlencode($math[$i]));
						if($req != "@500"):
							$req=$this->latexmlfunctions('latex2image',urlencode($req));
							if($req != "@500"):
								$NREQ=preg_replace('/<math.*>.*<\/math>/U','<img src="'.$req.'">',$NREQ);
							endif;
						endif;
					endif;
				endfor;
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;
			break;
// WIRIS QUESTIONS *** WIRIS QUESTIONS *** WIRIS QUESTIONS				
		
			case 'multichoiceset':	
				$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format(0,$questiondecimalpoints,".","");	
				$question=$tq['question'].'<br><br>';		
				$xxx1 = $DB->get_records_sql('SELECT qas.id AS id FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.sequencenumber IN (0,1) ORDER BY qas.id ASC');	
				$xxx2=array_keys($xxx1);	
				$db_q=$DB->get_record_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name="_order"');
				$db_q=$db_q->value;
				$db_answ=$DB->get_records_sql('SELECT id,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[1].'" AND name LIKE "choice%"');		
				$ttab1=null;
				$ttab1=explode(',',$db_q);
				$ttab2=null;
				$correct=false;
				foreach($db_answ AS $id => $val):
					$ttab2[]=$val;
				endforeach;			
				$answer='';	
				for($i=0;$i<count($ttab2);$i++):
					$answer.=($i+1).'. ';
					if($ttab2[$i]->value > 0):
						if(in_array($ttab1[$i],$tq['answers_corr_tab'])):
							$answer.='<span style="color:blue;font-weight: bold;">[X]</span> ';
							$correct=true;
						endif;
					else:
						$correct=false;	
					endif;
					$answer.=$tq['answers'][$tq['answers_id'][$ttab1[$i]]]['answer'];
				endfor;	
				if($correct):
					$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0],$questiondecimalpoints,".","");
				endif;
				$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nb_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_multichoiceset').'</td></tr></table><br />'.$question.' '.$answer.'<br /><br />
				<table style="margin-left: auto; margin-right: auto;" class="table">
				<tr>
				<th style="font-size: 10pt;">'.get_string('points_available','quiz_nitroreportpdf').'</th>
				<th style="font-size: 10pt;">'.get_string('gained_points','quiz_nitroreportpdf').'</th>
				</tr>
				<tr>
				<td style="text-align: center;font-size: 10pt;">'.$tq['points'][0].'</td>
				<td style="text-align: center;font-size: 10pt;">'.$tab_users[$users->userid]['attempt'][$tq['qid']].'</td>
				</tr>
				</table>';
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;
			break;

			case 'calculatedsimple':
				$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format(0,$questiondecimalpoints,".","");	
				$xxx1 = $DB->get_records_sql('SELECT qas.id AS id FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.sequencenumber IN (0,1) ORDER BY qas.id ASC');	
				$xxx2=array_keys($xxx1);
				$db_vars=$DB->get_records_sql('SELECT id,name,value FROM {question_attempt_step_data} WHERE attemptstepid="'.$xxx2[0].'" AND name LIKE "_var_%"');
				$variables=array();
				foreach($db_vars AS $ID => $obj):
					$variables[substr($obj->name,5)]=$obj->value;
				endforeach;
				$question=$tq['question'];
				foreach($db_vars AS $ID => $obj):
					$question=preg_replace('/\{'.(substr($obj->name,5)).'\}/',$obj->value,$question);
				endforeach;	
				$question.='<br><br><u>Odpowiedź:</u> ';
				$quiz_details_sql = $DB->get_records_sql('SELECT questionid,rightanswer,responsesummary FROM {question_attempts} WHERE questionusageid="'.$grademethod_sql->uniqueid.'" AND questionid="'.$tq['qid'].'"');
				$quiz_details_sql=$quiz_details_sql[$tq['qid']];
				$corrrect=$quiz_details_sql->rightanswer;
				$resp=$quiz_details_sql->responsesummary;
				if($corrrect == $resp):
					$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0],$questiondecimalpoints,".","");
					$question.='<span style="color:blue;font-weight: bold;">'.$resp.'</span>';
				else:
					$question.='<span style="color:red;font-weight: bold;">'.$resp.'</span>';
				endif;
				$NREQ='<table border="0" style="width:100%;"><tr><td><b>'.$nb_question.'.</b></td><td style="text-align:right;">'.get_string('pluginname','qtype_calculatedsimple').'</td></tr></table><br />'.$question.'<br /><br />
				<table style="margin-left: auto; margin-right: auto;" class="table">
				<tr>
				<th style="font-size: 10pt;">'.get_string('points_available','quiz_nitroreportpdf').'</th>
				<th style="font-size: 10pt;">'.get_string('gained_points','quiz_nitroreportpdf').'</th>
				</tr>
				<tr>
				<td style="text-align: center;font-size: 10pt;">'.$tq['points'][0].'</td>
				<td style="text-align: center;font-size: 10pt;">'.$tab_users[$users->userid]['attempt'][$tq['qid']].'</td>
				</tr>
				</table>';
				$mpdf->WriteHTML($NREQ);
				if($generate_html_file):
					$html_contents.=$NREQ;
				endif;
			break;

			default:			
				$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format(0,$questiondecimalpoints,".","");
				$gradedpartial=$DB->get_record_sql('SELECT count(qas.fraction) AS fraction FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.state = "gradedpartial" ORDER BY qas.sequencenumber DESC LIMIT 0,1');
				if($gradedpartial->fraction):
					$gradedpartial=$DB->get_record_sql('SELECT qas.fraction AS fraction FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.state = "gradedpartial" ORDER BY qas.sequencenumber DESC LIMIT 0,1');
					$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0]*$gradedpartial->fraction,$questiondecimalpoints,".","");
				endif;
			break;		
		endswitch;
		$mangrpartial=$DB->get_record_sql('SELECT count(qas.fraction) AS fraction FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.state = "mangrpartial" ORDER BY qas.sequencenumber DESC LIMIT 0,1');
		if($mangrpartial->fraction):
			$mangrpartial=$DB->get_record_sql('SELECT qas.fraction AS fraction FROM {question_attempts} qa, {question_attempt_steps} qas WHERE qa.questionusageid="'.$grademethod_sql->uniqueid.'" AND qa.questionid="'.$tq['qid'].'" AND qas.questionattemptid=qa.id AND qas.state = "mangrpartial" ORDER BY qas.sequencenumber DESC LIMIT 0,1');
			$tab_users[$users->userid]['attempt'][$tq['qid']]=number_format($tq['points'][0]*$mangrpartial->fraction,$questiondecimalpoints,".","");
		endif;
		if(($nr_question-1)>$nb_question):
			$NREQ='<hr noshade style="height:1px;color:black;" />';
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
		endif;
		$nb_question++;
	endforeach;
	$NREQ='<hr noshade style="height:5px;color:black;" />';
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ.'<hr noshade>';;
	endif;
	$user_i++;
endforeach;
///////////////////////////////////////////////////////////
$this->SetBarWidth(number_format(floor(8*(100/$PROGRESSBAR_PARTS)),2,'.',''));
@ob_flush();
@flush();
$mpdf->AddPage();
$mpdf->Bookmark('7. '.get_string('statisticalanalysis','quiz_nitroreportpdf'),0);
$NREQ='<p style="text-align: center;font-weight: bold;font-size:14pt;text-transform:uppercase;">'.get_string('statisticalanalysis','quiz_nitroreportpdf').'</p><p></p>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
$tab_points=array();
$tab_userids=array_keys($tab_users);
$tab_quizids=array_keys($tab_quiz);
for($i=0;$i<count($tab_userids);$i++):
	$sum=0;
	$tab_users[$tab_userids[$i]]['sum_points']=0;
	for($j=0;$j<count($tab_quizids);$j++):
		$sum=$sum+$tab_users[$tab_userids[$i]]['attempt'][$tab_quizids[$j]];
		$tab_points[$j][]=$tab_users[$tab_userids[$i]]['attempt'][$tab_quizids[$j]];
	endfor;
	$tab_users[$tab_userids[$i]]['sum_points']=$sum;
endfor;
$NREQ='
<table style="margin-left: auto; margin-right: auto;" repeat_header="1" class="table">
	<tr>
		<th colspan="3">&nbsp;</th>
		<th colspan="2">'.get_string('number_of_points','quiz_nitroreportpdf').'</th>
	</tr>
	<tr>
		<th>'.get_string('question2','quiz_nitroreportpdf').'</th>
		<th>'.get_string('max_points','quiz_nitroreportpdf').'</th>
		<th>'.get_string('average','quiz_nitroreportpdf').'</th>
		<th>'.get_string('min','quiz_nitroreportpdf').'</th>
		<th>'.get_string('max','quiz_nitroreportpdf').'</th>
	</tr>
';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
for($i=0;$i<count($tab_points);$i++):
	$tab_avg=array_sum($tab_points[$i])/count($tab_points[$i]);
	if($i % 2 == 1):
		$attach_style=' class="table_td_highlight"';
	endif;
	$NREQ='
	<tr>
		<td'.$attach_style.'>'.($i+1).'.</td>
		<td'.$attach_style.'>'.max($tab_quiz[$tab_quizids[$i]]['points']).'</td>
		<td'.$attach_style.'>'.number_format($tab_avg,$decimalpoints,'.','').'</td>
		<td'.$attach_style.'>'.(min($tab_points[$i])).'</td>
		<td'.$attach_style.'>'.(max($tab_points[$i])).'</td>
	</tr>
	';
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ;
	endif;
	unset($attach_style);
endfor;
$NREQ='</table>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ.'<hr noshade>';;
endif;
if($GENERATE_EXCEL):
	$SheetCount=$objPHPExcel->getSheetCount();
	$objPHPExcel->createSheet(NULL,$SheetCount);
	$objPHPExcel->setActiveSheetIndex($SheetCount);
	$objPHPExcel->getActiveSheet()->setTitle(get_string('statisticalanalysis','quiz_nitroreportpdf'));
	$objPHPExcel->getActiveSheet()->setCellValue('A1', get_string('question2','quiz_nitroreportpdf'))
	->setCellValue('B1', get_string('max_points','quiz_nitroreportpdf'))
	->setCellValue('C1', get_string('average','quiz_nitroreportpdf'))
	->setCellValue('D2', get_string('min','quiz_nitroreportpdf'))
	->setCellValue('E2', get_string('max','quiz_nitroreportpdf'))
	->setCellValue('D1', get_string('number_of_points','quiz_nitroreportpdf'));
	$objPHPExcel->getActiveSheet()->mergeCells('A1:A2');
	$objPHPExcel->getActiveSheet()->mergeCells('B1:B2');
	$objPHPExcel->getActiveSheet()->mergeCells('C1:C2');
	$objPHPExcel->getActiveSheet()->mergeCells('D1:E1');
	for($i=0;$i<count($tab_points);$i++):
		$tab_avg=array_sum($tab_points[$i])/count($tab_points[$i]);
		$objPHPExcel->getActiveSheet()->setCellValue('A'.($i+3), ($i+1))
		->setCellValue('B'.($i+3), max($tab_quiz[$tab_quizids[$i]]['points']))
		->setCellValue('C'.($i+3), number_format($tab_avg,$decimalpoints,'.',''))
		->setCellValue('D'.($i+3), (min($tab_points[$i])))
		->setCellValue('E'.($i+3), (max($tab_points[$i])));
	endfor;
	for($i=1;$i<=count($tab_points)+2;$i++):
		if($i % 2 == 0):
			$objPHPExcel->getActiveSheet()->getStyle('A'.$i.':E'.$i)->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('A'.$i.':E'.$i)->getFill()->getStartColor()->setRGB('FFFFA1');
		endif;
	endfor;
	$objPHPExcel->getActiveSheet()->getStyle('A1')->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
	$objPHPExcel->getActiveSheet()->getStyle('B1')->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
	$objPHPExcel->getActiveSheet()->getStyle('C1')->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
	$objPHPExcel->getActiveSheet()->getStyle('D1')->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
	$objPHPExcel->getActiveSheet()->getStyle('E1')->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
	$objPHPExcel->getActiveSheet()->getStyle('D2')->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
	$objPHPExcel->getActiveSheet()->getStyle('E2')->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
	$objPHPExcel->getActiveSheet()->getStyle('A1:E2')->getFont()->setBold(true);
	$objPHPExcel->getActiveSheet()->getStyle('A1:E'.($i+2))->getFont()->setSize(14);
	for($i=0;$i<count($tab_points)+3;$i++):
		$objPHPExcel->getActiveSheet()->getRowDimension($i)->setRowHeight(19.83);
		$objPHPExcel->getActiveSheet()->getStyle('A'.($i+3))->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
		$objPHPExcel->getActiveSheet()->getStyle('B'.($i+3))->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
		$objPHPExcel->getActiveSheet()->getStyle('C'.($i+3))->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
		$objPHPExcel->getActiveSheet()->getStyle('D'.($i+3))->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
		$objPHPExcel->getActiveSheet()->getStyle('E'.($i+3))->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
	endfor;
	$styleArray = array(
		'borders' => array(
			'allborders' => array(
				'style' => PHPExcel_Style_Border::BORDER_THIN,
				'color' => array(
					'rgb' => '000000'
				),
			),
		),
	);
	$objPHPExcel->getActiveSheet()->getStyle('A1:E'.($i-1))->applyFromArray($styleArray);
	$objPHPExcel->getActiveSheet()->getStyle('A1:E2')->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
	$objPHPExcel->getActiveSheet()->getStyle('A1:E2')->getFill()->getStartColor()->setRGB('0057AF');
	$objPHPExcel->getActiveSheet()->getStyle('A1:E2')->getFont()->getColor()->setARGB(PHPExcel_Style_Color::COLOR_WHITE);
	$objPHPExcel->getActiveSheet()->getStyle('A1:C1')->getAlignment()->setVertical(PHPExcel_Style_Alignment::VERTICAL_CENTER);
	$objPHPExcel->getActiveSheet()->getStyle('A1:B10') ->getAlignment()->setWrapText(false);
	PHPExcel_Shared_Font::setAutoSizeMethod(PHPExcel_Shared_Font::AUTOSIZE_METHOD_EXACT);
	$objPHPExcel->getActiveSheet()->getColumnDimension('A')->setWidth(17);
	$objPHPExcel->getActiveSheet()->getColumnDimension('B')->setWidth(25);
	$objPHPExcel->getActiveSheet()->getColumnDimension('C')->setWidth(17);
	$objPHPExcel->getActiveSheet()->getColumnDimension('D')->setWidth(17);
	$objPHPExcel->getActiveSheet()->getColumnDimension('E')->setWidth(17);
	$objPHPExcel->getActiveSheet()->getProtection()->setPassword(substr(hash('sha512',rand()),0,12));
	$objPHPExcel->getActiveSheet()->getProtection()->setSheet(true);
	$objPHPExcel->getActiveSheet()->getProtection()->setFormatCells(true);
	$objPHPExcel->getActiveSheet()->getHeaderFooter()->setOddFooter('&L&D, &T &R &P / &N');
endif;
/*8. SUMMARY*/
$this->SetBarWidth(number_format(floor(9*(100/$PROGRESSBAR_PARTS)),2,'.',''));
@ob_flush();
@flush();
$mpdf->AddPage();
$mpdf->Bookmark('8. '.get_string('summary','quiz_nitroreportpdf'),0);
for($i=0;$i<count($tab_userids);$i++):
	//grade
	if(count($tab_notes2)==0):
		$tab_users[$tab_userids[$i]]['feedback']='';
	else:
		$tab_users[$tab_userids[$i]]['precent']=number_format(($tab_users[$tab_userids[$i]]['sum_points']/$info_quiz->sumgrades)*100,$decimalpoints,".","");
		for($j=0;$j<count($tab_notes2);$j++):
			if(($tab_users[$tab_userids[$i]]['sum_points']>=$tab_notes2[$j]['mingrade_points'])&&($tab_notes2[$j]['maxgrade_points']>=$tab_users[$tab_userids[$i]]['sum_points'])):
				$tab_users[$tab_userids[$i]]['feedback']=$tab_notes2[$j]['feedback'];
				break;
			endif;
		endfor;
	endif;
	//avg points
	$tab_users[$tab_userids[$i]]['avg']=number_format(($tab_users[$tab_userids[$i]]['sum_points']/count($tab_quizids)),2,'.','');
	//variance
	$war=0;
	for($j=0;$j<count($tab_quizids);$j++):
		$war+=pow($tab_users[$tab_userids[$i]]['attempt'][$tab_quizids[$j]]-$avg[$tab_userids[$i]],2);
	endfor;
	$war/=count($tab_quizids);
	$war=number_format($war,4,'.','');
	$tab_users[$tab_userids[$i]]['war']=$war;
	//odch
	$tab_users[$tab_userids[$i]]['odch']=number_format(sqrt($war),4,'.','');
endfor; // users for

$NREQ='<table border="0" style="overflow:visible" repeat_header="1" class="table">';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
$NREQ='<tr><th>'.get_string('on','quiz_nitroreportpdf').'</th>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
for($i=0;$i<count($tab_userids);$i++):
	$NREQ='<th>'.($i+1).'.</th>';
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ;
	endif;
endfor;
$NREQ='</tr>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;

$NREQ='<tr><th>'.get_string('surname','quiz_nitroreportpdf').'</th>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;

for($i=0;$i<count($tab_userids);$i++):
	$NREQ='<td>'.$tab_users[$tab_userids[$i]]['surname'].'</td>';
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ;
	endif;

endfor;
$NREQ='</tr>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;


$NREQ='<tr><th>'.get_string('name','quiz_nitroreportpdf').'</th>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
for($i=0;$i<count($tab_userids);$i++):
	$NREQ='<td class="table_td_highlight">'.$tab_users[$tab_userids[$i]]['name'].'</td>';
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ;
	endif;
endfor;
$NREQ='</tr>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;

$NREQ='<tr><th>'.get_string('username','quiz_nitroreportpdf').'</th>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
for($i=0;$i<count($tab_userids);$i++):
	$NREQ='<td>'.$tab_users[$tab_userids[$i]]['username'].'</td>';
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ;
	endif;
endfor;
$NREQ='</tr>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;

$NREQ='<tr><th>'.get_string('email','quiz_nitroreportpdf').'</th>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
for($i=0;$i<count($tab_userids);$i++):
	$NREQ='<td class="table_td_highlight">'.$tab_users[$tab_userids[$i]]['email'].'</td>';
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ;
	endif;
endfor;
$NREQ='</tr>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;

if($_POST['show_question_summary']==1):
	for($i=0;$i<count($tab_quizids);$i++):
		$NREQ='<tr>';
		$mpdf->WriteHTML($NREQ);	
		if($generate_html_file):
			$html_contents.=$NREQ;
		endif;
		$NREQ='<th>'.get_string('question2','quiz_nitroreportpdf').' '.($i+1).'.</th>';
		$mpdf->WriteHTML($NREQ);
		if($generate_html_file):
			$html_contents.=$NREQ;
		endif;
		for($j=0;$j<count($tab_userids);$j++):
			if($invertcolorstyle):
				$attach_style=' class="table_td_highlight"';
			endif;
			$NREQ='<td'.$attach_style.'>'.$tab_users[$tab_userids[$j]]['attempt'][$tab_quizids[$i]].'</td>';
			$mpdf->WriteHTML($NREQ);
			if($generate_html_file):
				$html_contents.=$NREQ;
			endif;
		endfor;
		$NREQ='</tr>';
		$mpdf->WriteHTML($NREQ);
		if($generate_html_file):
			$html_contents.=$NREQ;
		endif;
		$invertcolorstyle=!$invertcolorstyle;
		unset($attach_style);
	endfor;
endif;

$NREQ='<tr><th>'.get_string('sum_points2','quiz_nitroreportpdf').'</th>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
for($i=0;$i<count($tab_users);$i++):
	if($invertcolorstyle):
		$attach_style=' class="table_td_highlight"';
	endif;
	$NREQ='<td'.$attach_style.'>'.$tab_users[$tab_userids[$i]]['sum_points'].'</td>';
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ;
	endif;
endfor;
$NREQ='</tr>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
$invertcolorstyle=!$invertcolorstyle;
unset($attach_style);

$NREQ='<tr><th>'.get_string('points_precent','quiz_nitroreportpdf').'</th>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
for($i=0;$i<count($tab_users);$i++):
	if($invertcolorstyle):
		$attach_style=' class="table_td_highlight"';
	endif;
	$NREQ='<td'.$attach_style.'>'.$tab_users[$tab_userids[$i]]['precent'].'</td>';
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ;
	endif;
endfor;
$NREQ='</tr>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
$invertcolorstyle=!$invertcolorstyle;
unset($attach_style);

$NREQ='<tr><th>'.get_string('points_avg','quiz_nitroreportpdf').'</th>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
for($i=0;$i<count($tab_users);$i++):
	if($invertcolorstyle):
		$attach_style=' class="table_td_highlight"';
	endif;
	$NREQ='<td'.$attach_style.'>'.$tab_users[$tab_userids[$i]]['avg'].'</td>';
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ;
	endif;
endfor;
$NREQ='</tr>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
$invertcolorstyle=!$invertcolorstyle;
unset($attach_style);

$NREQ='<tr><th>'.get_string('variance_points','quiz_nitroreportpdf').'</th>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
for($i=0;$i<count($tab_users);$i++):
	if($invertcolorstyle):
		$attach_style=' class="table_td_highlight"';
	endif;
	$NREQ='<td'.$attach_style.'>'.$tab_users[$tab_userids[$i]]['war'].'</td>';
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ;
	endif;
endfor;
$NREQ='</tr>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
$invertcolorstyle=!$invertcolorstyle;
unset($attach_style);

$NREQ='<tr><th>'.get_string('standdeviationpoints','quiz_nitroreportpdf').'</th>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
for($i=0;$i<count($tab_users);$i++):
	if($invertcolorstyle):
		$attach_style=' class="table_td_highlight"';
	endif;
	$NREQ='<td'.$attach_style.'>'.$tab_users[$tab_userids[$i]]['odch'].'</td>';
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ;
	endif;
endfor;
$NREQ='</tr>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
$invertcolorstyle=!$invertcolorstyle;
unset($attach_style);

$NREQ='<tr><th>'.get_string('min_points','quiz_nitroreportpdf').'</th>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
for($j=0;$j<count($tab_userids);$j++):
	if($invertcolorstyle):
		$attach_style=' class="table_td_highlight"';
	endif;
	$NREQ='<td'.$attach_style.'>'.min($tab_users[$tab_userids[$j]]['attempt']).'</td>';
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ;
	endif;
endfor;
$NREQ='</tr>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
$invertcolorstyle=!$invertcolorstyle;
unset($attach_style);

$NREQ='<tr><th>'.get_string('max_points','quiz_nitroreportpdf').'</th>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
for($j=0;$j<count($tab_userids);$j++):
	if($invertcolorstyle):
		$attach_style=' class="table_td_highlight"';
	endif;
	$NREQ='<td'.$attach_style.'>'.max($tab_users[$tab_userids[$j]]['attempt']).'</td>';
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ;
	endif;
endfor;
$NREQ='</tr>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
$invertcolorstyle=!$invertcolorstyle;
unset($attach_style);

$NREQ='<tr><th style="text-transform:capitalize;">'.get_string('grade','quiz_nitroreportpdf').'</th>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
for($j=0;$j<count($tab_userids);$j++):
	if($invertcolorstyle):
		$attach_style=' class="table_td_highlight"';
	endif;
	$NREQ='<td'.$attach_style.'>'.$tab_users[$tab_userids[$j]]['feedback'].'</td>';
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ;
	endif;
endfor;
$NREQ='</tr>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
$invertcolorstyle=!$invertcolorstyle;
unset($attach_style);

$NREQ='<tr><th>'.get_string('notes','quiz_nitroreportpdf').'</th>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
for($j=0;$j<count($tab_userids);$j++):
	if($invertcolorstyle):
		$attach_style=' class="table_td_highlight"';
	endif;
	$NREQ='<td'.$attach_style.' style="width:100px;height:50px;">&nbsp;</td>';
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ;
	endif;
endfor;
$NREQ='</tr>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
$NREQ='</table>';
$mpdf->WriteHTML($NREQ);
if($generate_html_file):
	$html_contents.=$NREQ;
endif;
$invertcolorstyle=!$invertcolorstyle;
unset($attach_style);
if($GENERATE_EXCEL):
	$SheetCount=$objPHPExcel->getSheetCount();
	$objPHPExcel->createSheet(NULL,$SheetCount);
	$objPHPExcel->setActiveSheetIndex($SheetCount);
	$objPHPExcel->getActiveSheet()->setTitle(get_string('summary_sort_a_z','quiz_nitroreportpdf'));
	$objPHPExcel->getActiveSheet()
	->setCellValue('A1', get_string('on','quiz_nitroreportpdf'))
	->setCellValue('B1', get_string('surname','quiz_nitroreportpdf'))
	->setCellValue('C1', get_string('name','quiz_nitroreportpdf'))
	->setCellValue('D1', get_string('username','quiz_nitroreportpdf'))
	->setCellValue('E1', get_string('email','quiz_nitroreportpdf'));
	for($i=0;$i<count($tab_userids);$i++):
		$objPHPExcel->getActiveSheet()
		->setCellValue('A'.($i+2), ($i+1))
		->setCellValue('B'.($i+2), $tab_users[$tab_userids[$i]]['surname'])
		->setCellValue('C'.($i+2), $tab_users[$tab_userids[$i]]['name'])
		->setCellValue('D'.($i+2), $tab_users[$tab_userids[$i]]['username'])
		->setCellValue('E'.($i+2), $tab_users[$tab_userids[$i]]['email']);
		for($j=0;$j<count($tab_quizids);$j++):
			$objPHPExcel->getActiveSheet()->setCellValueByColumnAndRow((5+$j),1,get_string('question2','quiz_nitroreportpdf').' '.($j+1));
			$objPHPExcel->getActiveSheet()->setCellValueByColumnAndRow((5+$j),(2+$i),$tab_users[$tab_userids[$i]]['attempt'][$tab_quizids[$j]]);
			$objPHPExcel->getActiveSheet()->getColumnDimensionByColumn((5+$j))->setOutlineLevel(1)->setVisible(false)->setCollapsed(true);
		endfor;
	endfor;
	$objPHPExcel->getActiveSheet()
	->setCellValueByColumnAndRow((5+$j),1,get_string('sum_points2','quiz_nitroreportpdf'))
	->setCellValueByColumnAndRow((6+$j),1,get_string('points_precent','quiz_nitroreportpdf'))
	->setCellValueByColumnAndRow((7+$j),1,get_string('points_avg','quiz_nitroreportpdf'))
	->setCellValueByColumnAndRow((8+$j),1,get_string('variance_points','quiz_nitroreportpdf'))
	->setCellValueByColumnAndRow((9+$j),1,get_string('standdeviationpoints','quiz_nitroreportpdf'))
	->setCellValueByColumnAndRow((10+$j),1,get_string('min_points','quiz_nitroreportpdf'))
	->setCellValueByColumnAndRow((11+$j),1,get_string('max_points','quiz_nitroreportpdf'))
	->setCellValueByColumnAndRow((12+$j),1,get_string('grade','quiz_nitroreportpdf'))
	->setCellValueByColumnAndRow((13+$j),1,get_string('notes','quiz_nitroreportpdf'));
	for($i=0;$i<count($tab_userids);$i++):
		$objPHPExcel->getActiveSheet()
		->setCellValueByColumnAndRow((5+$j), ($i+2),$tab_users[$tab_userids[$i]]['sum_points'])
		->setCellValueByColumnAndRow((6+$j), ($i+2),$tab_users[$tab_userids[$i]]['precent'])
		->setCellValueByColumnAndRow((7+$j), ($i+2),$tab_users[$tab_userids[$i]]['avg'])
		->setCellValueByColumnAndRow((8+$j), ($i+2),$tab_users[$tab_userids[$i]]['war'])
		->setCellValueByColumnAndRow((9+$j), ($i+2),$tab_users[$tab_userids[$i]]['odch'])
		->setCellValueByColumnAndRow((10+$j), ($i+2),min($tab_users[$tab_userids[$i]]['attempt']))
		->setCellValueByColumnAndRow((11+$j), ($i+2),max($tab_users[$tab_userids[$i]]['attempt']))
		->setCellValueByColumnAndRow((12+$j), ($i+2),strip_tags($tab_users[$tab_userids[$i]]['feedback']));
	endfor;
	$styleArray = array(
		'borders' => array(
			'allborders' => array(
				'style' => PHPExcel_Style_Border::BORDER_THIN,
				'color' => array(
					'rgb' => '000000'
				),
			),
		),
	);
	for($y=0;$y<(14+$j);$y++):
		for($x=1;$x<=count($tab_userids)+1;$x++):
			$objPHPExcel->getActiveSheet()->getStyleByColumnAndRow($y,$x)->getFont()->setSize(14);
			$objPHPExcel->getActiveSheet()->getRowDimension($x)->setRowHeight(19.83);
			$objPHPExcel->getActiveSheet()->getStyleByColumnAndRow($y,$x)->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
			$objPHPExcel->getActiveSheet()->getStyleByColumnAndRow($y,$x)->applyFromArray($styleArray);
			if($x > 1)
				$objPHPExcel->getActiveSheet()->getStyleByColumnAndRow((13+$j),$x)->getProtection()->setLocked(PHPExcel_Style_Protection::PROTECTION_UNPROTECTED);
			if($x % 2 == 1):
				$objPHPExcel->getActiveSheet()->getStyleByColumnAndRow($y,$x)->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
				$objPHPExcel->getActiveSheet()->getStyleByColumnAndRow($y,$x)->getFill()->getStartColor()->setRGB('FFFFA1');
			endif;
		endfor;
		$objPHPExcel->getActiveSheet()->getStyleByColumnAndRow($y,1)->getFont()->setBold(true);
		$objPHPExcel->getActiveSheet()->getColumnDimensionByColumn($y)->setAutoSize(true);
		$objPHPExcel->getActiveSheet()->getStyleByColumnAndRow($y,1)->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
		$objPHPExcel->getActiveSheet()->getStyleByColumnAndRow($y,1)->getFill()->getStartColor()->setRGB('0057AF');
		$objPHPExcel->getActiveSheet()->getStyleByColumnAndRow($y,1)->getFont()->getColor()->setARGB(PHPExcel_Style_Color::COLOR_WHITE);
	endfor;
	$objPHPExcel->getActiveSheet()->freezePane('A2');
	$objPHPExcel->getActiveSheet()->getProtection()->setPassword(substr(hash('sha512',rand()),0,12));
	$objPHPExcel->getActiveSheet()->getProtection()->setSheet(true);
	$objPHPExcel->getActiveSheet()->getProtection()->setFormatCells(true);
	$objPHPExcel->getActiveSheet()->getHeaderFooter()->setOddFooter('&L&D, &T &R &P / &N');
	/*		SORT BY PRECENT		*/
	$SheetCount=$objPHPExcel->getSheetCount();
	$objPHPExcel->createSheet(NULL,$SheetCount);
	$objPHPExcel->setActiveSheetIndex($SheetCount);
	$objPHPExcel->getActiveSheet()->setTitle(get_string('summary_sort_precents','quiz_nitroreportpdf'));
	$objPHPExcel->getActiveSheet()->setCellValue('A1',get_string('on','quiz_nitroreportpdf'))
	->setCellValue('B1',get_string('surname','quiz_nitroreportpdf'))
	->setCellValue('C1',get_string('name','quiz_nitroreportpdf'))
	->setCellValue('D1',get_string('username','quiz_nitroreportpdf'))
	->setCellValue('E1',get_string('sum_points2','quiz_nitroreportpdf'))
	->setCellValue('F1',get_string('points_precent','quiz_nitroreportpdf'))
	->setCellValue('G1',get_string('points_avg','quiz_nitroreportpdf'))
	->setCellValue('H1',get_string('min_points','quiz_nitroreportpdf'))
	->setCellValue('I1',get_string('max_points','quiz_nitroreportpdf'))
	->setCellValue('J1',get_string('grade','quiz_nitroreportpdf'));
	$objPHPExcel->getActiveSheet()->getRowDimension(1)->setRowHeight(19.83);
	$tab_userids_temp=$tab_userids;
	if(count($tab_userids_temp)>1):
		$bubble_end=true;
		while($bubble_end):
			$bubble_end=false;
			for($y=0;$y<count($tab_userids_temp)-1;$y++):
				if($tab_users[$tab_userids_temp[$y+1]]['precent'] > $tab_users[$tab_userids_temp[$y]]['precent']):
					$temp=$tab_userids_temp[$y];
					$tab_userids_temp[$y]=$tab_userids_temp[$y+1];
					$tab_userids_temp[$y+1]=$temp;
					$bubble_end=true;
				endif;
			endfor;
		endwhile;
	endif;
	for($i=0;$i<count($tab_userids_temp);$i++):
		$objPHPExcel->getActiveSheet()->setCellValue('A'.($i+2), ($i+1))
		->setCellValue('B'.($i+2), $tab_users[$tab_userids_temp[$i]]['surname'])
		->setCellValue('C'.($i+2), $tab_users[$tab_userids_temp[$i]]['name'])
		->setCellValue('D'.($i+2), $tab_users[$tab_userids_temp[$i]]['username'])
		->setCellValue('E'.($i+2), $tab_users[$tab_userids_temp[$i]]['sum_points'])
		->setCellValue('G'.($i+2), $tab_users[$tab_userids_temp[$i]]['precent'])
		->setCellValue('F'.($i+2), $tab_users[$tab_userids_temp[$i]]['avg'])
		->setCellValue('H'.($i+2), min($tab_users[$tab_userids_temp[$i]]['attempt']))
		->setCellValue('I'.($i+2), max($tab_users[$tab_userids_temp[$i]]['attempt']))
		->setCellValue('J'.($i+2), strip_tags($tab_users[$tab_userids_temp[$i]]['feedback']));
		$objPHPExcel->getActiveSheet()->getRowDimension(($i+2))->setRowHeight(19.83);
		if($i % 2 == 1):
			$objPHPExcel->getActiveSheet()->getStyle('A'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('A'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
			$objPHPExcel->getActiveSheet()->getStyle('B'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('B'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
			$objPHPExcel->getActiveSheet()->getStyle('C'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('C'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
			$objPHPExcel->getActiveSheet()->getStyle('D'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('D'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
			$objPHPExcel->getActiveSheet()->getStyle('E'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('E'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
			$objPHPExcel->getActiveSheet()->getStyle('F'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('F'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
			$objPHPExcel->getActiveSheet()->getStyle('G'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('G'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
			$objPHPExcel->getActiveSheet()->getStyle('H'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('H'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
			$objPHPExcel->getActiveSheet()->getStyle('I'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('I'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
			$objPHPExcel->getActiveSheet()->getStyle('J'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('J'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
		endif;
	endfor;
	$objPHPExcel->getActiveSheet()->freezePane('A2');
	$styleArray = array(
		'borders' => array(
			'allborders' => array(
				'style' => PHPExcel_Style_Border::BORDER_THIN,
					'color' => array(
						'rgb' => '000000'
					),
				),
			),
		);
	$objPHPExcel->getActiveSheet()->getStyle('A1:J'.($i+1))->applyFromArray($styleArray);
	$objPHPExcel->getActiveSheet()->getStyle('A1:J'.($i+1))->getFont()->setSize(14);
	$objPHPExcel->getActiveSheet()->getStyle('A1:J'.($i+1))->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
	$objPHPExcel->getActiveSheet()->getStyle('A1:J1')->getFont()->setBold(true);
	$objPHPExcel->getActiveSheet()->getStyle('A1:J1')->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
	$objPHPExcel->getActiveSheet()->getStyle('A1:J1')->getFill()->getStartColor()->setRGB('0057AF');
	$objPHPExcel->getActiveSheet()->getStyle('A1:J1')->getFont()->getColor()->setARGB(PHPExcel_Style_Color::COLOR_WHITE);
	$this->AutoWidthColumn($objPHPExcel->getActiveSheet(),0,9,($i+1));
	$objPHPExcel->getActiveSheet()->getProtection()->setPassword(substr(hash('sha512',rand()),0,12));
	$objPHPExcel->getActiveSheet()->getProtection()->setSheet(true);
	$objPHPExcel->getActiveSheet()->getProtection()->setFormatCells(true);
	$objPHPExcel->getActiveSheet()->getHeaderFooter()->setOddFooter('&L&D, &T &R &P / &N');
	/*		SHORT INFO TO PRINT	*/
	$SheetCount=$objPHPExcel->getSheetCount();
	$objPHPExcel->createSheet(NULL,$SheetCount);
	$objPHPExcel->setActiveSheetIndex($SheetCount);
	$objPHPExcel->getActiveSheet()->setTitle(get_string('short_summary','quiz_nitroreportpdf'));
	$objPHPExcel->getActiveSheet()->setCellValue('A1',get_string('on','quiz_nitroreportpdf'))
	->setCellValue('B1',get_string('surname','quiz_nitroreportpdf'))
	->setCellValue('C1',get_string('name','quiz_nitroreportpdf'))
	->setCellValue('D1',get_string('username','quiz_nitroreportpdf'))
	->setCellValue('E1',get_string('sum_points2','quiz_nitroreportpdf'))
	->setCellValue('F1',get_string('points_precent','quiz_nitroreportpdf'))
	->setCellValue('G1',get_string('points_avg','quiz_nitroreportpdf'))
	->setCellValue('H1',get_string('min_points','quiz_nitroreportpdf'))
	->setCellValue('I1',get_string('max_points','quiz_nitroreportpdf'))
	->setCellValue('J1',get_string('grade','quiz_nitroreportpdf'))
	->setCellValue('K1',get_string('notes','quiz_nitroreportpdf'));
	$objPHPExcel->getActiveSheet()->getRowDimension(1)->setRowHeight(19.83);
	for($i=0;$i<count($tab_userids);$i++):
		$objPHPExcel->getActiveSheet()
		->setCellValue('A'.($i+2), ($i+1))
		->setCellValue('B'.($i+2), $tab_users[$tab_userids[$i]]['surname'])
		->setCellValue('C'.($i+2), $tab_users[$tab_userids[$i]]['name'])
		->setCellValue('D'.($i+2), $tab_users[$tab_userids[$i]]['username'])
		->setCellValue('E'.($i+2), $tab_users[$tab_userids[$i]]['sum_points'])
		->setCellValue('G'.($i+2), $tab_users[$tab_userids[$i]]['precent'])
		->setCellValue('F'.($i+2), $tab_users[$tab_userids[$i]]['avg'])
		->setCellValue('H'.($i+2), min($tab_users[$tab_userids[$i]]['attempt']))
		->setCellValue('I'.($i+2), max($tab_users[$tab_userids[$i]]['attempt']))
		->setCellValue('J'.($i+2), strip_tags($tab_users[$tab_userids[$i]]['feedback']));
		$objPHPExcel->getActiveSheet()->getRowDimension(($i+2))->setRowHeight(19.83);
		if($i % 2 == 1):
			$objPHPExcel->getActiveSheet()->getStyle('A'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('A'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
			$objPHPExcel->getActiveSheet()->getStyle('B'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('B'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
			$objPHPExcel->getActiveSheet()->getStyle('C'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('C'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
			$objPHPExcel->getActiveSheet()->getStyle('D'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('D'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
			$objPHPExcel->getActiveSheet()->getStyle('E'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('E'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
			$objPHPExcel->getActiveSheet()->getStyle('F'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('F'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
			$objPHPExcel->getActiveSheet()->getStyle('G'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('G'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
			$objPHPExcel->getActiveSheet()->getStyle('H'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('H'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
			$objPHPExcel->getActiveSheet()->getStyle('I'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('I'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
			$objPHPExcel->getActiveSheet()->getStyle('J'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('J'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
			$objPHPExcel->getActiveSheet()->getStyle('K'.($i+2))->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
			$objPHPExcel->getActiveSheet()->getStyle('K'.($i+2))->getFill()->getStartColor()->setRGB('FFFFA1');
		endif;
	endfor;
	$objPHPExcel->getActiveSheet()->freezePane('A2');
	$styleArray = array
	(
		'borders' => array
		(
			'allborders' => array
			(
				'style' => PHPExcel_Style_Border::BORDER_THIN,
					'color' => array
					(
						'rgb' => '000000'
					),
			),
		),
	);
	$objPHPExcel->getActiveSheet()->getStyle('A1:K'.($i+1))->applyFromArray($styleArray);
	$objPHPExcel->getActiveSheet()->getStyle('A1:K'.($i+1))->getFont()->setSize(14);
	$objPHPExcel->getActiveSheet()->getStyle('A1:K'.($i+1))->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
	$objPHPExcel->getActiveSheet()->getStyle('A1:K1')->getFont()->setBold(true);
	$objPHPExcel->getActiveSheet()->getStyle('A1:K1')->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID);
	$objPHPExcel->getActiveSheet()->getStyle('A1:K1')->getFill()->getStartColor()->setRGB('0057AF');
	$objPHPExcel->getActiveSheet()->getStyle('A1:K1')->getFont()->getColor()->setARGB(PHPExcel_Style_Color::COLOR_WHITE);
	$objPHPExcel->getActiveSheet()->getStyle('A1:K'.($i+1)) ->getAlignment()->setWrapText(false);
	$this->AutoWidthColumn($objPHPExcel->getActiveSheet(),0,9,($i+1));
	$objPHPExcel->getActiveSheet()->getProtection()->setPassword(substr(hash('sha512',rand()),0,12));
	$objPHPExcel->getActiveSheet()->getProtection()->setSheet(true);
	$objPHPExcel->getActiveSheet()->getProtection()->setFormatCells(true);
	$objPHPExcel->getActiveSheet()->getStyle('K2:K'.($i+1))->getProtection()->setLocked(PHPExcel_Style_Protection::PROTECTION_UNPROTECTED);
	$objPHPExcel->getActiveSheet()->getHeaderFooter()->setOddFooter('&L&D, &T &R &P / &N');
endif;
// ADD DECLARATION PAGE
if ( (get_config('quiz_nitroreportpdf','declaration') == 'DECLARATION_MUSTBE') || ($_POST['declaration'] == 1)	):
	$mpdf->AddPage();
	$mpdf->Bookmark(get_string('declaration_dontaccess','quiz_nitroreportpdf'),0);
	$NREQ='
	<p style="margin-left: auto; margin-right: auto;text-transform:uppercase;"><h2>'.get_string('declaration_dontaccess','quiz_nitroreportpdf').'</h2></p>
	<br />
	<p style="text-align: justify;">'.get_string('declaration_dontaccess_desc','quiz_nitroreportpdf').'</p>
	<br /><br />
	'.get_string('declaration_authorrights','quiz_nitroreportpdf').':<br />
	'.get_config('quiz_nitroreportpdf','contact').'
	<br />
	'.get_string('wwwmoodleplatform','quiz_nitroreportpdf').': '.$CFG->wwwroot.'
	<br /><br />
	'.get_string('declaration_accessexclude','quiz_nitroreportpdf').'.';
	$mpdf->WriteHTML($NREQ);
	if($generate_html_file):
		$html_contents.=$NREQ;
	endif;
	
endif;
echo '<script>document.getElementById(\'nitroreportpdf_text\').style.display = \'none\';</script>';
echo '<script>document.getElementById(\'nitroreportpdf_progress\').style.display = \'none\';</script>';
echo '<script>document.getElementById("nitro_submit").disabled=false;</script>';


$pdffile=preg_replace(array('/\\\/','/\//','/\:/','/\*/','/\?/','/\"/','/\</','/\>/','/\|/',"/\t/","/\s/"),'',($info_quiz->name.'_'.$info_course->fullname.'_'.date('d-m-Y-H-i-s')));
$mpdf->Output($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$pdffile.'.pdf','F');		
$fs = get_file_storage();	
$context = context_user::instance($USER->id);
$fs->create_directory($context->id,'user','private',0,'/NRPDF_Reports/',$USER->id);	
$fs->create_file_from_pathname(array('contextid'=>$context->id,'component'=>'user','filearea'=>'private','itemid'=>0,'filepath'=>'/NRPDF_Reports/','filename'=>$pdffile.'.pdf','timecreated'=>time(),'timemodified'=>time(),'userid'=>$USER->id), $CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$pdffile.'.pdf');

if($GENERATE_EXCEL):
	$objPHPExcel->getSheet(0)->setSheetState(PHPExcel_Worksheet::SHEETSTATE_HIDDEN);;
	$objPHPExcel->setActiveSheetIndex($objPHPExcel->getSheetCount()-1);
	$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');
	$objWriter->save($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$pdffile.'.xlsx');
	$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel5');
	$objWriter->save($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$pdffile.'.xls');
	$fs->create_file_from_pathname(array('contextid'=>$context->id,'component'=>'user','filearea'=>'private','itemid'=>0,'filepath'=>'/NRPDF_Reports/','filename'=>$pdffile.'.xlsx','timecreated'=>time(),'timemodified'=>time(),'userid'=>$USER->id), $CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$pdffile.'.xlsx');
	$fs->create_file_from_pathname(array('contextid'=>$context->id,'component'=>'user','filearea'=>'private','itemid'=>0,'filepath'=>'/NRPDF_Reports/','filename'=>$pdffile.'.xls','timecreated'=>time(),'timemodified'=>time(),'userid'=>$USER->id), $CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$pdffile.'.xls');	
endif;


/////////////////////////////////////////////////////////
//GENERATE ZIP
if($_POST['generate_zip']):
	$isoffline=false;
	if($_POST['zip_type'] == "offline"):
		if(file_exists($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/nrpdf_prepack.zip')):
			$isoffline=true;
		endif;
	endif;
	$zip = new ZipArchive();
	$towrite='<meta charset="utf-8">';
	if(!$isoffline):
		$towrite.='<script type="text/javascript" src="https://cdn.mathjax.org/mathjax/latest/MathJax.js?config=TeX-AMS-MML_HTMLorMML"></script>
		<script src="http://nitro2010.github.io/mathjax/MathJax.js?config=TeX-AMS-MML_HTMLorMML"></script>
		<link href="http://vjs.zencdn.net/4.12/video-js.css" rel="stylesheet">
		<script src="http://vjs.zencdn.net/4.12/video.js"></script>
		<link href="http://nitro2010.github.io/video-js/video-js.css" rel="stylesheet">
		<script src="http://nitro2010.github.io/video-js/video.js"></script>
		<script src="http://nitro2010.github.io/video-js/vjs.youtube.js"></script>
		<script src="http://nitro2010.github.io/video-js/vjs.vimeo.js"></script>
		<script src="http://nitro2010.github.io/video-js/vjs.dailymotion.js"></script>
		<script src="http://nitro2010.github.io/video-js/media.soundcloud.js"></script>
		<script>
			videojs.options.flash.swf = "http://nitro2010.github.io/video-js/video-js.swf"
		</script>
		<script type="text/javascript" src="http://nitro2010.github.io/bgp/bpgdec8.js"></script>
		<script type="text/javascript" src="http://nitro2010.github.io/bgp/bpgdec.js"></script>
		<script type="text/javascript" src="http://nitro2010.github.io/bgp/bpgdec8a.js"></script>';
		$name=md5(uniqid());
		$zip->open($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$name.'.zip', ZIPARCHIVE::CREATE);
	else:
		$towrite='<script type="text/javascript" src="js/mathjax/MathJax.js?config=TeX-AMS-MML_HTMLorMML"></script>
		<script type="text/javascript" src="js/video-js/video-js.css"></script>
		<script type="text/javascript" src="js/video-js/video.js"></script>
		<script type="text/javascript" src="js/video-js/vjs.youtube.js"></script>
		<script type="text/javascript" src="js/video-js/vjs.vimeo.js"></script>
		<script type="text/javascript" src="js/video-js/vjs.dailymotion.js"></script>
		<script type="text/javascript" src="js/video-js/media.soundcloud.js"></script>
		<script>
			videojs.options.flash.swf = "js/video-js/video-js.swf"
		</script>
		<script type="text/javascript" src="js/bpg/bpgdec8.js"></script>
		<script type="text/javascript" src="js/bpg/bpgdec.js"></script>
		<script type="text/javascript" src="js/bpg/bpgdec8a.js"></script>';
		$name=md5(uniqid());
		copy($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/nrpdf_prepack.zip',$CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$name.'.zip');
		$zip->open($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$name.'.zip');
	endif;
	$towrite.=$html_contents;
	$zip->addEmptyDir('files');

	$audio[]=array('ext'=>'mp3','type'=>'audio/mp3');
	$audio[]=array('ext'=>'webm','type'=>'audio/webm');
	$audio[]=array('ext'=>'ogg','type'=>'audio/ogg');
	$audio[]=array('ext'=>'wav','type'=>'audio/wave');

	$video[]=array('ext'=>'webm','type'=>'video/webm');
	$video[]=array('ext'=>'ogg','type'=>'video/ogg');
	$video[]=array('ext'=>'mp4','type'=>'video/mp4');

	$image=array('png','jpg','gif','bpg');


	for($a=0;$a<count($audio);$a++):
		preg_match_all('/<a.*".*\/mod\/quiz\/report\/nitroreportpdf\/cache\/(.*).'.$audio[$a]['ext'].'".*<\/a>/Ui',$towrite,$found);
		for($i=0;$i<count($found[0]);$i++):
			$zip->addFile($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$found[1][$i].'.'.$audio[$a]['ext'],'files/'.pathinfo($found[1][$i])['filename'].'.'.$audio[$a]['ext']);
			$towrite=str_replace($found[0][$i],'<audio id="'.uniqid().'" class="video-js vjs-default-skin" controls preload="auto" width="640" height="264"> <source src="files/'.pathinfo($found[1][$i])['filename'].'.'.$audio[$a]['ext'].'"  type="'.$audio[$a]['type'].'" /></audio>',$towrite);
		endfor;
		preg_match_all('/<.*".*\/mod\/quiz\/report\/nitroreportpdf\/cache\/(.*).'.$audio[$a]['ext'].'".*>/Ui',$towrite,$found);
		for($i=0;$i<count($found[0]);$i++):
			$zip->addFile($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$found[1][$i].'.'.$audio[$a]['ext'],'files/'.pathinfo($found[1][$i])['filename'].'.'.$audio[$a]['ext']);
			$towrite=str_replace($found[0][$i],'<audio id="'.uniqid().'"  class="video-js vjs-default-skin" controls preload="auto" width="640" height="264"> <source src="files/'.pathinfo($found[1][$i])['filename'].'.'.$audio[$a]['ext'].'"  type="'.$audio[$a]['type'].'" /></audio>',$towrite);
		endfor;
	endfor;

	for($a=0;$a<count($video);$a++):
		preg_match_all('/<a.*".*\/mod\/quiz\/report\/nitroreportpdf\/cache\/(.*).'.$video[$a]['ext'].'".*<\/a>/Ui',$towrite,$found);
		for($i=0;$i<count($found[0]);$i++):
			$zip->addFile($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$found[1][$i].'.'.$video[$a]['ext'],'files/'.pathinfo($found[1][$i])['filename'].'.'.$video[$a]['ext']);
			$towrite=str_replace($found[0][$i],'<video id="'.uniqid().'"   class="video-js vjs-default-skin" controls preload="auto" width="320" height="264"> <source src="files/'.pathinfo($found[1][$i])['filename'].'.'.$video[$a]['ext'].'"  type="'.$video[$a]['type'].'" /></video>',$towrite);
		endfor;
		preg_match_all('/<.*".*\/mod\/quiz\/report\/nitroreportpdf\/cache\/(.*).'.$video[$a]['ext'].'".*>/Ui',$towrite,$found);
		for($i=0;$i<count($found[0]);$i++):
			$zip->addFile($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$found[1][$i].'.'.$video[$a]['ext'],'files/'.pathinfo($found[1][$i])['filename'].'.'.$video[$a]['ext']);
			$towrite=str_replace($found[0][$i],'<video id="'.uniqid().'"   class="video-js vjs-default-skin" controls preload="auto" width="320" height="264"> <source src="files/'.pathinfo($found[1][$i])['filename'].'.'.$video[$a]['ext'].'"  type="'.$video[$a]['type'].'" /></video>',$towrite);
		endfor;
	endfor;

	for($a=0;$a<count($image);$a++):
		preg_match_all('/<img.*".*\/mod\/quiz\/report\/nitroreportpdf\/cache\/(.*).'.$image[$a].'".*>/Ui',$towrite,$found);
		for($i=0;$i<count($found[0]);$i++):
			$zip->addFile($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$found[1][$i].'.'.$image[$a],'files/'.pathinfo($found[1][$i])['filename'].'.'.$image[$a]);
			$towrite=str_replace($found[0][$i],'<img src="files/'.pathinfo($found[1][$i])['filename'].'.'.$image[$a].'" />',$towrite);
		endfor;
	endfor;

	//YT
	preg_match_all('/<a.*".*(youtu\.be|youtube).*".*<\/a>/Ui',$towrite,$found);
	for($a=0;$a<count($found);$a++):
		$f=$found[0][$a];
		preg_match('/<a.*"(.*)".*<\/a>/Ui',$f,$found2);
		$towrite=str_replace($found2[0],'<video id="'.uniqid().'" src="" class="video-js vjs-default-skin" controls preload="auto" width="640" height="360" data-setup=\'{ "techOrder": ["youtube"], "src": "'.$found2[1].'" }\'></video>',$towrite);
	endfor;

	//VIMEO
	preg_match_all('/<a.*".*(vimeo).*".*<\/a>/Ui',$towrite,$found);
	for($a=0;$a<count($found);$a++):
		$f=$found[0][$a];
		preg_match('/<a.*"(.*)".*<\/a>/Ui',$f,$found2);
		$towrite=str_replace($found2[0],'<video id="'.uniqid().'" src="" class="video-js vjs-default-skin" controls preload="auto" width="640" height="360" data-setup=\'{ "techOrder": ["vimeo"], "src": "'.$found2[1].'" }\'></video>',$towrite);
	endfor;

	//dailymotion
	preg_match_all('/<a.*".*(dailymotion).*".*<\/a>/Ui',$towrite,$found);
	for($a=0;$a<count($found);$a++):
		$f=$found[0][$a];
		preg_match('/<a.*"(.*)".*<\/a>/Ui',$f,$found2);
		$towrite=str_replace($found2[0],'<video id="'.uniqid().'" src="" class="video-js vjs-default-skin" controls preload="auto" width="640" height="360" data-setup=\'{ "techOrder": ["dailymotion"], "src": "'.$found2[1].'" }\'></video>',$towrite);
	endfor;

	//soundcloud
	preg_match_all('/<a.*".*(soundcloud).*".*<\/a>/Ui',$towrite,$found);
	for($a=0;$a<count($found);$a++):
		$f=$found[0][$a];
		preg_match('/<a.*"(.*)".*<\/a>/Ui',$f,$found2);
		$towrite=str_replace($found2[0],'<video id="'.uniqid().'" src="" class="video-js vjs-default-skin" controls preload="auto" width="640" height="360" data-setup=\'{ "techOrder": ["soundcloud"], "src": "'.$found2[1].'" }\'></video>',$towrite);
	endfor;
	//add index.html
	$zip->addFromString('index.html',$towrite);
	$zip->close();
	$fs->create_file_from_pathname(array('contextid'=>$context->id,'component'=>'user','filearea'=>'private','itemid'=>0,'filepath'=>'/NRPDF_Reports/','filename'=>$pdffile.'.zip','timecreated'=>time(),'timemodified'=>time(),'userid'=>$USER->id), $CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$name.'.zip');
endif;
/////////////////////////////////////////////////////////
echo '<br /><br /><br />'.get_string('files_are_generated','quiz_nitroreportpdf').'! <a href="'.$CFG->wwwroot.'/user/files.php" target="_blank">'.get_string('ucandownloadfromprivatearea','quiz_nitroreportpdf').'.</a><br />';
$tab_quiz_sum=0;
foreach($tab_quiz AS $id => $ext):
	$tab_quiz_sum+=max($ext['points']);
endforeach;
$tab_quiz_sum=number_format($tab_quiz_sum,4,'.','');
if($maxpoints != $tab_quiz_sum):
	echo '<br /><br /><br /><span style="color:red;\">'.get_string('warning1','quiz_nitroreportpdf').'</span>';	
endif;
		endif;
	}
//=================================					FUNCTIONS
	/*	 Get information about moodle user	*/
	protected function nitro_get_user($id)
	{
		global $DB;
		$user = $DB->get_record_sql('SELECT username,firstname,lastname,email,institution,department,timecreated,lastlogin,picture FROM {user} WHERE id = '.$id);
		return $user;
	}

	/*	 Get information about moodle course	*/
	protected function nitro_get_course($id)
	{
		global $DB;
		$course = $DB->get_record_sql('SELECT fullname,shortname,timecreated,timemodified FROM {course} WHERE id = '.$id);
		return $course;
	}

	/*	 Get information about moodle quiz	*/
	protected function nitro_get_quiz($id)
	{
		global $DB;
		$quiz = $DB->get_record_sql('SELECT course,name,timeopen,timeclose,timelimit,grademethod,decimalpoints,questiondecimalpoints,sumgrades,intro FROM {quiz} WHERE id = '.$id);
		return $quiz;
	}

	protected function nitro_convert_time($time)
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
		
	protected function files_from_db_img($component,$filearea,$options=array(),$text, $allfiles = false)
	{
		global $CFG, $DB;
		if($allfiles):
			preg_match_all('/<.*src=\"@@PLUGINFILE@@(.*)\".*>/Ui',$text,$found);
		else:
			preg_match_all('/<img*src=\"@@PLUGINFILE@@(.*)\".*>/Ui',$text,$found);		
		endif;
		if(count($found[1])>0):
			foreach($found[1] AS $file2):
				$fpath=@rawurldecode(preg_replace('/\\/','/',pathinfo($file2)['dirname']).'/');
				$ffile=@rawurldecode(pathinfo($file2)['basename']);
				$file_context = $DB->get_record_sql('SELECT contextid,contenthash,itemid,filesize,timecreated,timemodified FROM {files} WHERE component="'.$component.'" AND filearea="'.$filearea.'" AND filepath="'.$fpath.'" AND filename="'.$ffile.'" AND mimetype<>"" AND filename<>"."'.' '.$options['extra_sql']);
				$filename=hash('sha384',$component.$filearea.$file_context->filesize.$file_context->timecreated.$file_context->timemodified.$file_context->contenthash.$fpath.$ffile).'.'.pathinfo($ffile)['extension'];
				if(!file_exists($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$filename)):
					$fs = null;
					$file = null;
					$fs = get_file_storage();
					$file = $fs->get_file($file_context->contextid,$component,$filearea,$file_context->itemid,$fpath,$ffile);
					if($file):
						$file->copy_content_to($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$filename);
					endif; // file
				endif;
				$text=preg_replace('/@@PLUGINFILE@@'.preg_replace('/\//','\/',$file2).'/',$CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$filename,$text);
				touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$filename);
			endforeach;
		endif; //if are some file to process
		//A HREF
		if($allfiles):
			preg_match_all('/<a.*href=\"@@PLUGINFILE@@(.*)\".*>/Ui',$text,$found);
		else:
			preg_match_all('/<a.*href=\"@@PLUGINFILE@@(.*)\.(jpg|png|gif|jpeg|tiff|tif|bmp|ppm|pgm|pbm|pnm|webp|bgp)\".*>/Ui',$text,$found);		
		endif;	
		if(count($found[1])>0):
			foreach($found[1] AS $file2):
				$fpath=@rawurldecode(preg_replace('/\\/','/',pathinfo($file2)['dirname']).'/');
				$ffile=@rawurldecode(pathinfo($file2)['basename']);
				$file_context = $DB->get_record_sql('SELECT contextid,contenthash,itemid,filesize,timecreated,timemodified FROM {files} WHERE component="'.$component.'" AND filearea="'.$filearea.'" AND filepath="'.$fpath.'" AND filename="'.$ffile.'" AND mimetype<>"" AND filename<>"."'.' '.$options['extra_sql']);
				$filename=hash('sha384',$component.$filearea.$file_context->filesize.$file_context->timecreated.$file_context->timemodified.$file_context->contenthash.$fpath.$ffile).'.'.pathinfo($ffile)['extension'];
				if(!file_exists($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$filename)):
					$fs = null;
					$file = null;
					$fs = get_file_storage();
					$file = $fs->get_file($file_context->contextid,$component,$filearea,$file_context->itemid,$fpath,$ffile);
					if($file):
						$file->copy_content_to($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$filename);
					endif; // file
				endif;
				$text=preg_replace('/@@PLUGINFILE@@'.preg_replace('/\//','\/',$file2).'/',$CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$filename,$text);
				touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$filename);
			endforeach;
		endif; //if are some file to process	
		return $text;
	}

	protected function nitro_get_multianswer_correct_answer($c)
	{
		//return tab
		$tab_return=array();
		//search type of question
		preg_match('/([0-9]*):(NUMERICAL|NM|SHORTANSWER|SA|MW|SHORTANSWER_C|SAC|MWC|MULTICHOICE|MC|MULTICHOICE_V|MCV|MULTICHOICE_H|MCH):(.*)/',$c,$multi_type);
		//points
		$points=(empty($multi_type[1])) ? 0 : $multi_type[1];
		//type
		$type=strtoupper($multi_type[2]);
		if(in_array($type,array('NUMERICAL','NM'))):
			$correct_answer='';
			$answer = $multi_type[3];
			$to_replace=array("/#(.*)~/U","/#(.*)}/U","/{/","/}/");
			$replace=array("~","}","","");
			$answer=preg_replace($to_replace,$replace,$answer);
			$answers=explode('~',$answer);
			for($i=0;$i<count($answers);$i++):
				if((substr($answers[$i],0,1)=='%')||(substr($answers[$i],0,1)=='=')||(substr($answers[$i],0,1)=='*')):
					if(substr($answers[$i],0,1)=='%'):
						preg_match('/%([0-9.]+)%(.*)/',$answers[$i],$proc_answers);
						preg_match('/(.*):(.*)/',$proc_answers[2],$find_colon);
						if(count($find_colon)>0):
							$ans=($find_colon[1]-$find_colon[2]).'-'.($find_colon[1]+$find_colon[2]);
						else:
							$ans=$proc_answers[2];
						endif;
						$tab_return['answers'][]=$ans;
						$tab_return['type'][]=(count($find_colon)==0) ? 'single' : 'range';
						$tab_return['typewiris'][]='numerical';
						$tab_return['points'][]=($proc_answers[1]/100)*$points;
						if($proc_answers[1] == 100):
							$tab_return['correct']=(count($tab_return['answers'])-1);
						endif;
					elseif(substr($answers[$i],0,1)=='='):
						$ans=substr($answers[$i],1);
						preg_match('/(.*):(.*)/',$ans,$find_colon);
						if(count($find_colon)>0):
							$ans2=($find_colon[1]-$find_colon[2]).'-'.($find_colon[1]+$find_colon[2]);
						else:
							$ans2=$ans;
						endif;
						$tab_return['answers'][]=$ans2;
						$tab_return['type'][]=(count($find_colon)==0) ? 'single' : 'range';
						$tab_return['typewiris'][]='numerical';
						$tab_return['points'][]=$points;				
						$tab_return['correct']=(count($tab_return['answers'])-1);
					else:
						$ans=substr($answers[$i],1);
						preg_match('/(.*):(.*)/',$ans,$find_colon);
						if(count($find_colon)>0):
							$ans2=($find_colon[1]-$find_colon[2]).'-'.($find_colon[1]+$find_colon[2]);
						else:
							$ans2=$ans;
						endif;
						$tab_return['answers'][]=$ans2;
						$tab_return['type'][]=(count($find_colon)==0) ? 'single' : 'range';
						$tab_return['typewiris'][]='numerical';
						$tab_return['points'][]=0;					
					endif;
				else:
					$tab_return['answers'][]=$answers[$i];
					$tab_return['points'][]=0;	
					$tab_return['typewiris'][]='numerical';
				endif;
			endfor;
			return $tab_return;
		elseif(in_array($type,array('SHORTANSWER','SA','MW','SHORTANSWER_C','SAC','MWC'))):
			$correct_answer='';
			$answer = $multi_type[3];
			$to_replace=array("/#(.*)~/U","/#(.*)}/U","/{/","/}/");
			$replace=array("~","}","","");
			$answer=preg_replace($to_replace,$replace,$answer);
			$answers=explode('~',$answer);
			for($i=0;$i<count($answers);$i++):
				if((substr($answers[$i],0,1)=='%')||(substr($answers[$i],0,1)=='=')||(substr($answers[$i],0,1)=='*')):
					if(substr($answers[$i],0,1)=='%'):
						preg_match('/%([0-9.]+)%(.*)/',$answers[$i],$proc_answers);
						$tab_return['answers'][]=$proc_answers[2];
						$tab_return['points'][]=($proc_answers[1]/100)*$points;
						$tab_return['typewiris'][]='shortanswer';
						if($proc_answers[1] == 100):
							$tab_return['correct']=(count($tab_return['answers'])-1);
						endif;
					elseif(substr($answers[$i],0,1)=='='):
						$tab_return['answers'][]=substr($answers[$i],1);
						$tab_return['points'][]=$points;	
						$tab_return['typewiris'][]='shortanswer';						
						$tab_return['correct']=(count($tab_return['answers'])-1);
					else:
						$tab_return['answers'][]=substr($answers[$i],1);
						$tab_return['typewiris'][]='shortanswer';
						$tab_return['points'][]=0;
					endif;
				else:
					$tab_return['answers'][]=$answers[$i];
					$tab_return['points'][]=0;	
					$tab_return['typewiris'][]='shortanswer';					
				endif;
			endfor;
			return $tab_return;
		elseif(in_array($type,array('MULTICHOICE','MC','MULTICHOICE_V','MCV','MULTICHOICE_H','MCH'))):
		$correct_answer='';
			$answer = $multi_type[3];
			$to_replace=array("/#(.*)~/U","/#(.*)}/U","/{/","/}/");
			$replace=array("~","}","","");
			$answer=preg_replace($to_replace,$replace,$answer);
			$answers=explode('~',$answer);
			for($i=0;$i<count($answers);$i++):
				if((substr($answers[$i],0,1)=='%')||(substr($answers[$i],0,1)=='=')||(substr($answers[$i],0,1)=='*')):
					if(substr($answers[$i],0,1)=='%'):
						preg_match('/%([0-9.]+)%(.*)/',$answers[$i],$proc_answers);
						$tab_return['answers'][]=$proc_answers[2];
						$tab_return['points'][]=($proc_answers[1]/100)*$points;
						$tab_return['typewiris'][]='multichoice';
						if($proc_answers[1] == 100):
							$tab_return['correct']=(count($tab_return['answers'])-1);
						endif;
					elseif(substr($answers[$i],0,1)=='='):
						$tab_return['answers'][]=substr($answers[$i],1);
						$tab_return['points'][]=$points;				
						$tab_return['correct']=(count($tab_return['answers'])-1);
						$tab_return['typewiris'][]='multichoice';
					else:
						$tab_return['answers'][]=substr($answers[$i],1);
						$tab_return['points'][]=0;
						$tab_return['typewiris'][]='multichoice';
					endif;
				else:
					$tab_return['answers'][]=$answers[$i];
					$tab_return['points'][]=0;	
					$tab_return['typewiris'][]='multichoice';			
				endif;
			endfor;
			return $tab_return;
		endif;
	}

	protected function quick_sort($array)
	{
		$length = count($array);
		if($length <= 1):
			return $array;
		else:
			$pivot = $array[0];
			$left = $right = array();
			for($i = 1; $i < count($array); $i++):
				if($array[$i] < $pivot):
					$left[] = $array[$i];
				else:
					$right[] = $array[$i];
				endif;
			endfor;
			return array_merge($this->quick_sort($left), array($pivot), $this->quick_sort($right));
		endif;
	}
	
	protected function AutoWidthColumn($sheet,$FromColumn,$ToColumn,$MaxRow)
	{
		for($c=$FromColumn;$c<=$ToColumn;$c++):
			$width=0;
			for($r=1;$r<=$MaxRow;$r++):
				$sval=strlen($sheet->getCellByColumnAndRow($c,$r)->getCalculatedValue());
				if($sval > $width):
					$width=$sval;
				endif;
			endfor;
			$calculate=(1.025*$width)+5;
			$sheet->getColumnDimensionByColumn($c)->setWidth($calculate);
		endfor;
	}
	protected function SetBarWidth($number)
	{
		echo '<script>document.getElementById(\'nitroreportpdf_bar\').style.width = \''.$number.'%\';</script>';
		echo '<script>document.getElementById(\'nitroreportpdf_bar_text\').innerHTML = \''.$number.'%\';</script>';
	}
	protected function latexmlfunctions($type = null, $text = null)
	{
		global $CFG, $DB;
		if((empty($type))||(empty($text))||(!in_array($type,array('latex2image','latex2mathml','mathml2image','mathml2latex')))):
			return "@500";
		endif;
		$text_md5=md5($text);
		$download_count = $DB->count_records_sql('SELECT count(*) FROM {quiz_nitroreportpdf_latex_db} WHERE type="'.$type.'" AND ratio>0');
		if($download_count > 0):		
			$download = $DB->get_records_sql('SELECT * FROM {quiz_nitroreportpdf_latex_db} WHERE type="'.$type.'" AND ratio>0 ORDER BY ratio ASC');
			foreach($download AS $download):
				$ch = curl_init();
				curl_setopt($ch,CURLOPT_URL,'http://'.parse_url($download->url)['host']);
				curl_setopt($ch,CURLOPT_AUTOREFERER,true);
				curl_setopt($ch,CURLOPT_FOLLOWLOCATION,true); 
				curl_setopt($ch,CURLOPT_RETURNTRANSFER,true); 
				curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false); 
				curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,10);  
				curl_setopt($ch,CURLOPT_MAXREDIRS,10); 
				curl_setopt($ch,CURLOPT_TIMEOUT,10);  
				curl_exec($ch);
				if(!curl_errno($ch)):
					$info = curl_getinfo($ch);
					if($info['http_code'] != 200):
						curl_close($ch);
						return "@500";
						break;
					else:
						curl_close($ch);
						switch(strtoupper($download->typesender)):		
							case 'HTTP-GET':
								$ch = curl_init();
								curl_setopt($ch,CURLOPT_URL,$download->url.'?'.preg_replace('/#magic#/',$text,$download->options_url));
								curl_setopt($ch,CURLOPT_AUTOREFERER,true);
								curl_setopt($ch,CURLOPT_FOLLOWLOCATION,true); 
								curl_setopt($ch,CURLOPT_RETURNTRANSFER,true); 
								curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false); 
								curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,10);  
								curl_setopt($ch,CURLOPT_MAXREDIRS,10); 
								curl_setopt($ch,CURLOPT_TIMEOUT,120);  
								$req=curl_exec($ch);
								if(curl_errno($ch)):
									return "@500";
									break;
								else:
									if(in_array($download->format,array('GIF','JPG','PNG','SVG'))&&((empty($download->path))||($download->path == NULL))):
										file_put_contents($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$text_md5.'.'.strtolower($download->format),$req);
										return $CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$text_md5.'.'.strtolower($download->format);
									elseif(preg_match('/JSON/',$download->format)):
										$format=explode('-',$download->format);
										$format=$format[1];
										$store = new JsonStore($req);
										$res = $store->get('$'.$download->path, true);
										file_put_contents($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$text_md5.'.'.$format,$res[0]);
										if($format=="TEXT"):
											return $req;
										else:
											return $CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$text_md5.'.'.$format;
										endif;
									elseif($download->format=="TEXT"):
										return $req;
									endif;
								endif;
							break;
							case 'HTTP-POST':
								$ch = curl_init();
								curl_setopt($ch,CURLOPT_URL,$download->url);
								curl_setopt($ch,CURLOPT_AUTOREFERER,true);
								curl_setopt($ch,CURLOPT_FOLLOWLOCATION,true); 
								curl_setopt($ch,CURLOPT_RETURNTRANSFER,true); 
								curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false); 
								curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,10);  
								curl_setopt($ch,CURLOPT_MAXREDIRS,10); 
								curl_setopt($ch,CURLOPT_TIMEOUT,120);
								curl_setopt ($ch, CURLOPT_POST, 1);
								curl_setopt ($ch, CURLOPT_POSTFIELDS,preg_replace('/#magic#/',$text,$download->options_url));
								$req=curl_exec($ch);
								if(curl_errno($ch)):
									curl_close($ch);
									return "@500";
									break;
								else:
									curl_close($ch);
									if(in_array($download->format,array('GIF','JPG','PNG','SVG'))&&((empty($download->path))||($download->path == NULL))):
										file_put_contents($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$text_md5.'.'.strtolower($download->format),$req);
										return $CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$text_md5.'.'.strtolower($download->format);
									elseif(preg_match('/JSON/',$download->format)):
										$format=explode('-',$download->format);
										$format=$format[1];
										$store = new JsonStore($req);
										$res = $store->get('$'.$download->path, true);
										file_put_contents($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$text_md5.'.'.$format,$res[0]);
										if($format=="TEXT"):
											return $req;
										else:
											return $CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$text_md5.'.'.$format;
										endif;
									elseif($download->format=="TEXT"):
										return $req;
									endif;
								endif;
							break;
						endswitch;
						break;
					endif;
				endif;
				touch($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$text_md5.'.'.strtolower($download->format));
			endforeach;
		else:
			return "@500";
		endif;
	}	
/////
}
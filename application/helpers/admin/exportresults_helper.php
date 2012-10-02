<?php
/*
* LimeSurvey
* Copyright (C) 2007-2011 The LimeSurvey Project Team / Carsten Schmitz
* All rights reserved.
* License: GNU/GPL License v2 or later, see LICENSE.php
* LimeSurvey is free software. This version may have been modified pursuant
* to the GNU General Public License, and as distributed it includes or
* is derivative of works licensed under the GNU General Public License or
* other free or open source software licenses.
* See COPYRIGHT.php for copyright notices and details.
*
*/

/**
* A Survey object may be loaded from the database via the SurveyDao
* (which follows the Data Access Object pattern).  Data access is broken
* into two separate functions: the first loads the survey structure from
* the database, and the second loads responses from the database.  The
* data loading is structured in this way to provide for speedy access in
* the event that a survey's response table contains a large number of records.
* The responses can be loaded a user-defined number at a time for output
* without having to load the entire set of responses from the database.
*
* The Survey object contains methods to conveniently access data that it
* contains in an attempt to encapsulate some of the complexity of its internal
* format.
*
* Data formatting operations that may be specific to the data export routines
* are relegated to the Writer class hierarcy and work with the Survey object
* and FormattingOptions objects to provide proper style/content when exporting
* survey information.
*
* Some guess work has been done when deciding what might be specific to exports
* and what is not.  In general, anything that requires altering of data fields
* (abbreviating, concatenating, etc...) has been moved into the writers and
* anything that is a direct access call with no formatting logic is a part of
* the Survey object.
*
* - elameno
*/


class ExportSurveyResultsService
{
    /**
    * Root function for any export results action
    *
    * @param mixed $iSurveyId
    * @param mixed $sLanguageCode
    * @param FormattingOptions $oOptions
    * @param mixed $sOutputStyle  'display' or 'file'  Default: display (send to browser)
    */
    function exportSurvey($iSurveyId, $sLanguageCode, $sExportPlugin, FormattingOptions $oOptions)
    {
        //Do some input validation.
        if (empty($iSurveyId))
        {
            safeDie('A survey ID must be supplied.');
        }
        if (empty($sLanguageCode))
        {
            safeDie('A language code must be supplied.');
        }
        if (empty($oOptions))
        {
            safeDie('Formatting options must be supplied.');
        }
        if (empty($oOptions->selectedColumns))
        {
            safeDie('At least one column must be selected for export.');
        }
        
        //echo $oOptions->toString().PHP_EOL;
        $writer = null;

        $iSurveyId = sanitize_int($iSurveyId);
        if ($oOptions->output=='display')
        {
            header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
            header("Pragma: public");
        }

        switch ( $sExportPlugin ) {
            case "doc":
                $writer = new DocWriter();
                break;
            case "xls":
                    $writer = new ExcelWriter();
                break;
            case "pdf":
                Yii::import("application.libraries.admin.pdf", true);
                if ($oOptions->output=='return')
                {
                    $sRandomFileName=Yii::app()->getConfig("tempdir") . DIRECTORY_SEPARATOR . randomChars(40);
                    $writer = new PdfWriter($sRandomFileName);
                }
                else
                {
                    $writer = new PdfWriter();
                }
                break;
            case "csv":
            default:
                $writer = new CsvWriter();
                break;
        }

        $surveyDao = new SurveyDao();
        $survey = $surveyDao->loadSurveyById($iSurveyId);
        $writer->init($survey, $sLanguageCode, $oOptions);

        $iBatchSize=100; $iCurrentRecord=$oOptions->responseMinRecord-1;
        $bMoreRecords=true; $first=true;
        while ($bMoreRecords)
        {
            if($iBatchSize > (int)$oOptions->responseMaxRecord-$iCurrentRecord)
            {
               $iBatchSize=(int)$oOptions->responseMaxRecord-$iCurrentRecord;
        }
            $iExported= $surveyDao->loadSurveyResults($survey, $iBatchSize, $iCurrentRecord);
            $iCurrentRecord+=$iExported;
            $writer->write($survey, $sLanguageCode, $oOptions,$first);
            $first=false;
            $bMoreRecords=($iCurrentRecord < (int)$oOptions->responseMaxRecord);
        }

        $writer->close();
        if ($oOptions->output=='file')
        {
            return $writer->filename;
        }
    }
}

class FormattingOptions
{
    public $responseMinRecord;
    public $responseMaxRecord;

    /**
    * The columns that have been selected for output.  The values must be
    * in fieldMap format.
    *
    * @var array[]string
    */
    public $selectedColumns;

    /**
    * Acceptable values are:
    * "filter" = do not include incomplete answers
    * "incomplete" = only include incomplete answers
    * "show" = include ALL answers
    *
    * @var mixed
    */
    public $responseCompletionState;

    /**
    * Acceptable values are:
    * "abbreviated" = Abbreviated headings
    * "full" = Full headings
    * "code" = Question codes
    *
    * @var string
    */
    public $headingFormat;

    /**
    * Indicates whether to convert spaces in question headers to underscores.
    *
    * @var boolean
    */
    public $headerSpacesToUnderscores;

    /**
    * Valid values are:
    * "short" = Answer codes
    * "long" = Full answers
    *
    * @var string
    */
    public $answerFormat;

    /**
    * If $answerFormat is set to "short" then this indicates that 'Y' responses
    * should be converted to another value that is specified by $yValue.
    *
    * @var boolean
    */
    public $convertY;

    public $yValue;

    /**
    * If $answerFormat is set to "short" then this indicates that 'N' responses
    * should be converted to another value that is specified by $nValue.
    *
    * @var boolean
    */
    public $convertN;

    public $nValue;

    /**
    * Destination format - either 'display' (send to browser) or 'file' (send to file)
    * 
    * @var string
    */
    public $output;

    public function toString()
    {
        return $this->format.','.$this->headingFormat.','
        .$this->headerSpacesToUnderscores.','.$this->responseCompletionState
        .','.$this->responseMinRecord.','.$this->responseMaxRecord.','
        .$this->answerFormat.','.$this->convertY.','.$this->yValue.','
        .$this->convertN.','.$this->nValue.','
        .implode(',',$this->selectedColumns);
    }
}

class SurveyDao
{
    /**
    * Loads a survey from the database that has the given ID.  If no matching
    * survey is found then null is returned.  Note that no results are loaded
    * from this function call, only survey structure/definition.
    *
    * In the future it would be nice to load all languages from the db at
    * once and have the infrastructure be able to return responses based
    * on language codes.
    *
    * @param int $id
    * @return Survey
    */
    public function loadSurveyById($id)
    {
        $survey = new SurveyObj();
        $clang = Yii::app()->lang;

        $intId = sanitize_int($id);
        $survey->id = $intId;
        $lang = Survey::model()->findByPk($intId)->language;
        $clang = new limesurvey_lang($lang);

        $survey->fieldMap = createFieldMap($intId,false,false,getBaseLanguageFromSurveyID($intId));

        if (empty($intId))
        {
            //The id given to us is not an integer, croak.
            safeDie("An invalid survey ID was encountered: $sid");
        }


        //Load groups
        $sQuery = 'SELECT g.* FROM {{groups}} AS g '.
        'WHERE g.sid = '.$intId.' '.
        'ORDER BY g.group_order;';
        $recordSet = Yii::app()->db->createCommand($sQuery)->query()->readAll();
        $survey->groups = $recordSet;

        //Load questions
        $sQuery = 'SELECT q.* FROM {{questions}} AS q '.
        'JOIN {{groups}} AS g ON q.gid = g.gid '.
        'WHERE q.sid = '.$intId.' AND q.language = \''.$lang.'\' '.
        'ORDER BY g.group_order, q.question_order;';
        $survey->questions = Yii::app()->db->createCommand($sQuery)->query()->readAll();

        //Load answers
        $sQuery = 'SELECT DISTINCT a.* FROM {{answers}} AS a '.
        'JOIN {{questions}} AS q ON a.qid = q.qid '.
        'WHERE q.sid = '.$intId.' AND a.language = \''.$lang.'\' '.
        'ORDER BY a.qid, a.sortorder;';
        $survey->answers = Yii::app()->db->createCommand($sQuery)->query()->readAll();

        //Load tokens
        if (tableExists('{{tokens_' . $intId . '}}'))
        {
            $sQuery = 'SELECT t.* FROM {{tokens_' . $intId . '}} AS t;';
            $recordSet = Yii::app()->db->createCommand($sQuery)->query()->readAll();
            $survey->tokens = $recordSet;
        }
        else
        {
            $survey->tokens=array();
        }

        //Load language settings
        $sQuery = 'SELECT * FROM {{surveys_languagesettings}} WHERE surveyls_survey_id = '.$intId.';';
        $recordSet = Yii::app()->db->createCommand($sQuery)->query()->readAll();
        $survey->languageSettings = $recordSet;

        return $survey;
    }

    /**
    * Loads results for the survey into the $survey->responses array.  The
    * results  begin from $minRecord and end with $maxRecord.  Either none,
    * or both,  the $minRecord and $maxRecord variables must be provided.
    * If none are then all responses are loaded.
    *
    * @param Survey $survey
    * @param int $iOffset 
    * @param int $iLimit 
    */
    public function loadSurveyResults(SurveyObj $survey, $iLimit, $iOffset )
    {

        $oRecordSet = Yii::app()->db->createCommand()->select()->from('{{survey_' . $survey->id . '}}');
        if (tableExists('tokens_'.$survey->id))
        {
            $oRecordSet->join('{{tokens_' . $survey->id . '}}','{{tokens_' . $survey->id . '}}.token={{survey_' . $survey->id . '}}.token');
        }
        $survey->responses=$oRecordSet->order('id')->limit($iLimit, $iOffset)->query()->readAll();

        return count($survey->responses);
        }
        }

class SurveyObj
{
    /**
    * @var int
    */
    public $id;

    /**
    * Whether the survey is anonymous or not.
    * @var boolean
    */
    public $anonymous;

    /**
    * Answers, codes, and full text to the questions.
    * This is used in conjunction with the fieldMap to produce
    * some of the more verbose output in a survey export.
    * array[recordNo][columnName]
    *
    * @var array[int][string]mixed
    */
    public $answers;

    /**
    * The fieldMap as generated by createFieldMap(...).
    * @var array[]mixed
    */
    public $fieldMap;

    /**
    * The groups in the survey.
    *
    * @var array[int][string]mixed
    */
    public $groups;

    /**
    * The questions in the survey.
    *
    * @var array[int][string]mixed
    */
    public $questions;

    /**
    * The tokens in the survey.
    *
    * @var array[int][string]mixed
    */
    public $tokens;

    /**
    * Stores the responses to the survey in a two dimensional array form.
    * array[recordNo][fieldMapName]
    *
    * @var array[int][string]mixed
    */
    public $responses;

    /**
    *
    * @var array[int][string]mixed
    */
    public $languageSettings;

    /**
    * Returns question arrays ONLY for questions that are part of the
    * indicated group and are top level (i.e. no subquestions will be
    * returned).   If there are no then an empty array will be returned.
    * If $groupId is not set then all top level questions will be
    * returned regardless of the group they are a part of.
    */
    public function getQuestions($groupId = null)
    {
        $qs = array();
        foreach($this->questions as $q)
        {
            if ($q['parent_qid'] == 0)
            {
                if(empty($groupId) || $q['gid'] == $groupId)
                {
                    $qs[] = $q;
                }
            }
        }
        return $qs;
    }

    /**
    * Returns the question code/title for the question that matches the $fieldName.
    * False is returned if no matching question is found.
    * @param string $fieldName
    * @return string (or false)
    */
    public function getQuestionCode($fieldName)
    {
        $q = $this->fieldMap[$fieldName];
        if (isset($q->title))
        {
            return $q->title;
        }
        else
        {
            return false;
        }
    }

    public function getQuestionText($fieldName)
    {
        $question = $this->fieldMap[$fieldName];
        if ($question)
        {
            return $question['question'];
        }
        else
        {
            return false;
        }
    }


    /**
    * Returns all token records that have a token value that matches
    * the one given.  An empty array is returned if there are no
    * matching token records.
    *
    * @param mixed $token
    */
    public function getTokens($token)
    {
        $matchingTokens = array();

        foreach($this->tokens as $t)
        {
            if ($t['token'] == $token)
            {
                $matchingTokens[] = $t;
            }
        }

        return $matchingTokens;
    }

    /**
    * Returns an array containing all child question rows for the given parent
    * question ID.  If no children are found then an empty array is
    * returned.
    *
    * @param int $parentQuestionId
    * @return array[int]array[string]mixed
    */
    public function getSubQuestionArrays($parentQuestionId)
    {
        $results = array();
        foreach ($this->questions as $question)
        {
            if ($question['parent_qid'] == $parentQuestionId)
            {
                $results[$question['qid']] = $question;
            }
        }
        return $results;
    }

    /**
    * Returns an array of possible answers to the question.  If $scaleId is
    * specified then only answers that match the $scaleId value will be
    * returned. An empty array
    * may be returned by this function if answers are found that match the
    * questionId.
    *
    * @param int $questionId
    * @param int $scaleId
    * @return array[string]array[string]mixed (or false)
    */
    public function getAnswers($questionId, $scaleId = null)
    {
        $answers = array();
        foreach ($this->answers as $answer)
        {
            if (null == $scaleId && $answer['qid'] == $questionId)
            {
                $answers[$answer['code']] = $answer;
            }
            else if ($answer['qid'] == $questionId && $answer['scale_id'] == $scaleId)
                {
                    $answers[$answer['code']] = $answer;
                }
        }
        return $answers;
    }
}

class Translator
{
    //An associative array:  key = language code, value = translation library
    private $translations = array();

    //The following array stores field names that require pulling a value from the
    //internationalization layer. <fieldname> => <internationalization key>
    private $headerTranslationKeys = array(
    'id' => 'id',
    'lastname' => 'Last Name',
    'firstname' => 'First Name',
    'email' => 'Email Address',
    'token' => 'Token',
    'datestamp' => 'Date Last Action',
    'startdate' => 'Date Started',
    'submitdate' => 'Completed',
    //'completed' => 'Completed',
    'ipaddr' => 'IP-Address',
    'refurl' => 'Referring URL',
    'lastpage' => 'Last page seen',
    'startlanguage' => 'Start language'//,
    //'tid' => 'Token ID'
    );

    public function translate($key, $sLanguageCode)
    {
        return $this->getTranslationLibrary($sLanguageCode)->gT($key);
    }

    /**
    * Accepts a fieldName from a survey fieldMap and returns the translated value
    * for the fieldName in the survey's base language (if one exists).
    * If no translation exists for the provided column/fieldName then
    * false is returned.
    *
    * To add any columns/fieldNames to be processed by this function, simply add the
    * column/fieldName to the $headerTranslationKeys associative array.
    *
    * This provides a mechanism for determining of a column in a survey's data table
    * needs to be translated through the translation mechanism, or if its an actual
    * survey data column.
    *
    * @param string $column
    * @param string $sLanguageCode
    * @return string
    */
    public function translateHeading($column, $sLanguageCode)
    {
        $key = $this->getHeaderTranslationKey($column);
        //echo "Column: $column, Key: $key".PHP_EOL;
        if ($key)
        {
            return $this->translate($key, $sLanguageCode);
        }
        else
        {
            return false;
        }
    }

    protected function getTranslationLibrary($sLanguageCode)
    {
        $library = null;
        if (!array_key_exists($sLanguageCode, $this->translations))
        {
            $library = new limesurvey_lang($sLanguageCode);
            $this->translations[$sLanguageCode] = $library;
        }
        else
        {
            $library = $this->translations[$sLanguageCode];
        }
        return $library;
    }

    /**
    * Finds the header translation key for the column passed in.  If no key is
    * found then false is returned.
    *
    * @param string $key
    * @return string (or false if no match is found)
    */
    public function getHeaderTranslationKey($column)
    {
        if (isset($this->headerTranslationKeys[$column]))
        {
            return $this->headerTranslationKeys[$column];
        }
        else
        {
            return false;
        }
    }
}

interface IWriter
{
    /**
    * Writes the survey and all the responses it contains to the output
    * using the options specified in FormattingOptions.
    *
    * See Survey and SurveyDao objects for information on loading a survey
    * and results from the database.
    *
    * @param Survey $survey
    * @param string $sLanguageCode
    * @param FormattingOptions $oOptions
    */
    public function write(SurveyObj $survey, $sLanguageCode, FormattingOptions $oOptions);
    public function close();
}

/**
* Contains functions and properties that are common to all writers.
* All extending classes must implement the internalWrite(...) method and
* have access to functionality as described below:
*
* TODO Write more docs here
*/
abstract class Writer implements IWriter
{
    public $sLanguageCode;
    public $translator;

    public function translate($key, $sLanguageCode)
    {
        return $this->translator->translate($key, $sLanguageCode);
    }

    protected function translateHeading($column, $sLanguageCode)
    {
        if (substr($column,0,10)=='attribute_') return $column;
        return $this->translator->translateHeading($column, $sLanguageCode);
    }

    /**
    * An initialization method that implementing classes can override to gain access
    * to any information about the survey, language, or formatting options they
    * may need for setup.
    *
    * @param Survey $survey
    * @param mixed $sLanguageCode
    * @param FormattingOptions $oOptions
    */
    public function init(SurveyObj $survey, $sLanguageCode, FormattingOptions $oOptions)
    {
        $this->languageCode = $sLanguageCode;
        $this->translator = new Translator();
    }

    
    /**
    * Returns true if, given the $oOptions, the response should be included in the
    * output, and false if otherwise.
    *
    * @param mixed $response
    * @param FormattingOptions $oOptions
    * @return boolean
    */
    protected function shouldOutputResponse(array $response, FormattingOptions $oOptions)
    {
        switch ($oOptions->responseCompletionState)
        {
            default:
            case 'show':
                return true;
                break;

            case 'incomplete':
                return !isset($response['submitdate']);
                break;

            case 'filter':
                return isset($response['submitdate']);
                break;

        }
    }

    /**
    * Returns an abbreviated heading for the survey's question that matches
    * the $fieldName parameter (or false if a match is not found).
    *
    * @param Survey $survey
    * @param string $fieldName
    * @return string
    */
    public function getAbbreviatedHeading(SurveyObj $survey, $q)
    {
        $question = $survey->fieldMap[$fieldName];
        if ($question)
        {
            $heading = $question['question'];
            $heading = $this->stripTagsFull($heading);
            $heading = mb_substr($heading, 0, 15).'.. ';
            if (isset($q->aid))
            {
                $heading .= '['.$q->aid.']';
            }
            return $heading;
        }
        return false;
    }

    /**
    * Returns a full heading for the question that matches the $fieldName.
    * False is returned if no matching question is found.
    *
    * @param Survey $survey
    * @param FormattingOptions $oOptions
    * @param string $fieldName
    * @return string (or false)
    */
    public function getFullHeading(SurveyObj $survey, $q)
    {
        $question = $survey->fieldMap[$q->fieldname];
        $heading = $question['question'];
        $heading = $this->stripTagsFull($heading);
        $heading.= $q->getFieldSubHeading($survey, $this, false);
        return $heading;
    }

    public function getCodeHeading(SurveyObj $survey, $q)
    {
        $question = $survey->fieldMap[$q->fieldname];
        $heading = $question['title'];
        $heading = $this->stripTagsFull($heading);
        $heading.= $q->getFieldSubHeading($survey, $this, true);
        return $heading;
    }

    public function getOtherSubHeading()
    {
        return '['.$this->translate('Other', $this->languageCode).']';
    }

    public function getCommentSubHeading()
    {
        return '- comment';
    }

    /**
    * This method is made final to prevent extending code from circumventing the
    * initialization process that must take place prior to any of the translation
    * infrastructure to work.
    *
    * The inialization process is dependent upon the survey being passed into the
    * write function and so must be performed when the method is called and not
    * prior to (such as in a constructor).
    *
    * All extending classes must implement the internalWrite function which is
    * the code that is called after all initialization is completed.
    *
    * @param Survey $survey
    * @param string $sLanguageCode
    * @param FormattingOptions $oOptions
    * @param boolean $bOutputHeaders Set if header should be given back
    */
    final public function write(SurveyObj $survey, $sLanguageCode, FormattingOptions $oOptions, $bOutputHeaders=true)
    {

        //Output the survey.
        $headers = array();
        if ($bOutputHeaders)
        {
            
        foreach ($oOptions->selectedColumns as $column)
        {
            //Output the header.
            $value = $this->translateHeading($column, $sLanguageCode);
            if($value===false)
            {
                //This branch may be reached erroneously if columns are added to the LimeSurvey product
                //but are not updated in the Writer->headerTranslationKeys array.  We should trap for this
                //condition and do a safeDie.
                //FIXME fix the above condition

                //Survey question field, $column value is a field name from the getFieldMap function.

                $q = $survey->fieldMap[$column];
                switch ($oOptions->headingFormat)
                {
                    case 'abbreviated':
                        $value = $this->getAbbreviatedHeading($survey, $q);
                        break;
                    case 'full':
                        $value = $this->getFullHeading($survey, $q);
                        break;
                    default:
                    case 'code':
                        $value = $this->getCodeHeading($survey, $q);
                        break;
                }
            }
            if ($oOptions->headerSpacesToUnderscores)
            {
                $value = str_replace(' ', '_', $value);
            }

            //$this->output.=$this->csvEscape($value).$this->separator;
            $headers[] = $value;
        }
        }

        //Output the results.
        foreach($survey->responses as $response)
        {
            $elementArray = array();

            //If we shouldn't be outputting this response then we should skip the rest
            //of the loop and continue onto the next value.
            if (!$this->shouldOutputResponse($response, $oOptions))
            {
                continue;
            }

            foreach ($oOptions->selectedColumns as $column)
            {
                $value = $response[$column];
                $q = $survey->fieldMap[$column];

                if (!is_a($q, 'QuestionModule'))
                {
                    $elementArray[] = $this->stripTagsFull($value);
                    continue;
                }

                switch ($oOptions->answerFormat) {
                    case 'long':
                        $elementArray[] = $elementArray[] = $q->transformResponseValue($this, $q->getFullAnswer($value, $this, $survey), $oOptions);
                        break;
                    default:
                    case 'short':
                        $elementArray[] = $q->transformResponseValue($this, $value, $oOptions);
                        break;
                }
            }

            $this->outputRecord($headers, $elementArray, $oOptions);
        }
    }

    public function stripTagsFull($string)
    {
        $string=str_replace('-oth-','',$string);
        return flattenText($string,false,true,'UTF-8',false);
    }

    /**
    * This method will be called once for every row of data that needs to be
    * output.
    *
    * Implementations must use the data from these method calls to construct
    * proper output for their output type and the given FormattingOptions.
    *
    * @param array $headers
    * @param array $values
    * @param FormattingOptions $oOptions
    */
    abstract protected function outputRecord($headers, $values, FormattingOptions $oOptions);
}

class CsvWriter extends Writer
{
    private $output;
    private $separator;
    private $hasOutputHeader;

    function __construct()
    {
        $this->output = '';
        $this->separator = ',';
        $this->hasOutputHeader = false;
    }

    public function init(SurveyObj $survey, $sLanguageCode, FormattingOptions $oOptions)
    {
        parent::init($survey, $sLanguageCode, $oOptions);
        if ($oOptions->output=='display')
            {
                header("Content-Disposition: attachment; filename=results-survey".$survey->id.".csv");
                header("Content-type: text/comma-separated-values; charset=UTF-8");
            }

    }
    
    protected function outputRecord($headers, $values, FormattingOptions $oOptions)
    {
        if(!$this->hasOutputHeader)
        {
            $index = 0;
            foreach ($headers as $header)
            {
                $headers[$index] = $this->csvEscape($header);
                $index++;
            }

            //Output the header...once and only once.
            $sRecord=implode($this->separator, $headers);
            if ($oOptions->output='display')
            {
                echo $sRecord; 
            }

            $this->hasOutputHeader = true;
        }
        //Output the values.
        $index = 0;
        foreach ($values as $value)
        {
            $values[$index] = $this->csvEscape($value);
            $index++;
        }
        $sRecord=PHP_EOL.implode($this->separator, $values);
        if ($oOptions->output='display')
        {
            echo $sRecord; 
    }
    }

    public function close()
    {
        return $this->output;
    }

    /**
    * Returns the value with all necessary escaping needed to place it into a CSV string.
    *
    * @param string $value
    * @return string
    */
    protected function csvEscape($value)
    {
        return CSVEscape($value);
    }
}

class DocWriter extends Writer
{
    private $output;
    private $separator;
    private $isBeginning;

    public function __construct()
    {
        $this->separator = "\t";
        $this->output = '';
        $this->isBeginning = true;
    }

    public function init(SurveyObj $survey, $sLanguageCode, FormattingOptions $oOptions)
    {
        parent::init($survey, $sLanguageCode, $oOptions);

        if ($oOptions->output=='display')
        {
            header("Content-Disposition: attachment; filename=results-survey".$survey->id.".doc");
            header("Content-type: application/vnd.ms-word");
        }
        
        
        $sOutput = '<style>
        table {
        border-collapse:collapse;
        }
        td, th {
        border:solid black 1.0pt;
        }
        th {
        background: #c0c0c0;
        }
        </style>';
        if ($oOptions->output=='display'){
            echo  $sOutput;
    }
    }

    /**
    * @param array $headers
    * @param array $values
    * @param FormattingOptions $oOptions
    */
    protected function outputRecord($headers, $values, FormattingOptions $oOptions)
    {
        if ($oOptions->answerFormat == 'short')
        {
            //No headers at all, only output values.
            $this->output .= implode($this->separator, $values).PHP_EOL;
            if ($oOptions->output=='display'){
                echo  $this->output;
                $this->output='';
        }
        }
        elseif ($oOptions->answerFormat == 'long')
        {
            //Output each record, one per page, with a header preceding every value.
            if ($this->isBeginning)
            {
                $this->isBeginning = false;
            }
            else
            {
                $this->output .= "<br clear='all' style='page-break-before:always'>";
            }
            $this->output .= "<table><tr><th colspan='2'>".$this->translate('New Record', $this->languageCode)."</td></tr>".PHP_EOL;

            $counter = 0;
            foreach ($headers as $header)
            {
                $this->output .= "<tr><td>".$header."</td><td>".$values[$counter]."</td></tr>".PHP_EOL;
                $counter++;
            }
            $this->output .= "</table>".PHP_EOL;
            if ($oOptions->output=='display'){
                echo  $this->output;
                $this->output='';
        }
            
        }
        else
        {
            safeDie('An invalid answer format was selected.  Only \'short\' and \'long\' are valid.');
        }
    }

    public function close()
    {
    }
}

/**
* Exports results in Microsoft Excel format.  By default the Writer sends
* HTTP headers and the file contents via HTTP.  For testing purposes a
* file name can be  to the constructor which will cause the ExcelWriter to
* output to a file.
*/
class ExcelWriter extends Writer
{
    private $workbook;
    private $currentSheet;
    private $separator;
    private $hasOutputHeader;
    private $rowCounter;

    //Indicates if the Writer is outputting to a file rather than sending via HTTP.
    private $fileName;
    private $outputToFile;

    /**
    * The presence of a filename will cause the writer to output to
    * a file rather than send.
    *
    * @param string $filename
    * @return ExcelWriter
    */
    public function __construct($filename = null)
    {
        Yii::import('application.libraries.admin.pear.Spreadsheet.Excel.Xlswriter', true);
        $this->separator = '~|';
        $this->hasOutputHeader = false;
        $this->rowCounter = 1;
    }

    public function init(SurveyObj $survey, $sLanguageCode, FormattingOptions $oOptions)
        {
        parent::init($survey, $sLanguageCode, $oOptions);
                            $sRandomFileName=Yii::app()->getConfig("tempdir"). DIRECTORY_SEPARATOR . randomChars(40);

        if ($oOptions->output=='file')
        {
            $oOptions['filename']=Yii::app()->getConfig("tempdir"). DIRECTORY_SEPARATOR . randomChars(40);            
            $this->workbook = new xlswriter($oOptions['filename']);
        }
        else
        {
            $this->workbook = new xlswriter;
        }

        $this->workbook->send('results-survey'.$survey->id.'.xls');
        $worksheetName = $survey->languageSettings[0]['surveyls_title'];
        $worksheetName=substr(str_replace(array('*', ':', '/', '\\', '?', '[', ']'),array(' '),$worksheetName),0,31); // Remove invalid characters

        $this->workbook->setVersion(8);
        if (!empty($tempdir)) {
            $this->$workbook->setTempDir($tempdir);
        }
        $sheet =$this->workbook->addWorksheet($worksheetName); // do not translate/change this - the library does not support any special chars in sheet name
        $sheet->setInputEncoding('utf-8');
        $this->currentSheet = $sheet;
    }

    protected function outputRecord($headers, $values, FormattingOptions $oOptions)
    {
        if (!$this->hasOutputHeader)
        {
            $columnCounter = 0;
            foreach ($headers as $header)
            {
                $this->currentSheet->write($this->rowCounter,$columnCounter,str_replace('?', '-', $this->excelEscape($header)));
                $columnCounter++;
            }
            $this->hasOutputHeader = true;
            $this->rowCounter++;
        }
        $columnCounter = 0;
        foreach ($values as $value)
        {
            $this->currentSheet->write($this->rowCounter, $columnCounter, $this->excelEscape($value));
            $columnCounter++;
        }
        $this->rowCounter++;
    }

    private function excelEscape($value)
    {
        if (substr($value, 0, 1) == '=')
        {
            $value = '"'.$value.'"';
        }
        return $value;
    }

    public function close()
    {
        $this->workbook->close();
        return $this->workbook;
    }
}

class PdfWriter extends Writer
{
    private $pdf;
    private $separator;
    private $rowCounter;
    private $pdfDestination;
    private $fileName;
    private $surveyName;

    public function __construct($filename = null)
    {
        if (!empty($filename))
        {
            $this->pdfDestination = 'F';
            $this->fileName = $filename;
        }
        else
        {
            $this->pdfDestination = 'D';
        }

        //The $pdforientation, $pdfDefaultFont, and $pdfFontSize values
        //come from the Lime Survey config files.

        global $pdforientation, $pdfdefaultfont, $pdffontsize;

        Yii::import('application.libraries.admin.pdf', true);
        $this->pdf = new PDF($pdforientation,'mm','A4');
        $this->pdf->SetFont($pdfdefaultfont, '', $pdffontsize);
        $this->pdf->AddPage();
        $this->pdf->intopdf("PDF export ".date("Y.m.d-H:i", time()));


        $this->separator="\t";

        $this->rowCounter = 0;
    }

    public function init(SurveyObj $survey, $sLanguageCode, FormattingOptions $oOptions)
    {
        parent::init($survey, $sLanguageCode, $oOptions);
        $this->surveyName = $survey->languageSettings[0]['surveyls_title'];
        $this->pdf->titleintopdf($this->surveyName, $survey->languageSettings[0]['surveyls_description']);
    }

    public function outputRecord($headers, $values, FormattingOptions $oOptions)
    {
        $this->rowCounter++;
        if ($oOptions->answerFormat == 'short')
        {
            $pdfstring = '';
            $this->pdf->titleintopdf($this->translate('New Record', $this->languageCode));
            foreach ($values as $value)
            {
                $pdfstring .= $value.' | ';
            }
            $this->pdf->intopdf($pdfstring);
        }
        elseif ($oOptions->answerFormat == 'long')
        {
            if ($this->rowCounter != 1)
            {
                $this->pdf->AddPage();
            }
            $this->pdf->Cell(0, 10, $this->translate('NEW RECORD', $this->languageCode).' '.$this->rowCounter, 1, 1);

            $columnCounter = 0;
            foreach($headers as $header)
            {
                $this->pdf->intopdf($header);
                $this->pdf->intopdf($this->stripTagsFull($values[$columnCounter]));
                $columnCounter++;
            }
        }
        else
        {
            safeDie('An invalid answer format was encountered: '.$oOptions->answerFormat);
        }

    }

    public function close()
    {
        if ($this->pdfDestination == 'F')
        {
            //Save to file on filesystem.
            $filename = $this->fileName;
        }
        else
        {
            //Presuming this else branch is a send to client via HTTP.
            $filename = $this->translate($this->surveyName, $this->languageCode).'.pdf';
        }
        $this->pdf->Output($filename, $this->pdfDestination);
    }
}

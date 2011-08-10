<?php 

require_once 'InstructionParser.php';

class MantisBeanstalk
{
	/** 
	 * @var MantisConnector 
	 */
	protected $mantisClient;
	
	/** 
	 * @var InstructionParser 
	 */
	protected $parser;
	
	/**
	 * simple assoc array that maps project names from beanstalk to mantis projectIds
	 * 
	 * @var array ('projectname' => 14) 
	 */
	protected $projectMapping;
	
	/**
	 * The data contained in the payload
	 * 
	 * See https://gitorious.org/gitorious/pages/WebHooks 
	 * for format
	 * 
	 * @var stdClass
	 */
	protected $data;
	
	protected $logData = array();

	/**
	 * Path to log directory
	 * 
	 * @var string
	 */
	protected $logPath;
	
	/**
	 * the mantis project id
	 * 
	 * @var string
	 */
	protected $projectId;
	
	public function __construct()
	{
		$this->parser = new InstructionParser();
	}
	
	public function run()
	{
		$this->logData[] = print_r($_REQUEST, true);
		
		$e = null;
		try {
			$this->execute();
		} catch (Exception $e) {
			$logData[] = $e->getMessage();
			$logData[] = $e->getTraceAsString();
		}
		
		if ($this->logPath)	$this->writeLog();
	}
	
	protected function writeLog()
	{
	  $content = implode("\n\n", $this->logData);
	  
		if (!is_dir($this->logPath) || !is_writable($this->logPath))
		{
			throw new Exception('Specified log path is not writable or does not exist.');
		}

		$i = count(scandir($this->logPath)) -1;
		$filename = "log_{$i}.log";
		
		$path = $this->getLogPath() .  $filename;
		
   	$flag = file_put_contents($path, $content);
	
    if ($flag === false)
    {
    	throw new Exception('Could not write log file.');
    }
	}
	
	public function execute()
	{
		if (!isset($_REQUEST['payload']))
		{
			throw new Exception('Could not find Gitorious webhook data in POST.');
		}
		
		$data = $_REQUEST['payload'];
		
		$data = json_decode($data);
		$data = get_object_vars($data);
		
		if (!$data || !$this->verifyData($data))
		{
			throw new Exception('Could not verify json data.');
		}
		
		$this->data = $data;
		
		$this->logData[] = 'Trying to parse ' . count($data['commits']) . ' commits.';
		
		foreach ($data['commits'] as $commit)
		{
			if (is_object($commit)) $commit = get_object_vars($commit);
			
			$this->processCommit($commit);
		}
		
		$this->logData[] = 'Processed all commits.';
	}
	
	public function processCommit(array $data)
	{
		$instructions = $this->parser->parse($data['message'], $data);		
    
		foreach ($instructions as $instruction)
		{
			$this->processInstruction($instruction, $data);
		}
	}
	
	public function processInstruction(Instruction $instruction, $data)
	{
	  $this->logData[] = 'Processing instruction for issue #' . $instruction->issueId;
	  
		/** @var IssueData */
		$issue = $this->mantisClient->mc_issue_get($instruction->issueId);
		
		if (!$issue)
		{
			throw new Exception('Issue with id "' . $instruction->issueId . '" not found!');
		}
		
		$projectId = $issue->project->id;

		$user = $this->determineUser($data, $projectId); 
		
		if (!$user) throw new Exception('User could not be found.');
		
		$this->logData[] = 'Found user ...';

		if ($assignTo = $instruction->assignTo)
		{
			$handler = $this->mantisClient->getUserBy('name', $projectId, $assignTo);
			if ($handler)
			{
				$issue->handler = $handler;
			}
		}
		
		if ($status = $instruction->getAsObjectRef('status'))
		{
			$issue->status = $status;
		}
		if ($priority = $instruction->getAsObjectRef('priority'))
		{
			$issue->priority = $priority;
		}
		if ($severity = $instruction->getAsObjectRef('severity'))
		{
			$issue->severity = $severity;
		}
		if ($resolution = $instruction->getAsObjectRef('resolution'))
		{
			$issue->resolution = $resolution;
		}
		
		// add new note to issue
		$noteText = $instruction->note ? $instruction->note : '';
		$noteText .= $this->getCommitInfoMessage($data);
			
		$note = new IssueNoteData();
		$note->text = $noteText;
		$note->reporter = $user;
			
		$this->mantisClient->mc_issue_note_add($instruction->issueId, $note);
		
		$this->logData[] = 'Trying to update issue...';

		$result = $this->mantisClient->mc_issue_update($instruction->issueId, $issue);
		
		$this->logData[] = print_r($issue, true);
		
		if (!$result)
		{
		  throw new Exception('Could not update Issue #' . $instruction->issueId);
		}
		
		$this->logData[] = 'Issue updated successfully';
	}
	
	protected function getCommitInfoMessage($data)
	{
	  $message = PHP_EOL . PHP_EOL . str_repeat('-', 100) . PHP_EOL;
	  
	  $message .= 'Repository: ' . $this->data['repository']->name . ' <' . $this->data['repository']->url . '>' . PHP_EOL;
	  $author = $data['author'];
		$message .= sprintf(
		  'Author: %s <%s>', 
		  isset($author->name) ? $author->name : '', 
		  isset($author->email) ? $author->email : ''
		) . PHP_EOL;
		
		
		$message .= 'Revision: ' . $data['id'] .  ' <' . $data['url'] .  '>' . PHP_EOL;
		$message .= 'Committed At: ' . $data['committed_at'] . PHP_EOL;
		$message .= 'Pushed At: ' . $this->data['pushed_at'] . PHP_EOL;
		
		return $message;
	}
	
	protected function determineUser($commit, $projectId)
	{
		$email = $commit['author']->email;
		$name = $commit['author']->name;
		
		$user = $this->mantisClient->getUserByEmail($projectId, $email);
		if (!$user) $user = $this->mantisClient->getUserBy('name', $projectId, $name);
		
		return $user;
	}
	
	protected function verifyData(array $data)
	{
    return (isset($data['commits']) && is_array($data['commits']));
	}
	
	public function setMantisClient($mantisClient)
	{
		$this->mantisClient = $mantisClient;
		return $this;
	}
	
	public function getMantisClient($mantisClient)
	{
		return $this->mantisClient;
	}
	
	public function setInstructionParser($instructionParser)
	{
		$this->instructionParser = $instructionParser;
		return $this;
	}
	
	public function getInstructionParser($instructionParser)
	{
		return $this->instructionParser;
	}
	
	public function setProjectId($projectId)
	{
		$this->projectId = $projectId;
		return $this;
	}
	
	public function getProjectId($projectId)
	{
		return $this->projectId;
	}
	
  /**
   * 
   * @param string $logPath
   */
  public function setLogPath($logPath)
  {
    $this->logPath = $logPath;
    return $this;
  }
  
  /**
   * @return MantisConnect $client
   */
  public function getLogPath()
  {
  	if (substr($this->logPath, -1) !== '/') $this->logPath .= '/';
    return $this->logPath;
  }
  
  public function setProjectMapping(array $map)
  {
  	$this->projectMapping = $map;
  }
}

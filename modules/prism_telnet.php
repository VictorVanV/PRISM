<?php

define('TELNET_NOT_LOGGED_IN', 0);
define('TELNET_ASKED_USERNAME', 1);
define('TELNET_ASKED_PASSWORD', 2);
define('TELNET_LOGGED_IN', 3);

class TelnetHandler extends SectionHandler
{
	private $telnetSock		= null;
	private $clients		= array();
	private $numClients		= 0;
	
	private $telnetVars		= array();
	
	public function __construct()
	{
		$this->iniFile = 'telnet.ini';
	}

	public function __destruct()
	{
		$this->close(true);
	}
	
	public function initialise()
	{
		global $PRISM;
		
		$this->telnetVars = array
		(
			'ip' => '', 
			'port' => 0,
		);

		if ($this->loadIniFile($this->telnetVars, false))
		{
			if ($PRISM->config->cvars['debugMode'] & PRISM_DEBUG_CORE)
				console('Loaded '.$this->iniFile);
		}
		else
		{
			# We ask the client to manually input the connection details here.
			require_once(ROOTPATH . '/modules/prism_interactive.php');
			Interactive::queryTelnet($this->telnetVars);
			
			# Then build a telnet.ini file based on these details provided.
			$extraInfo = <<<ININOTES
;
; Telnet listen details (for remote console access).
; 0.0.0.0 (default) will bind the socket to all available network interfaces.
; To limit the bind to one interface only, you can enter its IP address here.
; If you do not want to use the telnet feature, you can comment or remove the 
; lines, or enter "" and 0 for the ip and port.
;

ININOTES;
			if ($this->createIniFile('Telnet Configuration (remote console)', array('telnet' => &$this->telnetVars), $extraInfo))
				console('Generated config/'.$this->iniFile);
		}
		
		// Setup telnet socket to listen on
		if (!$this->setupListenSocket())
			return false;
		
		return true;
	}

	private function setupListenSocket()
	{
		$this->close(false);
		
		if ($this->telnetVars['ip'] != '' && $this->telnetVars['port'] > 0)
		{
			$this->telnetSock = @stream_socket_server('tcp://'.$this->telnetVars['ip'].':'.$this->telnetVars['port'], $errNo, $errStr);
			if (!is_resource($this->telnetSock) || $this->telnetSock === FALSE || $errNo)
			{
				console('Error opening telnet socket : '.$errStr.' ('.$errNo.')');
				return false;
			}
			else
			{
				console('Listening for telnet input on '.$this->telnetVars['ip'].':'.$this->telnetVars['port']);
			}
		}
		return true;
	}

	private function close($all)
	{
		if (is_resource($this->telnetSock))
			fclose($this->telnetSock);
		
		if (!$all)
			return;
		
		for ($k=0; $k<$this->numClients; $k++)
		{
			array_splice($this->clients, $k, 1);
			$k--;
			$this->numClients--;
		}
	}

	public function getSelectableSockets(array &$sockReads, array &$sockWrites)
	{
		// Add http sockets to sockReads
		if (is_resource($this->telnetSock))
			$sockReads[] = $this->telnetSock;

		for ($k=0; $k<$this->numClients; $k++)
		{
			if (is_resource($this->clients[$k]->getSocket()))
			{
				$sockReads[] = $this->clients[$k]->getSocket();
				
				// If write buffer was full, we must check to see when we can write again
				if ($this->clients[$k]->getSendQLen() > 0)
					$sockWrites[] = $this->clients[$k]->getSocket();
			}
		}
	}

	public function checkTraffic(array &$sockReads, array &$sockWrites)
	{
		$activity = 0;

		// telnetSock input (incoming telnet connection)
		if (in_array($this->telnetSock, $sockReads))
		{
			$activity++;
			
			// Accept the new connection
			$peerInfo = '';
			$sock = @stream_socket_accept ($this->telnetSock, NULL, $peerInfo);
			if (is_resource($sock))
			{
				//stream_set_blocking ($sock, 0);
				
				// Add new connection to clients array
				$exp = explode(':', $peerInfo);
				$this->clients[] = new TelnetClient($sock, $exp[0], $exp[1]);
				$this->numClients++;
				console('Telnet Client '.$exp[0].':'.$exp[1].' connected.');
			}
			unset ($sock);
		}
		
		// telnet clients input
		for ($k=0; $k<$this->numClients; $k++) {
			// Recover from a full write buffer?
			if ($this->clients[$k]->getSendQLen() > 0 &&
				in_array($this->clients[$k]->getSocket(), $sockWrites))
			{
				$activity++;
				
				// Flush the sendQ (bit by bit, not all at once - that could block the whole app)
				if ($this->clients[$k]->getSendQLen() > 0)
					$this->clients[$k]->flushSendQ();
			}
			
			// Did we receive something from a httpClient?
			if (!in_array($this->clients[$k]->getSocket(), $sockReads))
				continue;

			$activity++;
			
			$data = $this->clients[$k]->read($data);
			
			// Did the client hang up?
			if ($data == '')
			{
				console('Closed telnet client (client initiated) '.$this->clients[$k]->getRemoteIP().':'.$this->clients[$k]->getRemotePort());
				array_splice ($this->clients, $k, 1);
				$k--;
				$this->numClients--;
				continue;
			}

			$this->clients[$k]->addInputToBuffer($data);

			// Handle login / input
			$result = $this->clients[$k]->processInput();
			if ($result === false)
			{
				if ($this->clients[$k]->getMustClose())
				{
					console('Closed telnet client (client ctrl-c) '.$this->clients[$k]->getRemoteIP().':'.$this->clients[$k]->getRemotePort());
					array_splice ($this->clients, $k, 1);
					$k--;
					$this->numClients--;
				}
			}
		}
		
		return $activity;
	}
}

// IAC ACTION OPTION (3 bytes)
define('TELNET_OPT_BINARY',			chr(0x00));	// Binary (RCF 856)
define('TELNET_OPT_ECHO',			chr(0x01));	// (server) Echo (RFC 857)
define('TELNET_OPT_SGA',			chr(0x03));	// Suppres Go Ahead (RFC 858)
define('TELNET_OPT_TTYPE',			chr(0x18));	// Terminal Type (RFC 1091)
define('TELNET_OPT_NAWS',			chr(0x1F));	// Window Size (RFC 1073)
define('TELNET_OPT_TOGGLE_FLOW_CONTROL', chr(0x21));	// flow control (RFC 1372)
define('TELNET_OPT_LINEMODE',		chr(0x22));	// Linemode (RFC 1184)
define('TELNET_OPT_NOP',			chr(0xF1));	// No Operation.

// IAC OPTION (2 bytes)
define('TELNET_OPT_EOF',			chr(0xEC));
define('TELNET_OPT_SUSP',			chr(0xED));
define('TELNET_OPT_ABORT',			chr(0xEE));
define('TELNET_OPT_DM',				chr(0xF2));	// Indicates the position of a Synch event within the data stream. This should always be accompanied by a TCP urgent notification.
define('TELNET_OPT_BRK',			chr(0xF3));	// Break. Indicates that the �break� or �attention� key was hit.
define('TELNET_OPT_IP',				chr(0xF4));	// suspend/abort process.
define('TELNET_OPT_AO',				chr(0xF5));	// process can complete, but send no more output to users terminal.
define('TELNET_OPT_AYT',			chr(0xF6));	// check to see if system is still running.
define('TELNET_OPT_EC',				chr(0xF7));	// delete last character sent typically used to edit keyboard input.
define('TELNET_OPT_EL',				chr(0xF8));	// delete all input in current line.
define('TELNET_OPT_GA',				chr(0xF9));	// Used, under certain circumstances, to tell the other end that it can transmit.

// Suboptions Begin and End (variable byte length options with suboptions)
define('TELNET_OPT_SB',				chr(0xFA));	// Indicates that what follows is subnegotiation of the indicated option.
define('TELNET_OPT_SE',				chr(0xF0));	// End of subnegotiation parameters.

// ACTION bytes
define('TELNET_ACTION_WILL',		chr(0xFB));	// Indicates the desire to begin performing, or confirmation that you are now performing, the indicated option.
define('TELNET_ACTION_WONT',		chr(0xFC));	// Indicates the refusal to perform, or continue performing, the indicated option.
define('TELNET_ACTION_DO',			chr(0xFD));	// Indicates the request that the other party perform, or confirmation that you are expecting theother party to perform, the indicated option.
define('TELNET_ACTION_DONT',		chr(0xFE));	// Indicates the demand that the other party stop performing, or confirmation that you are no longer expecting the other party to perform, the indicated option.

// Command escape char
define('TELNET_IAC',				chr(0xFF));	// Interpret as command (commands begin with this value)

// Linemode sub options
define('LINEMODE_MODE',				chr(0x01));
define('LINEMODE_FORWARDMASK',		chr(0x02));
define('LINEMODE_SLC',				chr(0x03));	// Set Local Characters

// Linemode mode sub option values
define('LINEMODE_MODE_EDIT',		chr(0x01));
define('LINEMODE_MODE_TRAPSIG',		chr(0x02));
define('LINEMODE_MODE_MODE_ACK',	chr(0x04));
define('LINEMODE_MODE_SOFT_TAB',	chr(0x08));
define('LINEMODE_MODE_LIT_ECHO',	chr(0x10));

// Linemode Set Local Characters sub option values
define('LINEMODE_SLC_SYNCH',		chr(1));
define('LINEMODE_SLC_BRK',			chr(2));
define('LINEMODE_SLC_IP',			chr(3));
define('LINEMODE_SLC_AO',			chr(4));
define('LINEMODE_SLC_AYT',			chr(5));
define('LINEMODE_SLC_EOR',			chr(6));
define('LINEMODE_SLC_ABORT',		chr(7));
define('LINEMODE_SLC_EOF',			chr(8));
define('LINEMODE_SLC_SUSP',			chr(9));
define('LINEMODE_SLC_EC',			chr(10));
define('LINEMODE_SLC_EL',			chr(11));
define('LINEMODE_SLC_EW',			chr(12));
define('LINEMODE_SLC_RP',			chr(13));
define('LINEMODE_SLC_LNEXT',		chr(14));
define('LINEMODE_SLC_XON',			chr(15));
define('LINEMODE_SLC_XOFF',			chr(16));
define('LINEMODE_SLC_FORW1',		chr(17));
define('LINEMODE_SLC_FORW2',		chr(18));
define('LINEMODE_SLC_MCL',			chr(19));
define('LINEMODE_SLC_MCR',			chr(20));
define('LINEMODE_SLC_MCWL',			chr(21));
define('LINEMODE_SLC_MCWR',			chr(22));
define('LINEMODE_SLC_MCBOL',		chr(23));
define('LINEMODE_SLC_MCEOL',		chr(24));
define('LINEMODE_SLC_INSRT',		chr(25));
define('LINEMODE_SLC_OVER',			chr(26));
define('LINEMODE_SLC_ECR',			chr(27));
define('LINEMODE_SLC_EWR',			chr(28));
define('LINEMODE_SLC_EBOL', 		chr(29));
define('LINEMODE_SLC_EEOL',			chr(30));

define('LINEMODE_SLC_DEFAULT',		chr(3));
define('LINEMODE_SLC_VALUE',		chr(2));
define('LINEMODE_SLC_CANTCHANGE',	chr(1));
define('LINEMODE_SLC_NOSUPPORT',	chr(0));
define('LINEMODE_SLC_LEVELBITS',	chr(3));

define('LINEMODE_SLC_ACK',			chr(128));
define('LINEMODE_SLC_FLUSHIN',		chr(64));
define('LINEMODE_SLC_FLUSHOUT',		chr(32));

// Some telnet edit mode states
define('TELNET_MODE_ECHO', 1);
define('TELNET_MODE_LINEMODE', 2);
define('TELNET_MODE_BINARY', 4);
define('TELNET_MODE_SGA', 8);
define('TELNET_MODE_NAWS', 16);
define('TELNET_MODE_INSERT', 1024);

// Some control character defines - saves us from having to do ord() on the characters all the time
define('KEY_IP',					chr(0x03));			// backspace
define('KEY_BS',					chr(0x08));			// backspace
define('KEY_ESCAPE',				chr(0x1B));			// escape
define('KEY_DELETE',				chr(0x7F));			// del

class TelnetClient
{
	private $socket			= null;
	private $ip				= '';
	private $port			= 0;
	
	private $lineBuffer		= array();
	private $lineBufferPtr	= 0;
	private $inputBuffer	= '';
	private $inputBufferLen	= 0;
	
	// send queue used for backlog, in case we can't send a reply in one go
	private $sendQ			= '';
	private $sendQLen		= 0;

	private $sendWindow		= STREAM_WRITE_BYTES;	// dynamic window size
	
	private $lastActivity	= 0;
	private $mustClose		= false;
	
	// If filled in, the user is logged in (or half-way logging in).
	private $username		= '';
	
	// We need these so we know the state of the login process.
	private $loginState		= 0;
	
	// Editing related
	private $modeState		= 0;
	private $winSize		= array();
	
	private $charMap		= array();
	
	
	public function __construct(&$sock, &$ip, &$port)
	{
		$this->socket		= $sock;
		$this->ip			= $ip;
		$this->port			= $port;
		
		$this->lastActivity	= time();
		
		// Send welcome message and ask for username
		$msg = "Welcome to the Prism remote console.\r\n";
		$msg .= "Please login with your Prism account details.\r\n";
		$msg .= "Username : ";
		
		$this->write($msg);
		$this->loginState = TELNET_ASKED_USERNAME;
		
		$this->modeState |= TELNET_MODE_INSERT;

		$this->setOption(TELNET_ACTION_DO, TELNET_OPT_BINARY);
		$this->setOption(TELNET_ACTION_WILL, TELNET_OPT_ECHO);
		$this->setOption(TELNET_ACTION_DO, TELNET_OPT_SGA);
		$this->setOption(TELNET_ACTION_DO, TELNET_OPT_LINEMODE);
		$this->setOption(TELNET_ACTION_DO, TELNET_OPT_NAWS);
	}
	
	public function __destruct()
	{
		if ($this->sendQLen > 0)
			$this->sendQReset();

		if (is_resource($this->socket))
			fclose($this->socket);
	}

	public function &getSocket()
	{
		return $this->socket;
	}
	
	public function &getRemoteIP()
	{
		return $this->ip;
	}
	
	public function &getRemotePort()
	{
		return $this->port;
	}
	
	public function &getLastActivity()
	{
		return $this->lastActivity;
	}
	
	public function setOption($action, $option)
	{
		$this->write(TELNET_IAC.$action.$option);
	}
	
	public function getLoginState()
	{
		return $this->loginState;
	}
	
	public function getMustClose()
	{
		return $this->mustClose;
	}
	
	private function doLogin()
	{
		$line = $this->getLine();
		if ($line === false)
			return;
		
		switch($this->getLoginState())
		{
			case TELNET_NOT_LOGGED_IN :
				// Send error notice and ask for username
				$msg .= "Please login with your Prism account details.\r\n";
				$msg .= "Username : ";
				
				$this->write($msg);
				$this->loginState = TELNET_ASKED_USERNAME;
				
				break;
			
			case TELNET_ASKED_USERNAME :
				if ($line == '')
				{
					$this->write('Username : ');
					break;
				}
				$this->username = $line;
				$this->write("Password : ");
				$this->loginState = TELNET_ASKED_PASSWORD;
				
				break;
			
			case TELNET_ASKED_PASSWORD :
				if ($this->verifyLogin($line))
				{
					$this->loginState = TELNET_LOGGED_IN;
					$this->write("Login successful\r\n");
					console('Successful telnet login from '.$this->username.' on '.date('r'));
					
					// Now setup the screen
				}
				else
				{
					$msg = "Incorrect login. Please try again.\r\n";
					$msg .= "Username : ";
					$this->username = '';
					$this->write($msg);
					$this->loginState = TELNET_ASKED_USERNAME;
				}
				break;
		}
	}
	
	public function verifyLogin(&$password)
	{
		global $PRISM;

		return ($PRISM->admins->isPasswordCorrect($this->username, $password));
	}
	
	public function read(&$data)
	{
		$this->lastActivity	= time();
		return fread($this->socket, STREAM_READ_BYTES);
	}
	
	public function addInputToBuffer(&$raw)
	{
//		for ($a=0; $a<strlen($raw); $a++)
//			printf('%02x', ord($this->translateClientChar($raw[$a])));
//		echo "\n";
		
		// (Control) Character translation
		
		
		// Add raw input to buffer
		$this->inputBuffer .= $raw;
		$this->inputBufferLen += strlen($raw);
	}
	
	public function processInput()
	{
		$haveLine = false;
		
		// Here we first check if a telnet command came in.
		// Otherwise we just pass the input to the window handler
		for ($a=0; $a<$this->inputBufferLen; $a++)
		{
			// Check if next bytes in the buffer is a command
			if ($this->inputBuffer[$a] == TELNET_IAC)
			{
				$startIndex = $a;
				$a++;
				switch ($this->inputBuffer[$a])
				{
					// IAC ACTION OPTION (3 bytes)
					case TELNET_ACTION_WILL :
						switch($this->inputBuffer[$a+1])
						{
							case TELNET_OPT_BINARY :
								console('Binary TRUE');
								$this->modeState |= TELNET_MODE_BINARY;
								break;
							case TELNET_OPT_SGA :
								console('SGA TRUE');
								$this->modeState |= TELNET_MODE_SGA;
								break;
							case TELNET_OPT_LINEMODE :
								console('Linemode TRUE');
								$this->modeState |= TELNET_MODE_LINEMODE;
								break;
							case TELNET_OPT_NAWS :
								console('NAWS TRUE');
								$this->modeState |= TELNET_MODE_NAWS;
								break;
						}
						$a++;
						break;
	
					case TELNET_ACTION_WONT :
						switch($this->inputBuffer[$a+1])
						{
							case TELNET_OPT_BINARY :
								console('Binary FALSE');
								$this->modeState &= ~TELNET_MODE_BINARY;
								break;
							case TELNET_OPT_SGA :
								console('SGA FALSE');
								$this->modeState &= ~TELNET_MODE_SGA;
								break;
							case TELNET_OPT_LINEMODE :
								console('Linemode FALSE');
								$this->modeState &= ~TELNET_MODE_LINEMODE;
								break;
							case TELNET_OPT_NAWS :
								console('NAWS FALSE');
								$this->modeState &= ~TELNET_MODE_NAWS;
								break;
						}
						$a++;
						break;
	
					case TELNET_ACTION_DO :
						switch($this->inputBuffer[$a+1])
						{
							case TELNET_OPT_ECHO :
								console('Server DO echo');
								$this->modeState |= TELNET_MODE_ECHO;
								break;
						}
						$a++;
						break;
	
					case TELNET_ACTION_DONT :
						switch($this->inputBuffer[$a+1])
						{
							case TELNET_OPT_ECHO :
								console('Server DONT echo');
								$this->modeState &= ~TELNET_MODE_ECHO;
								break;
						}
						$a++;
						break;
	
	//				case TELNET_OPT_BINARY :
	//					break;
	//				
	//				case TELNET_OPT_ECHO :
	//					break;
	//				
	//				case TELNET_OPT_SGA :
	//					break;
	//				
	//				case TELNET_OPT_TTYPE :
	//					break;
	//				
	//				case TELNET_OPT_NAWS :
	//					break;
	//				
	//				case TELNET_OPT_TOGGLE_FLOW_CONTROL :
	//					break;
	//				
	//				case TELNET_OPT_LINEMODE :
	//					break;
					
					// AIC OPTION (2 bytes)
					case TELNET_OPT_NOP :
						break;
					
					case TELNET_OPT_DM :
						break;
					
					case TELNET_OPT_BRK :
						break;
					
					case TELNET_OPT_IP :
						break;
					
					case TELNET_OPT_AO :
						break;
					
					case TELNET_OPT_AYT :
						break;
					
					case TELNET_OPT_EC :
						break;
					
					case TELNET_OPT_EL :
						break;
					
					case TELNET_OPT_GA :
						break;
					
					case TELNET_OPT_EOF :
						break;
					
					case TELNET_OPT_SUSP :
						break;
					
					case TELNET_OPT_ABORT :
						break;
					
					// Suboptions (variable length)
					case TELNET_OPT_SB :
						// Find the next IAC SE
						if (($pos = strpos($this->inputBuffer, TELNET_IAC.TELNET_OPT_SE, $a)) === false)
						{
							return true;		// we need more data.
						}
						
						$a++;
						$dist = $pos - $a;
						$subVars = substr($this->inputBuffer, $a, $dist);
						// Detect the command type
						switch ($subVars[0])
						{
							case TELNET_OPT_LINEMODE :
								switch ($subVars[1])
								{
									case LINEMODE_MODE :
										console('SB LINEMODE MODE sub command');
										break;
									
									case LINEMODE_FORWARDMASK :
										console('SB LINEMODE FORWARDMASK sub command');
										break;
									
									case LINEMODE_SLC :
										console('SB LINEMODE SLC sub command ('.strlen($subVars).')');
										$this->writeCharMap(substr($subVars, 2));
										break;
								}
								break;
							case TELNET_OPT_NAWS :
								console('SB NAWS sub command ('.strlen($subVars).')');
								$this->unescapeIAC($subVars);
								$screenInfo = unpack('Ctype/nwidth/nheight', $subVars);
								$this->winSize = array($screenInfo['width'], $screenInfo['height']);
								break;
						}
						$a += $dist + 1;
						break;
					
					case TELNET_OPT_SE :
						// Hmm not possible?
						break;
					
					// Command escape char
					case TELNET_IAC :			// Escaped AIC - treat as single 0xFF; send straight to linebuffer
						$this->charToLineBuffer($this->inputBuffer[$a]);
						break;
					
					default :
						console('UNKNOWN TELNET COMMAND ('.ord($this->inputBuffer[$a]).')');
						break;
					
				}
				
				// We have processed a full command - prune it from the buffer
				if ($startIndex == 0)
				{
					$this->inputBuffer = substr($this->inputBuffer, $a + 1);
					$this->inputBufferLen = strlen($this->inputBuffer);
					$a = -1;
				}
				else
				{
					$this->inputBuffer = substr($this->inputBuffer, 0, $startIndex).substr($this->inputBuffer, $a + 1);
					$this->inputBufferLen = strlen($this->inputBuffer);
				}
				//console('command');
			}
			else
			{
				// Translate char
				$char = $this->translateClientChar($this->inputBuffer[$a]);
				
				// Check char for special meaning
				$special = false;
				switch ($char)
				{
					case KEY_IP :
						$special = true;
						
						// Set close state and return false
						$this->mustClose = true;
						return false;
					
					case KEY_BS :
						$special = true;
						
						// See if there are any characters to (backwards) delete at all
						if ($this->lineBufferPtr > 0)
						{
							$this->lineBufferPtr--;
							array_splice($this->lineBuffer, $this->lineBufferPtr, 1);
							
							// Update the client
							$rewrite = '';
							$x = $this->lineBufferPtr;
							while (isset($this->lineBuffer[$x]))
								$rewrite .= $this->lineBuffer[$x++];
							$cursorBack = KEY_ESCAPE.'['.(strlen($rewrite)+1).'D';
							$this->write($this->inputBuffer[$a].$rewrite.' '.$cursorBack);
						}
						break;

					case KEY_DELETE :
						$special = true;
						
						// See if we're not at the end of the line buffer
						if (isset($this->lineBuffer[$this->lineBufferPtr]))
						{
							array_splice($this->lineBuffer, $this->lineBufferPtr, 1);
							
							// Update the client
							$rewrite = '';
							$x = $this->lineBufferPtr;
							while (isset($this->lineBuffer[$x]))
								$rewrite .= $this->lineBuffer[$x++];
							$cursorBack = KEY_ESCAPE.'['.(strlen($rewrite)+1).'D';
							$this->write($rewrite.' '.$cursorBack);
						}
						
						break;
					
					case KEY_ESCAPE :
						// Always skip at least escape char from lineBuffer.
						// Below we further adjust the $a pointer where needed.
						$special = true;

						// Look ahead in inputBuffer to detect escape sequence
						if (!isset($this->inputBuffer[$a+1]) || $this->inputBuffer[$a+1] != '[')
							break;
						
						$input = substr($this->inputBuffer, $a);
						$matches = array();
						if (preg_match('/^('.KEY_ESCAPE.'\[(\d?)D).*$/', $input, $matches))
						{
							// CURSOR LEFT
							if ($this->lineBufferPtr > 0)
							{
								$this->write($matches[1]);
								$a += strlen($matches[1]) - 1;
								$this->lineBufferPtr -= ((int) $matches[2] > 1) ? (int) $matches[2] : 1;
							}
						}
						else if (preg_match('/^('.KEY_ESCAPE.'\[(\d?)C).*$/', $input, $matches))
						{
							// CURSOR RIGHT
							if (isset($this->lineBuffer[$this->lineBufferPtr]))
							{
								$this->write($matches[1]);
								$a += strlen($matches[1]) - 1;
								$this->lineBufferPtr += ((int) $matches[2] > 1) ? (int) $matches[2] : 1;
							}
						}
						else if (preg_match('/^('.KEY_ESCAPE.'\[(\d?)A).*$/', $input, $matches))
						{
							// CURSOR UP
							//$this->write($matches[1]);
						}
						else if (preg_match('/^('.KEY_ESCAPE.'\[(\d?)B).*$/', $input, $matches))
						{
							// CURSOR DOWN
							//$this->write($matches[1]);
						}
						else if (preg_match('/^('.KEY_ESCAPE.'\[3~).*$/', $input, $matches))
						{
							// Alternate DEL keycode
							// See if we're not at the end of the line buffer
							if (isset($this->lineBuffer[$this->lineBufferPtr]))
							{
								array_splice($this->lineBuffer, $this->lineBufferPtr, 1);
								
								// Update the client
								$rewrite = '';
								$x = $this->lineBufferPtr;
								while (isset($this->lineBuffer[$x]))
									$rewrite .= $this->lineBuffer[$x++];
								$cursorBack = KEY_ESCAPE.'['.(strlen($rewrite)+1).'D';
								$this->write($rewrite.' '.$cursorBack);
							}
						}
						else if (preg_match('/^('.KEY_ESCAPE.'\[2~).*$/', $input, $matches))
						{
							// INSERT
							$this->modeState ^= TELNET_MODE_INSERT;
						}

						// Move inputBuffer pointer ahead to cover multibyte char?
						if (count($matches) > 1)
							$a += strlen($matches[1]) - 1;
						
						break;
				}
				
				// Add regular char to lineBuffer
				if (!$special)
					$this->charToLineBuffer($this->inputBuffer[$a]);
			}
		}

		$this->inputBuffer = substr($this->inputBuffer, $a + 1);
		$this->inputBufferLen = strlen($this->inputBuffer);
		
		if ($this->getLoginState() != TELNET_LOGGED_IN)
		{
			$this->doLogin();
		}
		else
		{
			// Here we must decide what to do with the input. Should we notify the window? Or what?
			// For now we just do getLine and print it out.
			do
			{
				$line = $this->getLine();
				if ($line === false)
					break;
				console('TELNET INPUT : '.$line);
			} while(true);
		}

		return true;
	}
	
	// Get a whole line from input
	public function getLine()
	{
		// Detect carriage return / line feed / whatever you want to call it
		$count = count($this->lineBuffer);
		if (!$count)
			return false;
		
		$line = '';
		$haveLine = false;
		for ($a=0; $a<$count; $a++)
		{
			if ($this->modeState & TELNET_MODE_LINEMODE)
			{
				if ($this->lineBuffer[$a] == "\r")
				{
					$haveLine = true;
					break;				// break out of the main char by char loop
				}
			}
			else
			{
				if (isset($this->lineBuffer[$a+1]) && 
					$this->lineBuffer[$a].$this->lineBuffer[$a+1] == "\r\n")
				{
					$a++;
					$haveLine = true;
					break;				// break out of the main char by char loop
				}
			}
			$line .= $this->lineBuffer[$a];
		}
		
		if ($haveLine)
		{
			// Send return to client if in echo mode (and later on, if in simple mode)
			if ($this->modeState & TELNET_MODE_ECHO)
				$this->write("\r\n");
			
			// Splice line out of line buffer
			array_splice($this->lineBuffer, 0, $a+1);
			
			$this->lineBuffer = array();
			$this->lineBufferPtr = 0;
			return $line;
		}

		return false;
	}
	
	// Get a single key from input
	public function getKey()
	{
		
	}

	private function charToLineBuffer($char)
	{
		if ($this->modeState & TELNET_MODE_INSERT)
			array_splice($this->lineBuffer, $this->lineBufferPtr, 0, $char);
		else
			$this->lineBuffer[$this->lineBufferPtr] = $char;
		$this->lineBufferPtr++;
		
		// Must we update the client?
		if ($this->modeState & TELNET_MODE_ECHO)
		{
			if (($char = filter_var($char, FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH)) != '')
			{
				$rewrite = $cursorBack = '';

				// Are we in insert mode and do we have to move any chars?
				if ($this->modeState & TELNET_MODE_INSERT && isset($this->lineBuffer[$this->lineBufferPtr]))
				{
					// Write the remaining chars and return cursor to original pos
					$x = $this->lineBufferPtr;
					while (isset($this->lineBuffer[$x]))
						$rewrite .= $this->lineBuffer[$x++];
					$cursorBack = KEY_ESCAPE.'['.(strlen($rewrite)).'D';
				}

				if ($this->loginState == TELNET_ASKED_PASSWORD)
					$this->write('*'.$rewrite.$cursorBack);
				else
					$this->write($char.$rewrite.$cursorBack);
			}
		}
	}
	
	private function translateClientChar($char)
	{
		foreach ($this->charMap as $func => $data)
		{
			if ($data[0] == $char)
			{
				$tr = $this->getFunctionChar($func);
				if ($tr)
					return $tr;
			}
		}
		
		return $char;
	}
	
	private function translateServerChar($char)
	{
		$this->charMap;
	}
	
	private function writeCharMap($mapData)
	{
		// Unescape IACIAC
		$this->unescapeIAC($mapData);
		
		// We must have a number of octect triplets
		$len = strlen($mapData);
		if (($len % 3) != 0)
			return false;
		
		$a = 0;
		$this->charMap = array();
		while ($a<$len)
		{
			$func		= $mapData[$a++];
			$options	= $mapData[$a++];
			$ascii		= $mapData[$a++];
			
			$this->charMap[$func] = array($ascii, $options);
		}
		
		return true;
	}
	
	private function unescapeIAC(&$data)
	{
		$new = '';
		for ($a=0; $a<strlen($data); $a++)
		{
			if ($data[$a] == TELNET_IAC &&
				isset($data[$a+1]) &&
				$data[$a+1] == TELNET_IAC)
			{
				continue;
			}
			$new .= $data[$a];
		}
		$data = $new;
	}
	
	// Get the default ascii character that belongs to a certain SLC function
	private function getFunctionChar($func)
	{
		switch ($func)
		{
			case LINEMODE_SLC_SYNCH :
				break;
			
			case LINEMODE_SLC_BRK :
				break;
			
			case LINEMODE_SLC_IP :
				return KEY_IP;			// ctrl-c
			
			case LINEMODE_SLC_AO :
				break;
			
			case LINEMODE_SLC_AYT :
				break;
			
			case LINEMODE_SLC_EOR :
				break;
			
			case LINEMODE_SLC_ABORT :
				break;
			
			case LINEMODE_SLC_EOF :
				break;
			
			case LINEMODE_SLC_SUSP :
				break;
			
			case LINEMODE_SLC_EC :
				return KEY_BS;			// backspace
			
			case LINEMODE_SLC_EL :
				break;
			
			case LINEMODE_SLC_EW :
				break;
			
			case LINEMODE_SLC_RP :
				break;

			case LINEMODE_SLC_LNEXT :
				break;
			
			case LINEMODE_SLC_XON :
				break;
			
			case LINEMODE_SLC_XOFF :
				break;
			
			case LINEMODE_SLC_FORW1 :
				break;
			
			case LINEMODE_SLC_FORW2 :
				break;
			
			case LINEMODE_SLC_MCL :
				break;
			
			case LINEMODE_SLC_MCR :
				break;
			
			case LINEMODE_SLC_MCWL :
				break;
			
			case LINEMODE_SLC_MCWR :
				break;
			
			case LINEMODE_SLC_MCBOL :
				break;
			
			case LINEMODE_SLC_MCEOL :
				break;
			
			case LINEMODE_SLC_INSRT :
				break;
			
			case LINEMODE_SLC_OVER :
				break;
			
			case LINEMODE_SLC_ECR :
				break;
			
			case LINEMODE_SLC_EWR :
				break;
			
			case LINEMODE_SLC_EBOL :
				break;
			
			case LINEMODE_SLC_EEOL :
				break;
		}
		
		return null;
	}
	
	public function write($data, $sendQPacket = FALSE)
	{
		$bytes = 0;
		$dataLen = strlen($data);
		if ($dataLen == 0)
			return 0;
		
		if (!is_resource($this->socket))
			return $bytes;
	
		if ($sendQPacket == TRUE)
		{
			// This packet came from the sendQ. We just try to send this and don't bother too much about error checking.
			// That's done from the sendQ flushing code.
			$bytes = @fwrite($this->socket, $data);
		}
		else
		{
			if ($this->sendQLen == 0)
			{
				// It's Ok to send packet
				$bytes = @fwrite($this->socket, $data);
				$this->lastActivity = time();
		
				if (!$bytes || $bytes != $dataLen)
				{
					// Could not send everything in one go - send the remainder to sendQ
					$this->addPacketToSendQ (substr($data, $bytes));
				}
			}
			else
			{
				// Remote is lagged
				$this->addPacketToSendQ($data);
			}
		}
	
		return $bytes;
	}

	public function &getSendQLen()
	{
		return $this->sendQLen;
	}
	
	public function addPacketToSendQ($data)
	{
		$this->sendQ			.= $data;
		$this->sendQLen			+= strlen($data);
	}

	public function flushSendQ()
	{
		// Send chunk of data
		$bytes = $this->write(substr($this->sendQ, 0, $this->sendWindow), TRUE);
		
		// Dynamic window sizing
		if ($bytes == $this->sendWindow)
			$this->sendWindow += STREAM_WRITE_BYTES;
		else
		{
			$this->sendWindow -= STREAM_WRITE_BYTES;
			if ($this->sendWindow < STREAM_WRITE_BYTES)
				$this->sendWindow = STREAM_WRITE_BYTES;
		}

		// Update the sendQ
		$this->sendQ = substr($this->sendQ, $bytes);
		$this->sendQLen -= $bytes;

		// Cleanup / reset timers
		if ($this->sendQLen == 0)
		{
			// All done flushing - reset queue variables
			$this->sendQReset();
		} 
		else if ($bytes > 0)
		{
			// Set when the last packet was flushed
			$this->lastActivity		= time();
		}
		//console('Bytes sent : '.$bytes.' - Bytes left : '.$this->sendQLen);
	}
	
	public function sendQReset()
	{
		$this->sendQ			= '';
		$this->sendQLen			= 0;
		$this->lastActivity		= time();
	}
}

?>
#!/opt/xphp/bin/php
<?php
/*
 This file is part of Grease
 http://github.com/AndrewRose/Grease
 http://andrewrose.co.uk
 License: GPL; see below
 Copyright Andrew Rose (hello@andrewrose.co.uk) 2014

    Grease is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    Grease is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with Grease.  If not, see <http://www.gnu.org/licenses/>
*/

//declare(ticks = 1);

namespace xdebugd;
include_once('/opt/grease/Xdebugd/Xmlhttp.php');
ini_set('memory_limit', '512M');

class Exception extends \Exception
{
	public function __construct($message, $code = 0, Exception $previous = null) 
	{
echo 'Got exception: '.$message.', code: '.$code."\n";
		parent::__construct($message, $code, $previous);
	}

	public function __toString()
	{
		return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
	}
}

class Handler
{
	private $xml;
	private $xmlhttp;
	public $connections = [];
	public $maxRead = 1024;

	public $clients = [];
	public $servers = [];

	public $recording = [];

	private $base;
	private $listener;

	public function __construct($pid)
	{
		//pcntl_signal(SIGTERM, [$this, "sig"]);

		$this->xml = new \DOMDocument();
		$this->xmlhttp = new Xmlhttp($this);

		$this->base = new \EventBase();
		if(!$this->base) 
		{
			exit("Couldn't open event base\n");
		}

		$this->listener = new \EventListener($this->base, [$this, 'ev_accept'], FALSE, \EventListener::OPT_CLOSE_ON_FREE | \EventListener::OPT_REUSEABLE, -1, '0.0.0.0:9000');

		if(!$this->listener)
		{
		    exit("Couldn't create listener\n");
		}

		$this->listener->setErrorCallback([$this, 'ev_error']);
		$this->base->dispatch();
		//$this->base->loop(\EventBase::NOLOCK);
	}

	public function record($idekey)
	{
echo 'got reques to record: '.$idekey."\n";

		$this->stepInto(FALSE, $this->servers[$idekey], $idekey) ;
		$this->recording[$idekey] = TRUE;
	}

	public function sig($num)
	{
		echo 'Got signal '.$num.' to exit...';
exit();
		
	}

	public function ev_error($listener, $ctx, $id)
	{
		$errno = \EventUtil::getLastSocketErrno();
		fprintf(STDERR, "Got an error %d (%s) on the listener. Shutting down.\n", $errno, \EventUtil::getLastSocketError());
		if($errno!=0)
		{
			if(isset($this->connections[$id]))
			{
				unset($this->servers[$this->connections[$id]['idekey']]);
				unset($this->connections[$id]);
			}
		}
		return FALSE;
	}

	public function ev_accept($listener, $fd, $address, $ctx)
	{
		static $id = 0;
		$id += 1;

		$this->connections[$id]['data'] = '';
		$this->connections[$id]['dataLength'] = FALSE;
		$this->connections[$id]['count'] = '';
		$this->connections[$id]['idekey'] = FALSE;

		$this->connections[$id]['cnx'] = new \EventBufferEvent($this->base, $fd, \EventBufferEvent::OPT_CLOSE_ON_FREE);

		if(!$this->connections[$id]['cnx'])
		{
			echo "Failed creating buffer\n";
			$this->base->exit(NULL);
			exit(1);
		}

		$this->connections[$id]['cnx']->setCallbacks([$this, "ev_read"], NULL, [$this, 'ev_error'], $id);
		$this->connections[$id]['cnx']->enable(\Event::READ | \Event::WRITE);
	}

	protected function ev_write($id, $string)
	{
//echo 'S('.$id.'): '.$string."\n";
if(!$id)
{
	print_r(debug_backtrace());
}

if(!isset($this->connections[$id]))
{
	return FALSE;
}
		$this->connections[$id]['cnx']->write($string);
		return TRUE;
	}

        public function ev_read($buffer, $id)
        {
//echo "\n\n".$id." is speaking.. ";
		while($buffer->input->length > 0)
		{
			$this->connections[$id]['data'] .= $buffer->input->read($this->maxRead);
		}
//echo $this->connections[$id]['data'] . "\n";

		if(!$this->connections[$id]['dataLength']  && $this->connections[$id]['data'][0] == 'G')
		{
			$h = new \http\Message($this->connections[$id]['data']);
			$url = new \http\Url($h->requestUrl);
			$url = $url->toArray();

			$params = [];
			parse_str($url['query'], $params);

			$this->handleWebRequest($id, $url['path'], $params, $h->getHeaders());

			$this->connections[$id]['dataLength'] = FALSE;
			$this->connections[$id]['data'] = '';
		}
		else
		{
			while(strlen($this->connections[$id]['data']))
			{
				if(!$this->connections[$id]['dataLength'])
				{
					$dataLength = strlen($this->connections[$id]['data']);
					for($i=0; $i<$dataLength; $i++)
					{
						$ch = $this->connections[$id]['data']{$i};

						if($ch == "\0")
						{
							$this->connections[$id]['dataLength'] = $this->connections[$id]['count'];
							$this->connections[$id]['data'] = substr($this->connections[$id]['data'], strlen($this->connections[$id]['count'])+1);
							$this->connections[$id]['count'] = '';
							break 1;
						}
						$this->connections[$id]['count'] .= $ch;
					}
				}

				if($this->connections[$id]['dataLength'] && (strlen($this->connections[$id]['data']) >= $this->connections[$id]['dataLength']))
				{
					try
					{
						$data = substr($this->connections[$id]['data'], 0, $this->connections[$id]['dataLength']+1);
						$this->connections[$id]['data'] = substr($this->connections[$id]['data'], $this->connections[$id]['dataLength']+1, strlen($this->connections[$id]['data'])+1);  //+1 remove null byte
						$this->parseClientData($id, $data);
						$this->connections[$id]['dataLength'] = FALSE;

					}
					catch (Exception $e) 
					{

					}
				}
				else
				{
					break 1; // break out of loop so we can collect more datas
				}
			}
		}
	}

	private function scanContext($node, &$props)
	{
		foreach($node->childNodes as $node)
		{
			if(get_class($node) != 'DOMElement')
			{
				continue;
			}

			$name = $node->getAttribute('name');
			$props[$name] = [];
			$props[$name]['type'] = $node->getAttribute('type');
			
			if(!$node->hasAttribute('numchildren'))
			{
				if($node->hasAttribute('encoding') && ($node->getAttribute('encoding') == 'base64'))
				{
					$props[$name]['value'] = base64_decode(trim($node->textContent));
				}
				else
				{
					$props[$name]['value'] = $node->textContent ;
				}
			}
			else
			{
				$props[$name]['properties'] = [];
				$this->scanContext($node, $props[$name]['properties']);
			}
		}
	}

	private function scanStack($node)
	{
		$ret = [];
		foreach($node->childNodes as $node)
		{
			$ret[] = [
				'where' => $node->getAttribute('where'),
				'level' => $node->getAttribute('level'),
				'type' => $node->getAttribute('type'),
				'filename' => $node->getAttribute('filename'),
				'lineno' => $node->getAttribute('lineno')
			];
		}
		return $ret;
	}

	private function parseClientData($id, $data)
	{
echo '--->'.$data."<---\n";
		$this->xml->loadXML($data);
		$rootNode = $this->xml->documentElement;

		if($rootNode->nodeName == 'init') // server xdebug init
		{
			$idekey = $rootNode->getAttribute('idekey');
//echo "server (".$idekey.") says: ".$data ."\n\n";
			$this->servers[$idekey] = $id;
			$this->connections[$id]['idekey'] = $idekey;
		}
		else if($rootNode->nodeName == 'response') // server xdebug
		{
			// check for error response from xdebug i.e:
			// <error code="5"><message><![CDATA[command is not available]]></message></error>
			if($rootNode->hasChildNodes() && $rootNode->childNodes->item(0)->nodeName == 'error')
			{
				throw new Exception($rootNode->childNodes->item(0)->childNodes->item(0)->textContent, $rootNode->childNodes->item(0)->getAttribute('code'));
			}

			$idekey = $rootNode->getAttribute('transaction_id');
//echo "server (".$idekey.") says: ".$data ."\n\n";
			if(!isset($this->clients[$idekey]))
			{
				$ret = 0;
				$this->ev_write($id, strlen($ret)."\0".$ret);
				return;
			}

			if($rootNode->getAttribute('status') && $rootNode->getAttribute('status') == 'stopped')
			{
				if($this->clients[$idekey])
				{
					$this->ev_write($this->clients[$idekey], strlen(1)."\0".'1');
				}
				else
				{
					$this->xmlhttp->session[$idekey][] = 'STOPPED';
				}
				unset($this->servers[$idekey]);
				unset($this->clients[$idekey]);
				return;
			}

			if($rootNode->getAttribute('status') && $rootNode->getAttribute('status') == 'stopping')
			{
				$stopping = TRUE;
			}
			else
			{
				$stopping = FALSE;
			}

			if($stopping)
			{
				if($this->clients[$idekey])
				{
					$this->ev_write($this->clients[$idekey], strlen(1)."\0".'0');
return;
				}
				else
				{
					$this->xmlhttp->session[$idekey][] = 'STOPPING';
				}
			}
			else
			{
				$ret = '';
				switch((string)$rootNode->getAttribute('command'))
				{
					case 'run':
					{
echo 'got run!';
						$ret = ['status' => $rootNode->getAttribute('status') ];

						if($ret['status'] == 'break')
						{
							$node = $rootNode->childNodes->item(0);
							$ret['filename'] = $node->getAttribute('filename');
							$ret['lineno'] = $node->getAttribute('lineno');
						}

						$ret = json_encode($ret);
						//$this->ev_write($this->clients[$idekey], strlen($ret)."\0".$ret);
					}
					break;

					case 'context_get':
					{
						$this->scanContext($rootNode, $ret);
						$ret = json_encode($ret);
						
						//$this->ev_write($this->clients[$idekey], strlen($ret)."\0".$ret);
					}
					break;

					case 'stack_get':
					{
						$ret = json_encode($this->scanStack($rootNode));
						//$this->ev_write($this->clients[$idekey], strlen($ret)."\0".$ret);
					}
					break;

					case 'step_into':
					{
						$node = $rootNode->childNodes->item(0);
						$filename = $node->getAttribute('filename');
						$lineno = $node->getAttribute('lineno');

$file = new \SplFileObject($filename);
$file->seek($lineno-1);

						$ret = json_encode(['filename' => $filename, 'lineno' => $lineno, 'preview' =>$file->current()]);

if(isset($this->recording[$idekey]) && $this->recording[$idekey])
{
	
	$this->stackGet(FALSE, $this->servers[$idekey], $idekey) ;
	$this->contextGet(FALSE, $this->servers[$idekey], $idekey) ;
	$this->stepInto(FALSE, $this->servers[$idekey], $idekey) ;
}

//echo "Got stepInto xdebugd\n Will return: ".$ret."\n";
						//$this->ev_write($this->clients[$idekey], strlen($ret)."\0".$ret);
					}
					break;

					case 'breakpoint_set':
					{
						$ret = $rootNode->getAttribute('id');
///echo 'got breakpoint id: '.$ret."\n";
						//$this->ev_write($this->clients[$idekey], strlen($ret)."\0".$ret);
					}
					break;

					case 'stop':
					{
						//$this->ev_write($this->clients[$idekey],  1);
						$ret = '1';
					}
					break;
				}

				if($this->clients[$idekey])
				{
echo 'sending back to client: '.$ret."<<<\n";
					$this->ev_write($this->clients[$idekey], strlen($ret)."\0".$ret);
				}
				else
				{
					$this->xmlhttp->sessions[$idekey][] = json_decode($ret, TRUE);
				}
				unset($ret);
			}
		}
		else if($rootNode->nodeName == 'request') // client ide
		{
//echo "client says: ".$data ."\n\n";

			if($rootNode->getAttribute('command') == 'getSessions')
			{
				$ret = json_encode($this->servers);
				$this->ev_write($id, strlen($ret)."\0".$ret);
				return;
			}

			$idekey = $rootNode->getAttribute('idekey');

			if(!isset($this->servers[$idekey]))
			{
				$ret = 0;
				$this->ev_write($id, strlen($ret)."\0".$ret);
				return;
			}

			$serverId = $this->servers[$idekey];

			switch($rootNode->getAttribute('command'))
			{
				case 'init':
				{
					$this->init($id, $serverId, $idekey);
				}
				break;

				case 'run':
				{
					$this->run($id, $serverId, $idekey);
				}
				break;

				case 'stop':
				{
					$this->stop($id, $serverId, $idekey);
				}
				break;

				case 'stepInto':
				{
					$this->stepInto($id, $serverId, $idekey);
				}
				break;

				case 'contextGet':
				{
					$this->contextGet($id, $serverId, $idekey);
				}
				break;

				case 'stackGet':
				{
					$this->stackGet($id, $serverId, $idekey);
				}
				break;

				case 'breakpointSetLine':
				{
					$this->breakpointSetLine($id, $serverId, $idekey, $rootNode->getAttribute('filename'), $rootNode->getAttribute('lineno'));
				}
				break;

				case 'breakpointRemoveLine':
				{
					$this->breakpointRemoveLine($id, $serverId, $idekey, $rootNode->getAttribute('breakpointId'));
				}
				break;
			}
		}
	}

	public function init($clientId, $serverId, $idekey)
	{
		$this->featureSet($clientId, $serverId, $idekey, 'max_data', 64);
		$this->featureSet($clientId, $serverId, $idekey, 'max_depth', 4);
		$this->featureSet($clientId, $serverId, $idekey, 'max_children', 16);
		$this->clients[$idekey] = $clientId;

		$ret = 1;
		if($clientId)
		{
			$this->ev_write($clientId, strlen($ret)."\0".$ret);
		}
	}

	public function stepInto($clientId, $serverId, $idekey) // return file and line number
	{
		echo "step into\n";
		$this->ev_write($serverId, 'step_into -i "'.$idekey."\"\0");
	}

	public function status($clientId, $serverId, $idekey)
	{
		echo "status\n";
		$this->ev_write($serverId, 'status -i "'.$idekey."\"\0");
	}

	public function stackGet($clientId, $serverId, $idekey)
	{
		echo "stackGet\n";
		$this->ev_write($serverId,  'stack_get -i "'.$idekey."\"\0");
	}

	public function contextGet($clientId, $serverId, $idekey)
	{
		echo "contextGet\n";
		$this->ev_write($serverId, 'context_get -i "'.$idekey."\" -c 0 -d 0\0");
	}

	public function featureSet($clientId, $serverId, $idekey, $n, $v)
	{
		echo "featureSet\n";
		$this->ev_write($serverId, 'feature_set -i "'.$idekey."\" -n $n -v $v\0");
	}

	public function run($clientId, $serverId, $idekey)
	{
		echo "run\n";
		$this->ev_write($serverId, 'run -i "'.$idekey."\"\0");
	}

	public function stop($clientId, $serverId, $idekey)
	{
		echo "stop\n";
		$this->ev_write($serverId, 'stop -i "'.$idekey."\"\0");
$this->connections[$serverId]['cnx']->free();
	}

	public function breakpointSetLine($clientId, $serverId, $idekey, $filename, $lineno)
	{
		echo "breakpointSetLine: ".'breakpoint_set -i "'.$idekey.'" -t line -s enabled -f "'.$filename.'" -n "'.$lineno."\"\0"."\n";
		$this->ev_write($serverId, 'breakpoint_set -i "'.$idekey.'" -t line -s enabled -f "'.$filename.'" -n "'.$lineno."\"\0");
	}

	public function breakpointRemoveLine($clientId, $serverId, $idekey, $breakpointId)
	{
		echo "breakpointRemoveLine: \n";
		$this->ev_write($serverId, 'breakpoint_remove -i "'.$idekey.'" -d "'.$breakpointId."\"\0");
	}

	public function handleWebRequest($clientId, $url, $params, $headers)
	{
		$wwwroot = 'Xdebugd/html/';
		$data = '';

		if($url == '/')
		{
		$ret = 'HTTP/1.0 200 OK
Content-Type: text/html
Content-Length: ';
			$data .= file_get_contents($wwwroot.'index.html');
		}
		else
		{
		$ret = 'HTTP/1.0 200 OK
Content-Type: application/json
Content-Length: ';
			$data = $this->xmlhttp->handler($url, $params);
		}

		$ret .= strlen($data)."\r\n\r\n";
		$this->ev_write($clientId, $ret.$data);
	}
}

$pid = pcntl_fork();
if($pid)
{
	return 1;
}
$pid = 0;
$t = new Handler($pid);

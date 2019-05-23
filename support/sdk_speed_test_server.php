<?php
	// Client SDK for the Speed Test server.
	// (C) 2019 CubicleSoft.  All Rights Reserved.

	class TCPSpeedTest
	{
		protected $fp, $debug;

		public function __construct()
		{
			$this->fp = false;
			$this->debug = false;
		}

		public function __destruct()
		{
			$this->Disconnect();
		}

		public function SetDebug($debug)
		{
			$this->debug = (bool)$debug;
		}

		public function Connect($host, $port)
		{
			$context = stream_context_create();

			$this->fp = @stream_socket_client("tcp://" . $host . ":" . $port, $errornum, $errorstr, 3, STREAM_CLIENT_CONNECT, $context);
			if ($this->fp === false)  return array("success" => false, "error" => self::TSTTranslate("Unable to connect to the server.  Try again later."), "errorcode" => "connect_failed");

			return array("success" => true);
		}

		public function Disconnect()
		{
			if ($this->fp !== false)
			{
				fclose($this->fp);

				$this->fp = false;
			}
		}

		public function Stats()
		{
			$data = array(
				"action" => "stats"
			);

			return $this->RunAPI($data);
		}

		public function RunLatencyTest($secs = 10)
		{
			$total = 0;
			$num = 0;
			$startts = microtime(true);
			do
			{
				$ts = microtime(true);

				$data = array(
					"action" => "latency"
				);

				$result = $this->RunAPI($data);
				if (!$result["success"])  return $result;

				$ts2 = microtime(true);

				$total += ($ts2 - $ts);
				$num++;
			} while (microtime(true) - $startts < $secs);

			return array("success" => true, "avg" => $total / $num);
		}

		public function RunDownloadTest($secs = 10)
		{
			$data = array(
				"action" => "download",
				"secs" => (int)$secs
			);

			$result = $this->RunAPI($data);
			if (!$result["success"])  return $result;

			// There will be roughly the specified number of seconds of data sent down the pipe terminated by a newline.
			$size = 0;
			do
			{
				$data = @fread($this->fp, 65536);
				if ($data === false || ($data === "" && feof($this>fp)))  return array("success" => false, "error" => self::TSTTranslate("Server disconnected."), "errorcode" => "download_test_failed");

				$size += strlen($data);

				if (strpos($data, "\n") !== false)  break;
			} while (1);

			return array("success" => true, "size" => $size, "time" => 10);
		}

		public function RunUploadTest($secs = 10)
		{
			if ($this->fp === false)  return array("success" => false, "error" => self::TSTTranslate("Not connected to the server."), "errorcode" => "not_connected");

			$data = array(
				"action" => "upload"
			);

			$result = $this->RunAPI($data);
			if (!$result["success"])  return $result;

			if (!class_exists("CSPRNG", false))  $data = random_bytes(4096);
			else
			{
				$rng = new CSPRNG();
				$data = $rng->GetBytes(4096);
			}

			$data = bin2hex($data);

			$size = 0;
			$startts = microtime(true);
			do
			{
				$result = @fwrite($this->fp, $data);
				if ($result < strlen($data))  return array("success" => false, "error" => self::TSTTranslate("Failed to complete sending request to the server."), "errorcode" => "service_request_failed");

				$size += $result;
				$endts = microtime(true);
			} while ($endts - $startts < $secs);

			$result = @fwrite($this->fp, "\n");
			if ($result != 1)  return array("success" => false, "error" => self::TSTTranslate("Failed to complete sending request to the server."), "errorcode" => "service_request_failed");

			return array("success" => true, "size" => $size, "time" => $endts - $startts);
		}

		protected function RunAPI($data)
		{
			if ($this->fp === false)  return array("success" => false, "error" => self::TSTTranslate("Not connected to the server."), "errorcode" => "not_connected");

			// Send the request.
			$data = json_encode($data, JSON_UNESCAPED_SLASHES) . "\n";
			$result = @fwrite($this->fp, $data);
			if ($this->debug)
			{
				echo "------- RAW SEND START -------\n";
				echo substr($data, 0, $result);
				echo "------- RAW SEND END -------\n\n";
			}
			if ($result < strlen($data))  return array("success" => false, "error" => self::TSTTranslate("Failed to complete sending request to the server."), "errorcode" => "service_request_failed");

			// Wait for the response.
			$data = @fgets($this->fp);
			if ($this->debug)
			{
				echo "------- RAW RECEIVE START -------\n";
				echo $data;
				echo "------- RAW RECEIVE END -------\n\n";
			}
			$data = @json_decode($data, true);
			if (!is_array($data) || !isset($data["success"]))  return array("success" => false, "error" => self::TSTTranslate("Unable to decode the response from the server."), "errorcode" => "decoding_failed");

			return $data;
		}

		protected static function TSTTranslate()
		{
			$args = func_get_args();
			if (!count($args))  return "";

			return call_user_func_array((defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC) ? CS_TRANSLATE_FUNC : "sprintf"), $args);
		}
	}
?>
<?php
	// Network speed tester.
	// (C) 2019 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cli.php";
	require_once $rootpath . "/support/str_basics.php";

	// Process the command-line options.
	$options = array(
		"shortmap" => array(
			"d" => "debug",
			"s" => "suppressoutput",
			"?" => "help"
		),
		"rules" => array(
			"debug" => array("arg" => false),
			"suppressoutput" => array("arg" => false),
			"help" => array("arg" => false)
		),
		"allow_opts_after_param" => false
	);
	$args = CLI::ParseCommandLine($options);

	if (isset($args["opts"]["help"]))
	{
		echo "Network speed tester command-line tool\n";
		echo "Purpose:  Run speed tests of a network to verify speed compliance.\n";
		echo "\n";
		echo "This tool is question/answer enabled.  Just running it will provide a guided interface.  It can also be run entirely from the command-line if you know all the answers.\n";
		echo "\n";
		echo "Syntax:  " . $args["file"] . " [options] [downmbits upmbits type [typeoptions]]\n";
		echo "Options:\n";
		echo "\t-d   Enable debugging mode.  Displays each executed command.\n";
		echo "\t-s   Suppress most output.  Useful for capturing JSON output.\n";
		echo "\n";
		echo "Examples:\n";
		echo "\tphp " . $args["file"] . "\n";
		echo "\tphp " . $args["file"] . " 20 5 ssh -profile testhost\n";
		echo "\tphp " . $args["file"] . " -s 20 5 digitalocean -prefix speedtest -region nyc1 -keep N\n";

		exit();
	}

	// Check enabled extensions.
	if (!extension_loaded("openssl"))  CLI::DisplayError("The 'openssl' PHP module is not enabled.  Please update the file '" . (php_ini_loaded_file() !== false ? php_ini_loaded_file() : "php.ini") . "' to enable the module.");

	require_once $rootpath . "/support/random.php";

	$origargs = $args;
	$debug = (isset($args["opts"]["debug"]) && $args["opts"]["debug"]);
	$suppressoutput = (isset($args["opts"]["suppressoutput"]) && $args["opts"]["suppressoutput"]);

	$downmbits = (double)CLI::GetUserInputWithArgs($args, false, "Mbits down", false, "The next couple of questions ask for the number of megabits down (frequently shown as Mbits or Mb) and megabits up that you pay for.  If you don't know, your modem/router should have that information or you can call your ISP and ask.", $suppressoutput);
	$upmbits = (double)CLI::GetUserInputWithArgs($args, false, "Mbits up", false, "", $suppressoutput);
	$downbytes = (int)($downmbits * 0.9 * 1000000 / 8);
	$upbytes = (int)($upmbits * 0.9 * 1000000 / 8);

	$types = array("ssh" => "SSH speed test", "tcp" => "TCP speed test", "ookla" => "Speedtest.net/OoklaServer speed test", "digitalocean" => "DigitalOcean speed test");

	$type = CLI::GetLimitedUserInputWithArgs($args, false, "Speed test", false, "Available speed test types:", $types, true, $suppressoutput);

	function DisplayResult($result)
	{
		echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

		exit();
	}

	function ReinitArgs($newargs)
	{
		global $args;

		// Process the parameters.
		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
			)
		);

		foreach ($newargs as $arg)  $options["rules"][$arg] = array("arg" => true, "multiple" => true);
		$options["rules"]["help"] = array("arg" => false);

		$args = CLI::ParseCommandLine($options, array_merge(array(""), $args["params"]));

		if (isset($args["opts"]["help"]))  DisplayResult(array("success" => true, "options" => array_keys($options["rules"])));
	}

	function RunProcess($cmd, $displaycapture = false)
	{
		global $debug;

		if ($debug)  echo "Executing:  " . $cmd . "\n";

		$fp = popen($cmd, "r");
		$result = "";
		do
		{
			$data = fread($fp, 65536);
			if ($data === false || ($data === "" && feof($fp)))  break;

			if ($displaycapture)  echo $data;

			$result .= $data;
		} while (1);

		return $result;
	}

	function RunJSONProcess($cmd, $displaycapture = false)
	{
		global $debug;

		$origresult = RunProcess($cmd, $displaycapture);
		$result = $origresult;
		while (($pos = strpos($result, "{")) !== false)
		{
			if ($debug)  echo substr($result, 0, $pos);

			$result = substr($result, $pos);
			$result2 = @json_decode($result, true);
			if (is_array($result2))  return $result2;
		}

		CLI::DisplayError("An error occurred while parsing a response as JSON.  Original data:\n" . $origresult);
	}

	function GetDistance($lat, $lon, $lat2, $lon2, $radius = 6371)
	{
		$lat = deg2rad($lat);
		$lon = deg2rad($lon);
		$lat2 = deg2rad($lat2);
		$lon2 = deg2rad($lon2);

		$difflat = sin(($lat2 - $lat) / 2);
		$difflon = sin(($lon2 - $lon) / 2);

		$angle = 2 * asin(sqrt(($difflat * $difflat) + (cos($lat) * cos($lat2) * ($difflon * $difflon))));

		return $angle * $radius;
	}

	if ($type === "ssh")
	{
		ReinitArgs(array("profile"));

		// Get the list of SSH profiles.
		$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/ssh.php") . " -s profiles list";
		$result = RunJSONProcess($cmd);
		if (!$result["success"])  DisplayResult($result);

		if (!count($result["data"]))  CLI::DisplayError("No SSH profiles have been set up.  Set up at least one SSH profile by running 'php ssh.php' before running the SSH speed test.");

		$sshprofiles = array();
		foreach ($result["data"] as $id => $sshprofile)
		{
			$info = array();
			foreach ($sshprofile["chain"] as $item)  $info[] = $item["username"] . "@" . $item["host"] . " (" . $item["method"] . ")";

			$sshprofiles[$id] = implode(" -> ", $info) . ", " . date("M j, Y", $sshprofile["created"]);
		}
		$sshprofile = CLI::GetLimitedUserInputWithArgs($args, "profile", "SSH profile", false, "Available SSH profiles:", $sshprofiles, true, $suppressoutput);

		$finalresult = array(
			"success" => true
		);

		if ($downbytes > 0)
		{
			$size = $downbytes * 10;
			$size -= $size % 1024;

			// Generate a random file to download on the server.
			if (!$suppressoutput)  echo "Preparing download file on the server (" . number_format($size, 0) . " bytes)...\n";
			$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/ssh.php") . " -s connect shell-php -profile " . escapeshellarg($sshprofile) . " -run " . escapeshellarg("dd bs=1024 count=" . (int)($size / 1024) . " < /dev/urandom > /tmp/speedtest_download.dat 2>/dev/null") . " -run exit";
			$result = RunJSONProcess($cmd);
			if (!$result["success"])  DisplayResult($result);

			// Test the download speed.
			if (!$suppressoutput)  echo "Testing download speed (" . number_format($size, 0) . " bytes)...\n";
			$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/ssh.php") . " -s connect download -profile " . escapeshellarg($sshprofile) . " -src " . escapeshellarg("/tmp/speedtest_download.dat") . " -dest " . escapeshellarg($rootpath . "/cache/speedtest_download.dat");
			$result = RunJSONProcess($cmd);
			if (!$result["success"])  DisplayResult($result);

			$result["size"] = $size;
			$result["rawrate"] = $size / $result["main"];
			$result["mbitrate"] = $result["rawrate"] * 8 / 1000000 / 0.9;
			$result["percent"] = $result["mbitrate"] * 100 / $downmbits;
			$result["disprate"] = number_format($result["mbitrate"], 1) . " Mbits / sec (" . (int)$result["percent"] . "% of expected)";

			if (!$suppressoutput)  echo "Download speed test results:\n  " . number_format($result["connect"], 2) . " sec (connect), " . number_format($result["main"], 2) . " sec (data), " . $result["disprate"] . "\n";

			$finalresult["download"] = $result;

			@unlink($rootpath . "/cache/speedtest_download.dat");
		}

		if ($upbytes > 0)
		{
			// Generate a random file that will take approximately 10 seconds to upload.
			$rng = new CSPRNG();
			$fp = fopen($rootpath . "/cache/speedtest_upload.dat", "wb");
			for ($size = $upbytes * 10; $size > 65536; $size -= 65536)
			{
				fwrite($fp, $rng->GetBytes(65536));
			}
			if ($size)  fwrite($fp, $rng->GetBytes($size));
			fclose($fp);

			// Test the upload speed.
			$size = $upbytes * 10;
			if (!$suppressoutput)  echo "Testing upload speed (" . number_format($size, 0) . " bytes)...\n";
			$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/ssh.php") . " -s connect upload -profile " . escapeshellarg($sshprofile) . " -src " . escapeshellarg($rootpath . "/cache/speedtest_upload.dat") . " -dest " . escapeshellarg("/tmp/speedtest_upload.dat");
			$result = RunJSONProcess($cmd);
			if (!$result["success"])  DisplayResult($result);

			$result["size"] = $size;
			$result["rawrate"] = $size / $result["main"];
			$result["mbitrate"] = $result["rawrate"] * 8 / 1000000 / 0.9;
			$result["percent"] = $result["mbitrate"] * 100 / $upmbits;
			$result["disprate"] = number_format($result["mbitrate"], 1) . " Mbits / sec (" . (int)$result["percent"] . "% of expected)";

			if (!$suppressoutput)  echo "Upload speed test results:\n  " . number_format($result["connect"], 2) . " sec (connect), " . number_format($result["main"], 2) . " sec (data), " . $result["disprate"] . "\n";

			$finalresult["upload"] = $result;

			@unlink($rootpath . "/cache/speedtest_upload.dat");
		}

		DisplayResult($finalresult);
	}
	else if ($type === "tcp")
	{
		ReinitArgs(array("host", "port", "latency", "download", "upload"));

		require_once $rootpath . "/support/sdk_speed_test_server.php";

		// Get user input.
		$host = CLI::GetUserInputWithArgs($args, "host", "Host", false, "The next couple questions ask for the host and port of the TCP speed test server to connect to.", $suppressoutput);
		$port = (int)CLI::GetUserInputWithArgs($args, "port", "Port", false, "", $suppressoutput);

		// Attempt to connect.
		$tst = new TCPSpeedTest();
		$result = $tst->Connect($host, $port);
		if (!$result["success"])  CLI::DisplayError("Unable to connect to " . $host . ":" . $port, $result);

		// Verify that this is a TCP Speed Test server.
		$result = $tst->Stats();
		if (!$result["success"])  CLI::DisplayError("The server at " . $host . ":" . $port . " is not a compatible TCP speed test server.", $result);

		$latency = (double)CLI::GetUserInputWithArgs($args, "latency", "Latency time (secs)", "10", "", $suppressoutput);
		$download = (double)CLI::GetUserInputWithArgs($args, "download", "Download time (secs)", "10", "", $suppressoutput);
		$upload = (double)CLI::GetUserInputWithArgs($args, "upload", "Upload time (secs)", "10", "", $suppressoutput);

		$finalresult = array(
			"success" => true
		);

		if ($latency > 0)
		{
			if (!$suppressoutput)  echo "Testing network latency (" . $latency . " seconds)...\n";
			$result = $tst->RunLatencyTest($latency);
			if (!$result["success"])  CLI::DisplayError("The latency test failed.", $result);

			$result["dispavg"] = number_format($result["avg"] * 1000, 0) . " ms";

			if (!$suppressoutput)  echo "Average round-trip latency:  " . $result["dispavg"] . "\n";

			$finalresult["latency"] = $result;
		}

		if ($download > 0)
		{
			if (!$suppressoutput)  echo "Testing download speed (" . $download . " seconds)...\n";
			$result = $tst->RunDownloadTest($download);
			if (!$result["success"])  CLI::DisplayError("The download test failed.", $result);

			$result["rawrate"] = $result["size"] / $result["time"];
			$result["mbitrate"] = $result["rawrate"] * 8 / 1000000 / 0.9;
			$result["percent"] = $result["mbitrate"] * 100 / $downmbits;
			$result["disprate"] = number_format($result["mbitrate"], 1) . " Mbits / sec (" . (int)$result["percent"] . "% of expected)";

			if (!$suppressoutput)  echo "Download speed test results:\n  " . number_format($result["time"], 2) . " sec, " . $result["disprate"] . "\n";

			$finalresult["download"] = $result;
		}

		if ($upload > 0)
		{
			if (!$suppressoutput)  echo "Testing upload speed (" . $download . " seconds)...\n";
			$result = $tst->RunUploadTest($upload);
			if (!$result["success"])  CLI::DisplayError("The download test failed.", $result);

			$result["rawrate"] = $result["size"] / $result["time"];
			$result["mbitrate"] = $result["rawrate"] * 8 / 1000000 / 0.9;
			$result["percent"] = $result["mbitrate"] * 100 / $upmbits;
			$result["disprate"] = number_format($result["mbitrate"], 1) . " Mbits / sec (" . (int)$result["percent"] . "% of expected)";

			if (!$suppressoutput)  echo "Upload speed test results:\n  " . number_format($result["time"], 2) . " sec, " . $result["disprate"] . "\n";

			$finalresult["upload"] = $result;
		}

		$result = $tst->Stats();
		if (!$result["success"])  CLI::DisplayError("Unable to retrieve final server stats.", $result);

		$finalresult["serverstats"] = $result;

		DisplayResult($finalresult);
	}
	else if ($type === "ookla")
	{
		ReinitArgs(array("hosttype", "location", "id", "url", "download", "upload"));

		// Retrieve speedtest.net configuration.
		require_once $rootpath . "/support/web_browser.php";
		require_once $rootpath . "/support/tag_filter.php";

		$htmloptions = TagFilter::GetHTMLOptions();

		$web = new WebBrowser();

		$result = $web->Process("https://www.speedtest.net/speedtest-config.php");
		if (!$result["success"])  DisplayResult($result);

		$html = TagFilter::Explode($result["body"], $htmloptions);
		$root = $html->Get();

		$clientinfo = $root->Find('client')->current();
		if (!$suppressoutput)  echo "Your IP is " . $clientinfo->ip . " (" . $clientinfo->isp . ")\n";
		$lat = (double)$clientinfo->lat;
		$lon = (double)$clientinfo->lon;

		$serverconfig = $root->Find('server-config')->current();
		$ignoreids = explode(",", $serverconfig->ignoreids);
		$ignoreids2 = array();
		foreach ($ignoreids as $id)  $ignoreids2[trim($id)] = true;

		$hosttypes = array(
			"closest" => "Closest speedtest.net OoklaServer",
			"location" => "Choose a speedtest.net OoklaServer via location search",
			"id" => "Server ID of a speedtest.net OoklaServer",
			"url" => "Isolated OoklaServer running on a host"
		);

		$hosttype = CLI::GetLimitedUserInputWithArgs($args, "hosttype", "Host type", false, "Available host types:", $hosttypes, true, $suppressoutput);

		if ($hosttype === "url")
		{
			$url = CLI::GetUserInputWithArgs($args, "url", "OoklaServer upload URL", false, "", $suppressoutput);

			// Verify the server.
			$url2 = HTTP::ConvertRelativeToAbsoluteURL($url, "/speedtest/latency.txt?x=" . time());

			$web = new WebBrowser();

			$result = $web->Process($url2 . ".0");

			if (!$result["success"] || $result["response"]["code"] != 200 || trim($result["body"]) !== "test=test")  CLI::DisplayError("The specified URL '" . $url . "' is not a valid OoklaServer upload URL.");
		}
		else
		{
			// Download and parse the server list.
			if (!$suppressoutput)  echo "Loading server list...\n";

			$filename = $rootpath . "/cache/speedtest_net_servers.dat";
			if (!file_exists($filename) || filemtime($filename) < time() - 3 * 60 * 60)
			{
				$web = new WebBrowser();

				$result = $web->Process("https://www.speedtest.net/speedtest-servers-static.php");
				if (!$result["success"])  DisplayResult($result);

				file_put_contents($filename, $result["body"]);
			}

			$data = file_get_contents($filename);

			$servermap = array();
			function ExtractSpeedtestNetServers($stack, &$content, $open, $tagname, &$attrs, $options)
			{
				global $servermap, $ignoreids2;

				if ($tagname === "server")
				{
					if (!isset($ignoreids2[$attrs["id"]]))  $servermap[$attrs["id"]] = (array)$attrs;
				}

				return array("keep_tag" => false, "keep_interior" => false);
			}

			$htmloptions["tag_callback"] = "ExtractSpeedtestNetServers";

			TagFilter::Run($data, $htmloptions);

			if ($hosttype === "location")
			{
				$location = CLI::GetUserInputWithArgs($args, "location", "Location search string", false, "", $suppressoutput);

				$servers = array();
				foreach ($servermap as $id => $info)
				{
					if (stripos($info["name"], $location) !== false)
					{
						$dist = GetDistance($lat, $lon, (double)$info["lat"], (double)$info["lon"]);
						$servers[$id] = $info["sponsor"] . ", " . $info["name"] . ", " . $info["country"] . ", " . number_format($dist, 1) . " km (" . number_format($dist / 1.609, 1) . " mi)";
					}
				}
				if (!count($servers))  CLI::DisplayError("Unable to find a server name matching '" . $location . "'.");

				$id = CLI::GetLimitedUserInputWithArgs($args, "id", "Server ID", false, "Available server IDs:", $servers, true, $suppressoutput);
			}
			else if ($hosttype === "id")
			{
				$id = CLI::GetUserInputWithArgs($args, "id", "Server ID", false, "", $suppressoutput);

				if (!isset($servermap[$id]))  CLI::DisplayError("Unable to find server ID '" . $id . "'.");

				if (!$suppressoutput)
				{
					$info = $servermap[$id];
					$dist = GetDistance($lat, $lon, (double)$info["lat"], (double)$info["lon"]);
					echo $id . ":  " . $info["sponsor"] . ", " . $info["name"] . ", " . $info["country"] . ", " . number_format($dist, 1) . " km (" . number_format($dist / 1.609, 1) . " mi)\n";
				}
			}
			else if ($hosttype === "closest")
			{
				if (!$suppressoutput)  echo "Finding closest server by network latency...\n";

				$dists = array();
				foreach ($servermap as $id => $info)
				{
					$dist = GetDistance($lat, $lon, (double)$info["lat"], (double)$info["lon"]);
					$dists[$id] = $dist;
				}

				asort($dists);

				$latencies = array();
				$num = 0;
				foreach ($dists as $id => $dist)
				{
					$info = $servermap[$id];
					$url = HTTP::ConvertRelativeToAbsoluteURL($info["url"], "/speedtest/latency.txt?x=" . time());

					$total = 0;
					for ($x = 0; $x < 3; $x++)
					{
						$web = new WebBrowser();

						$result = $web->Process($url . "." . $x);

						if ($result["success"] && $result["response"]["code"] == 200 && trim($result["body"]) === "test=test")  $total += $result["endts"] - $result["startts"];
						else  $total += 3600;
					}

					$latencies[$id] = $total / 3;

					$num++;
					if ($num >= 5)  break;
				}

				asort($latencies);

				foreach ($latencies as $id => $latency)
				{
					if (!$suppressoutput)
					{
						$info = $servermap[$id];
						$dist = GetDistance($lat, $lon, (double)$info["lat"], (double)$info["lon"]);
						echo $id . ":  " . $info["sponsor"] . ", " . $info["name"] . ", " . $info["country"] . ", " . number_format($dist, 1) . " km (" . number_format($dist / 1.609, 1) . " mi), " . number_format($latency * 1000, 0) . " ms\n";
					}

					break;
				}
			}

			$url = $servermap[$id]["url"];
		}

		// OoklaServer appears to have WebSocket support for some things but not everything (i.e. not worth messing with at this point).
		// In Chrome, the WebSocket option only uses HI, GETIP, and PING ping frames.  Chrome is the only browser where I can readily inspect WebSocket frames.
		// The minified Javascript source [sigh] shows additional things and Firefox does use those packet types, but getting at the WebSocket frames to figure out what they look like isn't really doable in Firefox.
		// So far, this is all I've been able to figure out (client request -> server response):
		//   HI [optional guid] -> HELLO 2.6 (2.6.9) 2019-02-20.2246.62a8e21
		//   GETIP -> YOURIP 123.123.123.123
		//   PING [optional timestamp] -> PONG timestamp
		//   DOWNLOAD size -> ???
		//   UPLOAD_STATS 1 -> UPLOAD ???

		$download = (double)CLI::GetUserInputWithArgs($args, "download", "Download time (secs)", "10", "", $suppressoutput);
		$upload = (double)CLI::GetUserInputWithArgs($args, "upload", "Upload time (secs)", "10", "", $suppressoutput);

		$finalresult = array(
			"success" => true
		);

		if ($download > 0)
		{
			$size = $downbytes * 10;

			$url2 = HTTP::ConvertRelativeToAbsoluteURL($url, "/download?nocache=" . time() . "&size=" . ($size * 2));

			if (!$suppressoutput)  echo "Testing download speed (" . $download . " seconds)...\n";

			$startts = microtime(true);
			$total = 0;
			function SpeedTestDownload_Callback($response, $data, $opts)
			{
				global $startts, $download, $total;

				if ($response["code"] == 200)
				{
					$total += strlen($data);

					if ($startts + $download < microtime(true))  return false;
				}

				return true;
			}

			$options = array(
				"read_body_callback" => "SpeedTestDownload_Callback",
				"read_body_callback_opts" => false
			);

			do
			{
				$web = new WebBrowser();

				$result = $web->Process($url2, $options);
				if (!$result["success"] && $result["info"]["errorcode"] !== "read_body_callback")  CLI::DisplayError("An error occurred while performing the download test.", $result);
				$endts = microtime(true);
			} while ($endts - $startts < $download);

			$result = array(
				"success" => true,
				"size" => $total,
				"time" => $endts - $startts
			);

			$result["rawrate"] = $result["size"] / $result["time"];
			$result["mbitrate"] = $result["rawrate"] * 8 / 1000000 / 0.9;
			$result["percent"] = $result["mbitrate"] * 100 / $downmbits;
			$result["disprate"] = number_format($result["mbitrate"], 1) . " Mbits / sec (" . (int)$result["percent"] . "% of expected)";

			if (!$suppressoutput)  echo "Download speed test results:\n  " . number_format($result["time"], 2) . " sec, " . $result["disprate"] . "\n";

			$finalresult["download"] = $result;
		}

		if ($upload > 0)
		{
			$url2 = HTTP::ConvertRelativeToAbsoluteURL($url, "/upload?nocache=" . time());

			if (!$suppressoutput)  echo "Testing upload speed (" . $upload . " seconds)...\n";

			$web = new WebBrowser();
			$rng = new CSPRNG();

			$options = array(
				"method" => "POST",
				"headers" => array(
					"Content-Type" => "application/octet-stream",
					"Connection" => "keep-alive"
				),
				"body" => $rng->GetBytes(100000)
			);

			$startts = microtime(true);
			$total = 0;
			do
			{
				$result = $web->Process($url2, $options);
				if (!$result["success"])  CLI::DisplayError("An error occurred while performing the upload test.", $result);

				$total += $result["rawsendsize"];

				if (isset($result["fp"]) && is_resource($result["fp"]))  $options["fp"] = $result["fp"];

				$endts = microtime(true);
			} while ($endts - $startts < $upload);

			$result = array(
				"success" => true,
				"size" => $total,
				"time" => $endts - $startts
			);

			// NOTE:  Due to making multiple round trips, the overhead is adjusted somewhat more leniently from other speed run types.
			$result["rawrate"] = $result["size"] / $result["time"];
			$result["mbitrate"] = $result["rawrate"] * 8 / 1000000 / 0.85;
			$result["percent"] = $result["mbitrate"] * 100 / $upmbits;
			$result["disprate"] = number_format($result["mbitrate"], 1) . " Mbits / sec (" . (int)$result["percent"] . "% of expected)";

			if (!$suppressoutput)  echo "Upload speed test results:\n  " . number_format($result["time"], 2) . " sec, " . $result["disprate"] . "\n";

			$finalresult["upload"] = $result;
		}

		DisplayResult($finalresult);
	}
	else if ($type === "digitalocean")
	{
		ReinitArgs(array("prefix", "region", "keep"));

		// Verify that the DigitalOcean configuration file has been set up.
		if (!file_exists($rootpath . "/config.dat"))  CLI::DisplayError("The DigitalOcean CLI tool has not been configured.  Configure the DigitalOcean CLI tool first by running 'php do.php' before running the speed test.");

		// Get user input.
		$prefix = CLI::GetUserInputWithArgs($args, "prefix", "Droplet prefix", "speedtest", "", $suppressoutput);

		$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/do.php") . " -s regions list";
		$result = RunJSONProcess($cmd);
		if (!$result["success"])  DisplayResult($result);

		$regions = array();
		foreach ($result["data"] as $region)
		{
			if ($region["available"])  $regions[$region["slug"]] = $region["name"];
		}
		$region = CLI::GetLimitedUserInputWithArgs($args, "region", "Droplet region", false, "Available Droplet regions:", $regions, true, $suppressoutput);
		$dropletname = $prefix . "-" . $region;

		$keep = CLI::GetYesNoUserInputWithArgs($args, "keep", "Keep Droplet after test", "N", "The next question asks if you want to keep the Droplet called '" . $dropletname . "' around for more tests.  Reminder:  Each DigitalOcean Droplet is individually charged by the hour (rounded up to the nearest hour).", $suppressoutput);

		// Get the list of SSH keys.
		$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/ssh.php") . " -s keys list";
		$result = RunJSONProcess($cmd);
		if (!$result["success"])  DisplayResult($result);

		// If the SSH key 'digitalocean-speedtest' does not exist, create it.
		if (isset($result["data"]["digitalocean-speedtest"]))  $sshkey = $result["data"]["digitalocean-speedtest"];
		else
		{
			if (!$suppressoutput)  echo "Generating SSH key...\n";

			$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/ssh.php") . " -s keys create -name digitalocean-speedtest -bits 4096";
			$result = RunJSONProcess($cmd);
			if (!$result["success"])  DisplayResult($result);

			$sshkey = $result["ssh_key"];
		}

		// Find a matching SSH key in DigitalOcean.
		$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/do.php") . " -s ssh-keys list";
		$result = RunJSONProcess($cmd);
		if (!$result["success"])  DisplayResult($result);

		$keyinfo = false;
		foreach ($result["data"] as $info)
		{
			if ($info["fingerprint"] === $sshkey["fingerprint"])  $keyinfo = $info;
		}

		// Register a new SSH key.
		if ($keyinfo === false)
		{
			if (!$suppressoutput)  echo "Registering SSH key with DigitalOcean...\n";
			$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/ssh.php") . " -s keys get-info -key digitalocean-speedtest";
			$result = RunJSONProcess($cmd);
			if (!$result["success"])  DisplayResult($result);

			$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/do.php") . " -s ssh-keys create -name speedtest-" . time() . " -publickey " . escapeshellarg($result["ssh_key"]["public_ssh_authorized_keys"]);
			$result = RunJSONProcess($cmd);
			if (!$result["success"])  DisplayResult($result);

			$keyinfo = $result["ssk_key"];
		}

		// Create a tag called 'speedtest'.  The API is idempotent.
		$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/do.php") . " -s tags create speedtest";
		$result = RunJSONProcess($cmd);

		// Find a matching Droplet name.
		if (!$suppressoutput)  echo "Attempting to find Droplet with name '" . $dropletname . "'...\n";
		$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/do.php") . " -s droplets list tag speedtest";
		$result = RunJSONProcess($cmd);
		if (!$result["success"])  DisplayResult($result);

		$dropletinfo = false;
		foreach ($result["data"] as $info)
		{
			if ($info["name"] === $dropletname)  $dropletinfo = $info;
		}

		if ($dropletinfo === false)
		{
			if (!$suppressoutput)  echo "Unable to find a matching DigitalOcean Droplet.\nCreating a new Droplet in the '" . $region . "' region (this will take about one minute)...\n";
			$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/do.php") . " -s droplets create -name " . escapeshellarg($dropletname) . " -size s-1vcpu-1gb -backups N -ipv6 Y -private_network N -storage N -metadata " . escapeshellarg("") . " -region " . escapeshellarg($region) . " -image ubuntu-18-04-x64 -sshkey " . escapeshellarg($keyinfo["id"]) . " -sshkey " . escapeshellarg("") . " -tag speedtest -tag " . escapeshellarg("") . " -wait Y";
			$result = RunJSONProcess($cmd);
			if (!$result["success"])  DisplayResult($result);

			// Get the Droplet information.
			if (!$suppressoutput)  echo "Retrieving Droplet information...\n";
			$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/do.php") . " -s droplets get-info " . escapeshellarg($result["droplet"]["id"]);
			$result = RunJSONProcess($cmd);
			if (!$result["success"])  DisplayResult($result);

			$dropletinfo = $result["droplet"];
		}

		$finalresult = array(
			"success" => true
		);

		// Get the list of SSH connection profiles.
		$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/ssh.php") . " -s profiles list";
		$result = RunJSONProcess($cmd);
		if (!$result["success"])  DisplayResult($result);

		// Remove any existing SSH connection profile (e.g. early termination).
		$sshprofile = "digitalocean-" . $dropletname;
		if (isset($result["data"][$sshprofile]))
		{
			$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/ssh.php") . " -s profiles delete -profile " . escapeshellarg($sshprofile);
			$result = RunJSONProcess($cmd);
			if (!$result["success"])  DisplayResult($result);
		}

		// Create a SSH connection profile.
		$ipaddr = $dropletinfo["networks"]["v4"][0]["ip_address"];
		$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/ssh.php") . " -s profiles create -name " . escapeshellarg($sshprofile) . " -host " . escapeshellarg($ipaddr) . " -port 22 -username root -method ssh-key -key digitalocean-speedtest";
		$result = RunJSONProcess($cmd);
		if (!$result["success"])  DisplayResult($result);

		// Wait for SSH to become available.
		if (!$suppressoutput)  echo "Verifying SSH (port 22) connectivity...\n";
		$waitmsg = false;
		do
		{
			$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/ssh.php") . " -s connect test -profile " . escapeshellarg($sshprofile);
			$result = @RunJSONProcess($cmd);
			if (!$result["success"])
			{
				if (!$suppressoutput)
				{
					echo ($waitmsg ? "." : "Waiting for Droplet to finish booting...");

					$waitmsg = true;
				}

				sleep(5);
			}
		} while (!$result["success"]);
		if ($waitmsg)  echo "\n";

		// Make sure PHP is installed.
		if (!$suppressoutput)  echo "Confirming PHP CLI installation...\n";
		$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/ssh.php") . " -s connect shell-php -profile " . escapeshellarg($sshprofile) . " -run " . escapeshellarg("[ -f /usr/bin/php ] || (apt-get update && apt-get -y install php-cli)") . " -run " . escapeshellarg("[ -f \"/root/tcpspeedtestserver/server.php\" ] && php /root/tcpspeedtestserver/server.php uninstall") . " -run exit";
		$result = RunJSONProcess($cmd);
		if (!$result["success"])  DisplayResult($result);

		// Upload and install the TCP speed test server.
		if (!$suppressoutput)  echo "Uploading TCP speed test server...\n";
		$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/ssh.php") . " -s connect upload -profile " . escapeshellarg($sshprofile) . " -src " . escapeshellarg($rootpath . "/tcpspeedtestserver/") . " -dest " . escapeshellarg("/root/tcpspeedtestserver");
		$result = RunJSONProcess($cmd);
		if (!$result["success"])  DisplayResult($result);

		if (!$suppressoutput)  echo "Installing and starting TCP speed test server...\n";
		$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/ssh.php") . " -s connect shell-php -profile " . escapeshellarg($sshprofile) . " -run " . escapeshellarg("php /root/tcpspeedtestserver/server.php install") . " -run " . escapeshellarg("service php-speed-test-server restart") . " -run exit";
		$result = RunJSONProcess($cmd);
		if (!$result["success"])  DisplayResult($result);

		if (!$suppressoutput)  echo "Getting TCP speed test server configuration...\n";
		$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/ssh.php") . " -s connect download -profile " . escapeshellarg($sshprofile) . " -src " . escapeshellarg("/root/tcpspeedtestserver/config.dat") . " -dest " . escapeshellarg($rootpath . "/cache/speedtestserver_config.dat");
		$result = RunJSONProcess($cmd);
		if (!$result["success"])  DisplayResult($result);

		$config = json_decode(file_get_contents($rootpath . "/cache/speedtestserver_config.dat"), true);
		if (!is_array($config))  CLI::DisplayError("TCP speed test server failed to start.", false, false);

		// Run TCP speed test server tests.
		if (is_array($config))
		{
			foreach ($config["v4ports"] as $num => $port)
			{
				if (!$suppressoutput)  echo "Running TCP speed test for " . $ipaddr . ":" . $port . " (" . ($num + 1) . " of " . count($config["v4ports"]) . " ports)...\n";
				$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/speedtest.php") . ($suppressoutput ? " -s " : " ") . escapeshellarg($downmbits) . " " . escapeshellarg($upmbits) . " tcp -host " . escapeshellarg($ipaddr) . " -port " . escapeshellarg($port) . " -latency 10 -download 10 -upload 10";
				$result = RunJSONProcess($cmd, !$suppressoutput);
				if (!$result["success"])  DisplayResult($result);

				$finalresult["tcp_" . $port] = $result;
			}
		}

		// Run the SSH speed test.
		if (!$suppressoutput)  echo "Running SSH/SFTP speed test...\n";
		$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/speedtest.php") . ($suppressoutput ? " -s " : " ") . escapeshellarg($downmbits) . " " . escapeshellarg($upmbits) . " ssh -profile " . escapeshellarg($sshprofile);
		$result = RunJSONProcess($cmd, !$suppressoutput);
		if (!$result["success"])  DisplayResult($result);

		$finalresult["ssh"] = $result;

		// Cleanup.
		if (!$keep)
		{
			// Remove the SSH connection profile.
			$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/ssh.php") . " -s profiles delete -profile " . escapeshellarg($sshprofile);
			$result = RunJSONProcess($cmd);

			// Remove the Droplet.
			$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/do.php") . " -s droplets delete " . escapeshellarg($dropletinfo["id"]);
			$result = RunJSONProcess($cmd);
			if (!$result["success"])  DisplayResult($result);
		}

		DisplayResult($finalresult);
	}
?>
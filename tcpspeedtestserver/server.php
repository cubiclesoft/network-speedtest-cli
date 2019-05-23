<?php
	// PHP-based Speed Test Server.
	// (C) 2019 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cli.php";

	// Service Manager integration.
	if ($argc > 1)
	{
		require_once $rootpath . "/servicemanager/sdks/servicemanager.php";

		$sm = new ServiceManager($rootpath . "/servicemanager");

		echo "Service manager:  " . $sm->GetServiceManagerRealpath() . "\n\n";

		$servicename = "php-speed-test-server";

		if ($argv[1] == "install")
		{
			// Install the service.
			$args = array();
			$options = array();

			$result = $sm->Install($servicename, __FILE__, $args, $options, true);
			if (!$result["success"])  CLI::DisplayError("Unable to install the '" . $servicename . "' service.", $result);
		}
		else if ($argv[1] == "uninstall")
		{
			// Uninstall the service.
			$result = $sm->Uninstall($servicename, true);
			if (!$result["success"])  CLI::DisplayError("Unable to uninstall the '" . $servicename . "' service.", $result);
		}
		else
		{
			CLI::DisplayError("Command not recognized.  Run service manager directly for anything other than 'install' and 'uninstall'.");
		}

		exit();
	}

	require_once $rootpath . "/support/random.php";
	require_once $rootpath . "/support/generic_server.php";

	$rng = new CSPRNG();

	// Start the server.
	$gsservs = array();

	$config = array("v4ports" => array(), "v6ports" => array());
	$initports = array(80, 443, 8080, $rng->GetInt(5001, 49151), 0);
	foreach ($initports as $port)
	{
		$gs = new GenericServer();
//		$gs->SetDebug(true);
		$result = @$gs->Start("0.0.0.0", $port);
		if ($result["success"])
		{
			$tempip = stream_socket_get_name($gs->GetStream(), false);
			echo "Started " . $tempip . "\n";

			$pos = strrpos($tempip, ":");
			if ($pos !== false)  $port = (int)substr($tempip, $pos + 1);

			$config["v4ports"][] = $port;

			$gsservs[] = $gs;
		}

		$gs = new GenericServer();
//		$gs->SetDebug(true);
		$result = @$gs->Start("[::0]", $port);
		if ($result["success"])
		{
			$tempip = stream_socket_get_name($gs->GetStream(), false);
			echo "Started " . $tempip . "\n";

			$pos = strrpos($tempip, ":");
			if ($pos !== false)  $port = (int)substr($tempip, $pos + 1);

			$config["v6ports"][] = $port;

			$gsservs[] = $gs;
		}
	}

	file_put_contents($rootpath . "/config.dat", json_encode($config, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

	echo "Ready.\n";

	$stopfilename = __FILE__ . ".notify.stop";
	$reloadfilename = __FILE__ . ".notify.reload";
	$lastservicecheck = time();
	$running = true;

	do
	{
		// Implement the stream_select() call directly since multiple server instances are involved.
		$timeout = 3;
		$readfps = array();
		$writefps = array();
		$exceptfps = NULL;

		$ts = microtime(true);
		foreach ($gsservs as $num => $gs)
		{
			$clients = $gs->GetClients();
			foreach ($clients as $id => $client)
			{
				if (isset($client->appdata) && $client->appdata["download"])
				{
					if ($client->appdata["downloaduntil"] > $ts)
					{
						if (strlen($client->writedata) < 65536)  $client->writedata .= bin2hex($rng->GetBytes(((int)((65536 - strlen($client->writedata)) / 2) + 1)));
					}
					else
					{
						$client->writedata .= "\n";
						$client->appdata["download"] = false;
					}
				}
			}

			$gs->UpdateStreamsAndTimeout("tst_" . $num, $timeout, $readfps, $writefps);
		}

		$result = @stream_select($readfps, $writefps, $exceptfps, $timeout);
		if ($result === false)  break;

		foreach ($gsservs as $gs)
		{
			$result = $gs->Wait(0);
			if (!$result["success"])  break;

			// Handle active clients.
			foreach ($result["clients"] as $id => $client)
			{
				if (!isset($client->appdata))
				{
					echo "Client " . $id . " connected.\n";

					$client->appdata = array("download" => false, "downloaduntil" => 0, "upload" => false);
				}

				if ($client->appdata["upload"])
				{
					$pos = strpos($client->readdata, "\n");
					if ($pos === false)  $client->readdata = "";
					else
					{
						$client->readdata = substr($client->readdata, $pos + 1);

						$client->appdata["upload"] = false;
					}
				}

				if (!$client->appdata["upload"])
				{
					while (($pos = strpos($client->readdata, "\n")) !== false)
					{
						$data = substr($client->readdata, 0, $pos);
						$client->readdata = substr($client->readdata, $pos + 1);

						$data = @json_decode($data, true);
						if (is_array($data) && isset($data["action"]))
						{
							if ($data["action"] === "stats")
							{
								$result2 = array(
									"success" => true,
									"received" => $client->recvsize,
									"sent" => $client->sendsize
								);
							}
							else if ($data["action"] === "latency")
							{
								$result2 = array(
									"success" => true,
									"ts" => microtime(true)
								);
							}
							else if ($data["action"] === "download")
							{
								if (!isset($data["secs"]))  $result2 = array("success" => false, "error" => "Missing the number of seconds (secs).", "errorcode" => "missing_secs");
								else if ($data["secs"] < 1)  $result2 = array("success" => false, "error" => "Invalid number of seconds (secs).", "errorcode" => "invalid_secs");
								else
								{
									$ts = microtime(true) + (double)$data["secs"];
									$client->appdata["download"] = true;
									$client->appdata["downloaduntil"] = $ts;

									$result2 = array(
										"success" => true,
										"ts" => $ts
									);
								}
							}
							else if ($data["action"] === "upload")
							{
								$client->appdata["upload"] = true;

								$result2 = array(
									"success" => true
								);
							}
							else
							{
								$result2 = array(
									"success" => false,
									"error" => "Unknown 'action'.",
									"errorcode" => "unknown_action"
								);
							}
						}
						else
						{
							$result2 = array(
								"success" => false,
								"error" => "Invalid request.",
								"errorcode" => "invalid_request"
							);
						}

						$client->writedata .= json_encode($result2, JSON_UNESCAPED_SLASHES) . "\n";
					}
				}
			}

			// Do something with removed clients.
			foreach ($result["removed"] as $id => $result2)
			{
				if (isset($result2["client"]->appdata))
				{
					echo "Client " . $id . " disconnected.\n";

//				echo "Client " . $id . " disconnected.  " . $result2["client"]->recvsize . " bytes received, " . $result2["client"]->sendsize . " bytes sent.  Disconnect reason:\n";
//				echo json_encode($result2["result"], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
//				echo "\n";
				}
			}
		}

		// Check the status of the two service file options.
		if ($lastservicecheck <= time() - 3)
		{
			if (file_exists($stopfilename))
			{
				// Initialize termination.
				echo "Stop requested.\n";

				$running = false;
			}
			else if (file_exists($reloadfilename))
			{
				// Reload configuration and then remove reload file.
				echo "Reload config requested.\n";

				@unlink($reloadfilename);
				$running = false;
			}

			$lastservicecheck = time();
		}
	} while ($running);
?>
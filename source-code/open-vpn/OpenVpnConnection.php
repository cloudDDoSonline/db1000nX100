<?php

class OpenVpnConnection
{
    const VPN_CONNECT_TIMEOUT = 90;

    private $connectionStartedAt,
            $openVpnConfig,
            $envFile,
            $upEnv,
            $vpnProcess,
            $vpnIndex,
            $applicationObject,
            $vpnProcessPGid,
            $pipes,
            $log,
            $instantLog,
            $vpnClientIp,
            $vpnNetmask,
            $vpnNetwork,
            $vpnGatewayIp,
            $vpnDnsServers,
            $vpnPublicIp,
            $netnsName,
            $netInterface,
            $resolveFileDir,
            $resolveFilePath,
            $wasConnected = false,
            $connectionFailed = false,
            $credentialsFileTrimmed,

                                                                  $test;
    public function __construct($vpnIndex, $openVpnConfig)
    {
        $this->connectionStartedAt = time();
        $this->vpnIndex = $vpnIndex;
        $this->netnsName = 'netc' . $this->vpnIndex;
        $this->netInterface = 'tun' . $this->vpnIndex;
        _shell_exec("ip netns delete {$this->netnsName}");
        _shell_exec("ip link  delete {$this->netInterface}");
        $this->openVpnConfig = $openVpnConfig;
        $this->openVpnConfig->logUse();

        $this->log('Connecting VPN' . $this->vpnIndex . ' "' . $this->getTitle() . '"');

        $vpnCommand  = 'sleep 1 ;   cd "' . mbDirname($this->openVpnConfig->getOvpnFile()) . '" ;   nice -n 5   '
                     . '/usr/sbin/openvpn  --config "' . $this->openVpnConfig->getOvpnFile() . '"  --ifconfig-noexec  --route-noexec  '
                     . '--script-security 2  --route-up "' . static::$UP_SCRIPT . '"  --dev-type tun --dev ' . $this->netInterface . '  '
                     . $this->getCredentialsArgs() . '  '
                     . '2>&1';

        $this->log($vpnCommand);
        $descriptorSpec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "a")   // stderr
        );
        $this->vpnProcess = proc_open($vpnCommand, $descriptorSpec, $this->pipes);
        $this->vpnProcessPGid = procChangePGid($this->vpnProcess, $log);
        $this->log($log);
        if ($this->vpnProcessPGid === false) {
            $this->terminate(true);
            $this->connectionFailed = true;
            return -1;
        }
        stream_set_blocking($this->pipes[1], false);
    }

    public function processConnection()
    {
        global $TEMP_DIR;

        if ($this->connectionFailed) {
            return -1;
        }

        if ($this->wasConnected) {
            return true;
        }

        $stdOutLines = streamReadLines($this->pipes[1], 0.1);
        if ($stdOutLines) {
            $this->log($stdOutLines, true);
        }

        if ($this->isAlive() !== true) {
            $this->connectionFailed = true;
            $this->terminate(true);
            return -1;
        }

        if (strpos($stdOutLines,'SIGTERM') !== false) {
            $this->connectionFailed = true;
            $this->terminate(true);
            return -1;
        }

        if (strpos($this->log, 'Initialization Sequence Completed') !== false) {

            $this->envFile = static::getEnvFilePath($this->netInterface);
            $envJson = @file_get_contents($this->envFile);
            $this->upEnv = json_decode($envJson, true);

            $this->vpnClientIp = $this->upEnv['ifconfig_local'] ?? '';
            $this->vpnGatewayIp = $this->upEnv['route_vpn_gateway'] ?? '';
            $this->vpnNetmask = $this->upEnv['ifconfig_netmask'] ?? '255.255.255.255';
            $this->vpnNetwork = long2ip(ip2long($this->vpnGatewayIp) & ip2long($this->vpnNetmask));


            $this->vpnDnsServers = [];
            $dnsRegExp = <<<PhpRegExp
                             #dhcp-option\s+DNS\s+([\d\.]+)#  
                             PhpRegExp;
            $i = 1;
            while ($foreignOption = $this->upEnv['foreign_option_' . $i] ?? false) {
                if (preg_match(trim($dnsRegExp), $foreignOption, $matches) === 1) {
                    $this->vpnDnsServers[] = trim($matches[1]);
                }
                $i++;
            }

            $this->log("\nnetInterface " . $this->netInterface);
            $this->log('vpnClientIp ' . $this->vpnClientIp);
            $this->log('vpnGatewayIp ' . $this->vpnGatewayIp);
            $this->log('vpnNetmask /' . $this->vpnNetmask);
            $this->log('vpnNetwork ' . $this->vpnNetwork);
            $this->log('vpnDnsServers ' . implode(', ', $this->vpnDnsServers));
            $this->log("netnsName " . $this->netnsName . "\n");

            if (!(
                $this->netInterface
                &&  $this->vpnClientIp
                &&  $this->vpnNetmask
                &&  $this->vpnGatewayIp
                &&  $this->vpnDnsServers
                &&  $this->vpnNetwork
            )) {
                $this->log("Failed to get VPN config");
                $this->connectionFailed = true;
                $this->terminate(true);
                return -1;
            }

            // https://developers.redhat.com/blog/2018/10/22/introduction-to-linux-interfaces-for-virtual-networking#ipvlan
            $commands = [
                "ip netns add {$this->netnsName}",
                "ip link set dev {$this->netInterface} up netns {$this->netnsName}",
                "ip netns exec {$this->netnsName}  ip -4 addr add {$this->vpnClientIp}/32 dev {$this->netInterface}",
                "ip netns exec {$this->netnsName}  ip route add {$this->vpnNetwork}/{$this->vpnNetmask} dev {$this->netInterface}",
                "ip netns exec {$this->netnsName}  ip route add default dev {$this->netInterface} via {$this->vpnGatewayIp}",
                "ip netns exec {$this->netnsName}  ip addr show",
                "ip netns exec {$this->netnsName}  ip route show"
            ];

            foreach ($commands as $command) {
                $r = _shell_exec($command);
                $this->log($r, !strlen($r));
            }

            //------------

            $this->resolveFileDir = "/etc/netns/{$this->netnsName}";
            $this->resolveFilePath = $this->resolveFileDir . "/resolv.conf";
            if (! is_dir($this->resolveFileDir)) {
                mkdir($this->resolveFileDir, 0775, true);
            }

            $this->vpnDnsServers[] = '8.8.8.8';
            $this->vpnDnsServers = array_unique($this->vpnDnsServers);
            $nameServersList  = array_map(
                function ($ip) {
                    return "nameserver $ip";
                },
                $this->vpnDnsServers
            );
            $nameServersListStr = implode("\n", $nameServersList);
            file_put_contents($this->resolveFilePath, $nameServersListStr);

            $this->log(_shell_exec("ip netns exec {$this->netnsName}  cat /etc/resolv.conf") . "\n");

            //------------

            $this->detectPublicIp();
            if ($this->vpnPublicIp) {
                $this->log("Detected VPN public IP " . $this->vpnPublicIp);
            } else {
                $this->log(Term::red . "Can't detected VPN public IP" . Term::clear);
            }


            $pingStatus = $this->checkPing();
            if ($pingStatus) {
                $this->log("VPN tunnel Ping OK");
            } else {
                $this->log(Term::red . "VPN tunnel Ping failed!" . Term::clear);
            }

            ResourcesConsumption::startTaskTimeTracking('httpPing');
            $googleHtml = _shell_exec("ip netns exec {$this->netnsName}   curl  --silent  --max-time 10  http://google.com");
            ResourcesConsumption::stopTaskTimeTracking('httpPing');

            $httpCheckStatus = (boolean) strlen(trim($googleHtml));
            if (! $httpCheckStatus) {
                $this->log(Term::red . "Http connection test failed!" . Term::clear);
            }

            if (!$pingStatus  &&  !$httpCheckStatus) {
                $this->log(Term::red . "Can't send any traffic through this VPN connection". Term::clear);
                $this->connectionFailed = true;
                $this->terminate(true);
                return -1;
            }

            $this->wasConnected = true;
            $this->openVpnConfig->logConnectionSuccess($this->vpnPublicIp);
            return true;
        }

        // Check timeout
        $timeElapsed = time() - $this->connectionStartedAt;
        if ($timeElapsed > static::VPN_CONNECT_TIMEOUT) {
            $this->log("VPN Timeout");
            $this->terminate(true);
            return -1;
        }

        return false;
    }

    private function log($message, $noLineEnd = false)
    {
        $message .= $noLineEnd  ?  '' : "\n";
        $this->log .= $message;
        if ($this->instantLog) {
            echo $message;
        }
    }

    public function clearLog()
    {
        $this->log = '';
    }

    public function getLog()
    {
        return mbRTrim($this->log);
    }

    public function getOpenVpnConfig() : OpenVpnConfig
    {
        return $this->openVpnConfig;
    }

    public function getIndex() : int
    {
        return $this->vpnIndex;
    }

    public function getTitle($singleLine = true) : string
    {
        return $this->openVpnConfig->getProvider()->getName() . ($singleLine ? ' ~ ' : "\n") . $this->openVpnConfig->getOvpnFileSubPath();
    }

    public function getNetnsName()
    {
        return $this->netnsName;
    }

    public function getVpnPublicIp()
    {
        return $this->vpnPublicIp;
    }

    public function setApplicationObject($applicationObject)
    {
        $this->applicationObject = $applicationObject;
    }

    public function getApplicationObject()
    {
        return $this->applicationObject;
    }

    public function terminate($hasError = false)
    {
        $this->stopBandwidthMonitor();

        if ($this->vpnProcessPGid) {
            $this->log("OpenVpnConnection SIGTERM PGID -{$this->vpnProcessPGid}");
            @posix_kill(0 - $this->vpnProcessPGid, SIGTERM);
        }

        @proc_terminate($this->vpnProcess);
        if ($this->netnsName) {
            _shell_exec("ip netns delete {$this->netnsName}");
        }

        if ($hasError) {
            $this->openVpnConfig->logConnectionFail();
        }
        OpenVpnProvider::releaseOpenVpnConfig($this->openVpnConfig);

        @unlink($this->resolveFilePath);
        @rmdir($this->resolveFileDir);
        @unlink($this->credentialsFileTrimmed);
        @unlink($this->envFile);
        
    }

    public function isAlive()
    {
        return isProcAlive($this->vpnProcess);
    }

    public function checkPing()
    {
        ResourcesConsumption::startTaskTimeTracking('ping');
        $r = shell_exec("ip netns exec {$this->netnsName} ping  -c 1  -w 10  8.8.8.8   2>&1");
        ResourcesConsumption::stopTaskTimeTracking('ping');
        return mb_strpos($r, 'bytes from 8.8.8.8') !== false;
    }

    public function detectPublicIp()
    {
        ResourcesConsumption::startTaskTimeTracking('httpPing');

        $this->vpnPublicIp = trim(_shell_exec("ip netns exec {$this->netnsName}   curl  --silent  --max-time 10   http://api64.ipify.org/"));
        if ($this->vpnPublicIp  &&  filter_var($this->vpnPublicIp, FILTER_VALIDATE_IP)) {
            //MainLog::log('IP from ipify.org');
            goto retu;
        }

        $this->vpnPublicIp = trim(_shell_exec("ip netns exec {$this->netnsName}   curl  --silent  --max-time 10   http://ipecho.net/plain"));
        if ($this->vpnPublicIp  &&  filter_var($this->vpnPublicIp, FILTER_VALIDATE_IP)) {
            //MainLog::log('IP from ipecho.net');
            goto retu;
        }

        //MainLog::log('IP from env');
        $this->vpnPublicIp = $this->upEnv['trusted_ip']  ??  '';

        retu:
        ResourcesConsumption::stopTaskTimeTracking('httpPing');
    }

    private function getCredentialsArgs()
    {
        global $TEMP_DIR;

        $ret = '';
        $credentialsFile = $this->openVpnConfig->getCredentialsFile();
        $this->credentialsFileTrimmed = $TEMP_DIR . '/credentials-trimmed-' . $this->netInterface . '.txt';

        if (file_exists($credentialsFile)) {
            $credentialsFileContent = mbTrim(file_get_contents($credentialsFile));
            $credentialsFileLines = mbSplitLines($credentialsFileContent);

            $login = mbTrim($credentialsFileLines[0] ?? '');
            $password = mbTrim($credentialsFileLines[1] ?? '');
            if (!($login  &&  $password)) {
                _die("Credential file \"$credentialsFile\" has wrong content. It should contain two lines.\n"
                   . "First line - login, second line - password");
            }

            $trimmedContent = $login . "\n" . $password;
            file_put_contents_secure($this->credentialsFileTrimmed, $trimmedContent);
            $ret = "--auth-user-pass \"{$this->credentialsFileTrimmed}\"";
        }

        return $ret;
    }

    public function calculateNetworkTrafficStat()
    {
        $stats = calculateNetworkTrafficStat($this->netInterface, $this->netnsName);
        if ($stats) {
            static::$devicesReceived[$this->netInterface]    = $stats->received;
            static::$devicesTransmitted[$this->netInterface] = $stats->transmitted;
            return $stats;
        } else {
            $ret = new stdClass();
            $ret->received    = 0;
            $ret->transmitted = 0;
        }

        return $ret;
    }

    public function startBandwidthMonitor()
    {
        $bandwidthMonitorData = new stdClass();
        $bandwidthMonitorData->startedAt = time();
        $trafficStat = $this->calculateNetworkTrafficStat();
        $bandwidthMonitorData->onStartReceived    = $trafficStat->received;
        $bandwidthMonitorData->onStartTransmitted = $trafficStat->transmitted;

        static::$bandwidthMonitorData[$this->netInterface] = $bandwidthMonitorData;
    }

    private function stopBandwidthMonitor()
    {
        $bandwidthMonitorData = static::$bandwidthMonitorData[$this->netInterface]  ??  null;
        if (!$bandwidthMonitorData) {
            return;
        }

        $bandwidthMonitorData->stoppedAt = time();
        $trafficStat = $this->calculateNetworkTrafficStat();
        $bandwidthMonitorData->onStopReceived    = $trafficStat->received;
        $bandwidthMonitorData->onStopTransmitted = $trafficStat->transmitted;

        static::$bandwidthMonitorData[$this->netInterface] = $bandwidthMonitorData;
    }

    public function getScoreBlock()
    {
        $efficiencyLevel = $this->applicationObject->getEfficiencyLevel();
        $trafficStat = $this->calculateNetworkTrafficStat();
        $score = (int) round($efficiencyLevel * roundLarge($trafficStat->received / 1024 / 1024));
        if ($score) {
            $this->openVpnConfig->setCurrentSessionScorePoints($score);
        }

        $ret = new stdClass();
        $ret->efficiencyLevel    = $efficiencyLevel;
        $ret->trafficReceived    = $trafficStat->received;
        $ret->trafficTransmitted = $trafficStat->transmitted;
        $ret->score = $score;

        return $ret;
    }

    // ----------------------  Static part of the class ----------------------

    private static string $UP_SCRIPT;

    public static int   $previousSessionsTransmitted,
                        $previousSessionsReceived;
    public static array $devicesTransmitted,
                        $devicesReceived,
                        $bandwidthMonitorData;

    public static function constructStatic()
    {
        static::$UP_SCRIPT = __DIR__ . '/on-open-vpn-up.cli.php';

        static::$previousSessionsReceived = 0;
        static::$previousSessionsTransmitted = 0;
        static::$devicesReceived = [];
        static::$devicesTransmitted = [];
        static::$bandwidthMonitorData = [];
    }

    public static function newIteration()
    {
        static::$previousSessionsReceived    += array_sum(static::$devicesReceived);
        static::$previousSessionsTransmitted += array_sum(static::$devicesTransmitted);
        static::$devicesReceived    = [];
        static::$devicesTransmitted = [];
        static::$bandwidthMonitorData = [];
    }

    public static function getEnvFilePath($netInterface)
    {
        global $TEMP_DIR;
        return $TEMP_DIR . "/open-vpn-env-{$netInterface}.txt";
    }

    public static function getBandwidthMonitorResults()  /* bytes per second */
    {
        $averageBandwidthUsage = 0;
        foreach (static::$bandwidthMonitorData as $itemData) {
            if (! $itemData->stoppedAt) {
                continue;
            }

            $periodReceived    = $itemData->onStopReceived    - $itemData->onStartReceived;
            $periodTransmitted = $itemData->onStopTransmitted - $itemData->onStartTransmitted;
            $periodDuration    = $itemData->stoppedAt         - $itemData->startedAt;

            $averageBandwidthUsage += intdiv($periodReceived + $periodTransmitted, $periodDuration);
        }

        return $averageBandwidthUsage;
    }
}

OpenVpnConnection::constructStatic();
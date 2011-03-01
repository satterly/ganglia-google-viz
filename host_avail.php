<?php

    # Define constants
    $API_VERSION = 0.6;
    $GANGLIA_IP = "127.0.0.1";
    $GANGLIA_PORT = 8652;
    $GANGLIA_REQUEST = "/?filter=summary\n";
    $TIMEOUT = 3.0;

    # Google Visualization API request/response (tqx) parameters
    $reqId = 0;
    $status = 'ok';
    $reqSig = null;
    $requestHandler = "google.visualization.Query.setResponse";

    # Get 'tqx' parameters
    $tqx = explode(";", $_REQUEST['tqx']);
    foreach ($tqx as $k => $p) {
        list($key, $value) = explode(":", $p);
        $params[$key] = $value;
    }
   
    # Set parameters if included in Request 
    if (isset($params['reqId'])) $reqId = $params['reqId'];
    if (isset($params['requestHandler'])) $requestHandler = $params['requestHandler'];
    if (isset($params['sig'])) $reqSig = $params['sig'];

    # Read Gmetad XML
    $fp = fsockopen($GANGLIA_IP, $GANGLIA_PORT, $errno, $errstr, $TIMEOUT);
    if (!$fp) {
        $status = 'error';
        $reason[] = 'internal_error';
    } else {
        $rc = fputs($fp, "$GANGLIA_REQUEST");
        if (!$rc) {
            $status = 'error';
            $reason[] = 'invalid_request';
        }
    }

    if ($status != 'ok') {
        $response = "$requestHandler({version:'$API_VERSION',reqId:'$reqId',status:'$status',errors:" .
                    json_encode($reason) . "});\n";
        header("Content-Type: text/javascript; charset=utf-8");
        echo $response;
        exit;
    }

    while(!feof($fp)) {
        $data = fread($fp, 16384);
        if (preg_match("@<GRID NAME=\"([a-zA-Z0-9- ]+)\" AUTHORITY@", $data, $match)) $grid = $match[1];
        if ($grid && preg_match("@<HOSTS UP=\"(\d+)\" DOWN=\"(\d+)\"@", $data, $match)) {
            $hosts_up = $match[1]; $hosts_down = $match[2];
            $row = array(); $r = array();
            $cell["v"] = $grid; $r[] = $cell;
            $cell["v"] = (float)$hosts_up; $r[] = $cell;
            $cell["v"] = (float)$hosts_down; $r[] = $cell;
            $row["c"] = $r; 
            $rows[] = $row;
            $grid = null;
        }
    }
    fclose($fp);

    # Define data source column headings
    $col["id"] = "grid"; $col["label"] = "Grid"; $col["type"] = "string"; $cols[] = $col;
    $col["id"] = "hosts_up"; $col["label"] = "Hosts Up"; $col["type"] = "number"; $cols[] = $col;
    $col["id"] = "hosts_down"; $col["label"] = "Hosts Down"; $col["type"] = "number"; $cols[] = $col;

    # Calculate if 'sig' has changed
    $sig = md5(json_encode($cols) . json_encode($rows));
    if ($reqSig == $sig) {
        $status = 'error';
        $reason[] = 'not_modified';
        $response = "$requestHandler({version:'$API_VERSION',reqId:'$reqId',status:'$status',errors:" .
                    json_encode($reason) . ",sig:'$sig'" . "});\n";
    } else {
        $response = "$requestHandler({version:'$API_VERSION',reqId:'$reqId',status:'$status',sig:'$sig'," .
                    "table:{cols:" . json_encode($cols) . ",rows:" . json_encode($rows) . "}});\n";
    }

    header("Content-Type: text/javascript; charset=utf-8");
    echo $response;

    exit;
?>

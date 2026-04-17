<?php
// server.php - WebSocket + HTTP API Server for WebRTC Signaling
require __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

// ------------------ ग्लोबल स्टोरेज ------------------
$rooms = [];                // roomId => ['monitor' => connection, 'viewers' => [connections]]
$connections = [];          // connectionId => connection
$connectionToRoom = [];     // connectionId => roomId
$connectionRole = [];       // connectionId => 'monitor'/'viewer'

// ------------------ WebSocket सर्वर ------------------
$ws_worker = new Worker("websocket://0.0.0.0:8080");
$ws_worker->count = 1;

$ws_worker->onConnect = function(TcpConnection $connection) use (&$connections) {
    $connections[$connection->id] = $connection;
    echo "New WebSocket connection: {$connection->id}\n";
};

$ws_worker->onMessage = function(TcpConnection $connection, $data) 
    use (&$rooms, &$connections, &$connectionToRoom, &$connectionRole) {
    
    $msg = json_decode($data, true);
    if (!$msg) return;
    
    $type = $msg['type'] ?? '';
    $roomId = $msg['room'] ?? '';
    
    switch ($type) {
        // रूम जॉइन करने का रिक्वेस्ट
        case 'join':
            $role = $msg['role'] ?? 'viewer'; // monitor या viewer
            $connectionToRoom[$connection->id] = $roomId;
            $connectionRole[$connection->id] = $role;
            
            if (!isset($rooms[$roomId])) {
                $rooms[$roomId] = ['monitor' => null, 'viewers' => []];
            }
            
            if ($role === 'monitor') {
                $rooms[$roomId]['monitor'] = $connection;
                echo "Monitor joined room: $roomId\n";
            } else {
                $rooms[$roomId]['viewers'][$connection->id] = $connection;
                echo "Viewer joined room: $roomId\n";
                // अगर मॉनिटर पहले से मौजूद है तो उसे ऑफर भेजने के लिए ट्रिगर करें
                if ($rooms[$roomId]['monitor']) {
                    $rooms[$roomId]['monitor']->send(json_encode([
                        'type' => 'create_offer',
                        'room' => $roomId
                    ]));
                }
            }
            break;
            
        // WebRTC सिग्नलिंग मैसेज
        case 'offer':
        case 'answer':
        case 'candidate':
            $targetRoom = $roomId;
            if (!isset($rooms[$targetRoom])) break;
            
            $senderRole = $connectionRole[$connection->id] ?? '';
            if ($senderRole === 'monitor') {
                // मॉनिटर से आया है, सभी viewers को भेजो
                foreach ($rooms[$targetRoom]['viewers'] as $viewerConn) {
                    $viewerConn->send(json_encode($msg));
                }
            } else {
                // viewer से आया है, मॉनिटर को भेजो
                if ($rooms[$targetRoom]['monitor']) {
                    $rooms[$targetRoom]['monitor']->send(json_encode($msg));
                }
            }
            break;
            
        // कैमरा स्विच कमांड
        case 'command':
            $command = $msg['command'] ?? '';
            $targetRoom = $roomId;
            if ($command === 'switch_camera' && isset($rooms[$targetRoom]['monitor'])) {
                $rooms[$targetRoom]['monitor']->send(json_encode([
                    'command' => 'switch_camera'
                ]));
            }
            break;
    }
};

$ws_worker->onClose = function(TcpConnection $connection) 
    use (&$rooms, &$connections, &$connectionToRoom, &$connectionRole) {
    
    $roomId = $connectionToRoom[$connection->id] ?? null;
    $role = $connectionRole[$connection->id] ?? null;
    
    if ($roomId && isset($rooms[$roomId])) {
        if ($role === 'monitor') {
            $rooms[$roomId]['monitor'] = null;
            // सभी viewers को बताएं कि मॉनिटर डिस्कनेक्ट हो गया
            foreach ($rooms[$roomId]['viewers'] as $viewerConn) {
                $viewerConn->send(json_encode(['type' => 'monitor_disconnected']));
            }
        } else {
            unset($rooms[$roomId]['viewers'][$connection->id]);
        }
        // अगर रूम खाली है तो डिलीट करें
        if (empty($rooms[$roomId]['monitor']) && empty($rooms[$roomId]['viewers'])) {
            unset($rooms[$roomId]);
        }
    }
    
    unset($connections[$connection->id]);
    unset($connectionToRoom[$connection->id]);
    unset($connectionRole[$connection->id]);
    echo "Connection {$connection->id} closed\n";
};

// ------------------ HTTP API सर्वर (पोर्ट 80) ------------------
$http_worker = new Worker("http://0.0.0.0:80");
$http_worker->count = 1;

$http_worker->onMessage = function(TcpConnection $connection, Request $request) use (&$rooms) {
    $path = $request->path();
    $method = $request->method();
    
    // CORS headers
    $headers = [
        'Content-Type' => 'application/json',
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type'
    ];
    
    if ($method === 'OPTIONS') {
        $connection->send(new Response(200, $headers, ''));
        return;
    }
    
    // API एंडपॉइंट्स
    if ($path === '/api/status') {
        $connection->send(new Response(200, $headers, json_encode([
            'status' => 'online',
            'rooms' => count($rooms),
            'connections' => count($connections)
        ])));
        
    } elseif ($path === '/api/rooms') {
        $roomList = [];
        foreach ($rooms as $roomId => $data) {
            $roomList[] = [
                'roomId' => $roomId,
                'hasMonitor' => !is_null($data['monitor']),
                'viewerCount' => count($data['viewers'])
            ];
        }
        $connection->send(new Response(200, $headers, json_encode($roomList)));
        
    } elseif (preg_match('#^/api/room/([^/]+)$#', $path, $matches)) {
        $roomId = $matches[1];
        if (isset($rooms[$roomId])) {
            $connection->send(new Response(200, $headers, json_encode([
                'roomId' => $roomId,
                'hasMonitor' => !is_null($rooms[$roomId]['monitor']),
                'viewerCount' => count($rooms[$roomId]['viewers'])
            ])));
        } else {
            $connection->send(new Response(404, $headers, json_encode(['error' => 'Room not found'])));
        }
        
    } else {
        $connection->send(new Response(404, $headers, json_encode(['error' => 'Not found'])));
    }
};

// दोनों वर्कर शुरू करें
Worker::runAll();
?>
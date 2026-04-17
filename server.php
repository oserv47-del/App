<?php
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

require 'vendor/autoload.php';

class SignalingServer implements MessageComponentInterface {
    protected $clients; // सभी कनेक्टेड डिवाइसेस को स्टोर करने के लिए

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        echo "Signaling Server Started!\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        echo "Received: $msg\n";
        $data = json_decode($msg, true);
        if (!$data) return;

        // कमांड हैंडलिंग (जैसे कैमरा स्विच)
        if (isset($data['command'])) {
            $this->handleCommand($from, $data);
            return;
        }

        // सिग्नलिंग मैसेज हैंडलिंग (offer, answer, candidate)
        if (isset($data['to'])) {
            $this->handleSignaling($from, $data);
            return;
        }
    }

    // सिग्नलिंग डेटा को सही क्लाइंट तक भेजें
    private function handleSignaling($from, $data) {
        $targetResourceId = $data['to'];
        foreach ($this->clients as $client) {
            if ($client->resourceId == $targetResourceId) {
                // सुनिश्चित करें कि भेजने वाले की जानकारी भी साथ जाए
                $data['from'] = $from->resourceId;
                $client->send(json_encode($data));
                break;
            }
        }
    }

    // ब्राउज़र से आए कमांड (जैसे 'switch_camera') को मॉनिटर ऐप तक भेजें
    private function handleCommand($from, $data) {
        $targetResourceId = $data['target']; // जिस मॉनिटर को कमांड भेजनी है
        foreach ($this->clients as $client) {
            if ($client->resourceId == $targetResourceId) {
                $client->send(json_encode($data));
                break;
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }
}

// Render.com असाइन किए गए पोर्ट का उपयोग करें
$port = getenv('PORT') ?: 8080;
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new SignalingServer()
        )
    ),
    $port
);

echo "Server running on port $port...\n";
$server->run();
?>